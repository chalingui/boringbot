<?php
declare(strict_types=1);

namespace BoringBot\Bot;

use BoringBot\DB\Database;
use BoringBot\Exchange\BybitClient;
use BoringBot\Utils\Logger;
use DateInterval;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class PurchaseManager
{
    private const STATUS_BUYING = 'BUYING';
    private const STATUS_HOLDING = 'HOLDING';
    private const STATUS_OPEN = 'OPEN';
    private const STATUS_SOLD = 'SOLD';
    private const STATUS_NEEDS_FUNDS = 'NEEDS_FUNDS';

    public function __construct(
        private readonly Database $db,
        private readonly BybitClient $bybit,
        private readonly ?Notifier $notifier,
        private readonly Logger $logger,
        private readonly string $symbolTrade,
        private readonly float $dcaAmountUsdt,
        private readonly int $dcaIntervalDays,
        private readonly int $dcaOffsetHours,
        private readonly float $sellMarkupPct,
        private readonly float $sellQtyBuffer,
        private readonly int $noFundsLeadHours,
        private readonly bool $transferEnabled,
        private readonly string $transferFromAccount,
        private readonly string $transferPrincipalToAccount,
        private readonly string $transferProfitToAccount,
        private readonly bool $dryRun,
    ) {
    }

    public function tick(): void
    {
        $this->migrateLegacyStatuses();
        $this->ensureBalanceRows();
        $this->syncBuyingPurchases();
        $this->syncHoldingPurchases();
        $this->syncOpenSells();
        $this->placeNewPurchaseIfDue();
    }

    private function migrateLegacyStatuses(): void
    {
        // Backward-compat: older versions used SOLD_PENDING_CONVERT when profit conversion to USDC failed.
        // Conversion was removed; keep DB clean by treating those as SOLD (profit remains in USDT).
        $this->db->exec('UPDATE purchases SET status = :to WHERE status = :from', [
            ':to' => self::STATUS_SOLD,
            ':from' => 'SOLD_PENDING_CONVERT',
        ]);
    }

    private function ensureBalanceRows(): void
    {
        foreach (['USDT', 'ETH'] as $asset) {
            $this->db->exec('INSERT OR IGNORE INTO balances(asset, amount) VALUES(:a, 0)', [':a' => $asset]);
        }
    }

    private function syncBuyingPurchases(): void
    {
        $rows = $this->db->fetchAll('SELECT * FROM purchases WHERE status = :s ORDER BY id ASC', [
            ':s' => self::STATUS_BUYING,
        ]);

        foreach ($rows as $p) {
            if (($p['buy_order_id'] ?? '') === '') {
                $this->logger->warn('BUYING purchase without buy_order_id', ['purchase_id' => $p['id']]);
                continue;
            }

            if ($this->dryRun) {
                continue;
            }

            $order = $this->bybit->getOrder($this->symbolTrade, (string)$p['buy_order_id']);
            if (!is_array($order)) {
                continue;
            }

            $status = (string)($order['orderStatus'] ?? '');
            if ($status !== 'Filled') {
                continue;
            }

            $qty = isset($order['cumExecQty']) ? (float)$order['cumExecQty'] : 0.0;
            $value = isset($order['cumExecValue']) ? (float)$order['cumExecValue'] : 0.0;
            $avgPrice = isset($order['avgPrice']) ? (float)$order['avgPrice'] : 0.0;
            if ($avgPrice <= 0 && $qty > 0) {
                $avgPrice = $value / $qty;
            }
            if ($qty <= 0 || $avgPrice <= 0) {
                $this->logger->warn('Filled buy order missing qty/price', [
                    'purchase_id' => $p['id'],
                    'order' => $order,
                ]);
                continue;
            }

            // Some accounts pay spot fees in base asset (e.g., ETH). If so, the sellable qty is net of fees.
            $feeCurrency = (string)($order['feeCurrency'] ?? '');
            $fee = isset($order['cumExecFee']) ? (float)$order['cumExecFee'] : 0.0;
            $baseAsset = $this->baseAssetFromSymbol($this->symbolTrade);
            $netQty = $qty;
            if ($fee > 0 && $feeCurrency !== '' && $baseAsset !== '' && $feeCurrency === $baseAsset) {
                $netQty = max(0.0, $qty - $fee);
            }

            if ($baseAsset !== '') {
                $balanceInfo = $this->bybit->walletBalanceInfo($baseAsset);
                $this->logger->info('Base asset balance snapshot after buy fill', [
                    'purchase_id' => $p['id'],
                    'asset' => $baseAsset,
                    'available' => $this->fmtNullable($balanceInfo['available'] ?? null),
                    'available_to_transfer' => $this->fmtNullable($balanceInfo['availableToTransfer'] ?? null),
                    'available_to_withdraw' => $this->fmtNullable($balanceInfo['availableToWithdraw'] ?? null),
                    'wallet_balance' => $this->fmtNullable($balanceInfo['walletBalance'] ?? null),
                ]);
            }

            $this->logger->info('Buy filled; placing limit sell', [
                'purchase_id' => $p['id'],
                'qty' => $this->fmt8($netQty),
                'avg_price' => $this->fmt8($avgPrice),
                'fee' => $this->fmt8($fee),
                'fee_currency' => $feeCurrency,
            ]);

            $targetPrice = $avgPrice * (1.0 + ((float)$p['sell_markup_pct'] / 100.0));
            $sellOrderId = null;
            try {
                $sellOrderId = $this->bybit->createLimitSell($this->symbolTrade, $netQty, $targetPrice);
            } catch (Throwable $e) {
                $this->logger->error('Failed to place limit sell; will retry', [
                    'purchase_id' => $p['id'],
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $this->db->begin();
                $this->db->exec(
                    'UPDATE purchases SET
                        buy_price = :bp,
                        buy_qty = :bq,
                        buy_filled_at = datetime(\'now\'),
                        sell_order_id = :so,
                        sell_price = :sp,
                        sell_qty = :sq,
                        status = :st
                     WHERE id = :id',
                    [
                        ':bp' => $avgPrice,
                        ':bq' => $netQty,
                        ':so' => $sellOrderId,
                        ':sp' => $sellOrderId === null ? null : $targetPrice,
                        ':sq' => $sellOrderId === null ? null : $netQty,
                        ':st' => $sellOrderId === null ? self::STATUS_HOLDING : self::STATUS_OPEN,
                        ':id' => $p['id'],
                    ]
                );
                $this->addBalance('ETH', $netQty);
                $this->insertEvent($sellOrderId === null ? 'BUY_FILLED_SELL_FAILED' : 'BUY_FILLED_SELL_PLACED', [
                    'purchase_id' => (int)$p['id'],
                    'buy_order_id' => (string)$p['buy_order_id'],
                    'buy_qty' => $this->fmt8($netQty),
                    'buy_price' => $this->fmt8($avgPrice),
                    'buy_fee' => $this->fmt8($fee),
                    'buy_fee_currency' => $feeCurrency,
                    'sell_order_id' => $sellOrderId,
                    'sell_price' => $this->fmt8($targetPrice),
                ]);
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            if (!$this->dryRun && $this->notifier !== null) {
                $this->notifier->bought((int)$p['id'], $this->symbolTrade, (float)$p['buy_usdt'], $netQty, $avgPrice);
            }
        }
    }

    private function syncHoldingPurchases(): void
    {
        if ($this->dryRun) {
            return;
        }

        $rows = $this->db->fetchAll('SELECT * FROM purchases WHERE status IN (:s1, :s2) ORDER BY id ASC', [
            ':s1' => self::STATUS_HOLDING,
            ':s2' => self::STATUS_NEEDS_FUNDS,
        ]);

        foreach ($rows as $p) {
            $qty = (float)($p['buy_qty'] ?? 0.0);
            $price = (float)($p['buy_price'] ?? 0.0);
            if ($qty <= 0 || $price <= 0) {
                $this->logger->warn('HOLDING purchase missing buy_qty/buy_price', ['purchase_id' => $p['id']]);
                continue;
            }

            // If fees were taken in base asset, available balance may be slightly lower than recorded qty.
            $baseAsset = $this->baseAssetFromSymbol($this->symbolTrade);
            $avail = null;
            $wallet = null;
            $transfer = null;
            $filters = $this->bybit->orderFilters($this->symbolTrade);
            $minQty = isset($filters['minOrderQty']) ? (float)$filters['minOrderQty'] : null;
            $minAmt = isset($filters['minOrderAmt']) ? (float)$filters['minOrderAmt'] : null;
            $qtyStepStr = (string)($filters['qtyStep'] ?? '');
            if ($baseAsset !== '') {
                $balanceInfo = $this->bybit->walletBalanceInfo($baseAsset);
                $avail = $balanceInfo['available'] ?? null;
                $wallet = $balanceInfo['walletBalance'] ?? null;
                try {
                    $transfer = $this->bybit->transferBalance($baseAsset);
                } catch (Throwable $e) {
                    $this->logger->warn('Transfer balance fetch failed', [
                        'purchase_id' => $p['id'],
                        'asset' => $baseAsset,
                        'error' => $e->getMessage(),
                    ]);
                }
                $this->logger->info('Base asset balance snapshot for HOLDING retry', [
                    'purchase_id' => $p['id'],
                    'asset' => $baseAsset,
                    'available' => $this->fmtNullable($avail),
                    'available_to_transfer' => $this->fmtNullable($balanceInfo['availableToTransfer'] ?? null),
                    'available_to_withdraw' => $this->fmtNullable($balanceInfo['availableToWithdraw'] ?? null),
                    'transfer_balance' => $this->fmtNullable($transfer),
                    'wallet_balance' => $this->fmtNullable($wallet),
                ]);
                if (is_float($avail) && $avail <= 0 && is_float($wallet) && $wallet > 0) {
                    $this->logger->warn('Available balance reported as zero; skipping qty adjustment', [
                        'purchase_id' => $p['id'],
                        'available' => $this->fmt8($avail),
                        'wallet_balance' => $this->fmt8($wallet),
                        'asset' => $baseAsset,
                        'note' => 'Funds may be in funding/unavailable; transfer to spot/unified to sell.',
                    ]);
                }
                if (is_float($avail) && $avail > 0 && $avail + 1e-12 < $qty) {
                    $this->logger->warn('Adjusting HOLDING qty to available balance (likely fees)', [
                        'purchase_id' => $p['id'],
                        'recorded_qty' => $this->fmt8($qty),
                        'available' => $this->fmt8($avail),
                        'wallet_balance' => is_float($wallet) ? $this->fmt8($wallet) : null,
                        'asset' => $baseAsset,
                    ]);
                    $diff = $qty - $avail;
                    $qty = $avail;
                    try {
                        $this->db->begin();
                        $this->db->exec('UPDATE purchases SET buy_qty = :q WHERE id = :id', [
                            ':q' => $qty,
                            ':id' => $p['id'],
                        ]);
                        $this->addBalance('ETH', -$diff);
                        $this->insertEvent('BUY_QTY_ADJUSTED', [
                            'purchase_id' => (int)$p['id'],
                            'diff' => $diff,
                            'new_buy_qty' => $qty,
                            'reason' => 'available_balance',
                        ]);
                        $this->db->commit();
                    } catch (Throwable $e) {
                        $this->db->rollBack();
                        throw $e;
                    }
                    if ($qty <= 0) {
                        continue;
                    }
                }
            }

            $sellQty = $qty;
            $effectiveAvail = null;
            if (is_float($transfer) && $transfer > 0) {
                $effectiveAvail = $transfer;
            } elseif (is_float($avail) && $avail > 0) {
                $effectiveAvail = $avail;
            } elseif (is_float($wallet) && $wallet > 0) {
                $effectiveAvail = $wallet;
            }
            if (is_float($effectiveAvail) && $effectiveAvail > 0 && $this->isBelowMinTradeQty($effectiveAvail, $qtyStepStr, $minQty)) {
                $this->logger->warn('Effective available balance is dust; ignoring transfer/available balance', [
                    'purchase_id' => $p['id'],
                    'effective_available' => $this->fmt8($effectiveAvail),
                    'qty_step' => $qtyStepStr,
                    'min_order_qty' => $minQty !== null ? $this->fmt8($minQty) : null,
                ]);
                $effectiveAvail = null;
                if (is_float($avail) && $avail > 0) {
                    $effectiveAvail = $avail;
                } elseif (is_float($wallet) && $wallet > 0) {
                    $effectiveAvail = $wallet;
                }
            }
            if (is_float($effectiveAvail) && $effectiveAvail >= 0) {
                $capAvail = $effectiveAvail;
                if ($this->sellQtyBuffer > 0) {
                    $capAvail = max(0.0, $capAvail - $this->sellQtyBuffer);
                }
                if ($capAvail + 1e-12 < $sellQty) {
                    $sellQty = $capAvail;
                    if ($sellQty <= 0) {
                        $this->logger->warn('HOLDING purchase has no available balance after buffer', [
                            'purchase_id' => $p['id'],
                            'available' => is_float($avail) ? $this->fmt8($avail) : null,
                            'wallet_balance' => is_float($wallet) ? $this->fmt8($wallet) : null,
                            'transfer_balance' => is_float($transfer) ? $this->fmt8($transfer) : null,
                            'buffer' => $this->fmt8($this->sellQtyBuffer),
                        ]);
                        $this->markNeedsFunds($p, 'no_available_balance', [
                            'available' => $avail,
                            'wallet_balance' => $wallet,
                            'transfer_balance' => $transfer,
                            'buffer' => $this->sellQtyBuffer,
                        ]);
                        continue;
                    }
                    $this->logger->info('Capping HOLDING sell qty to available balance', [
                        'purchase_id' => $p['id'],
                        'recorded_qty' => $this->fmt8($qty),
                        'sell_qty' => $this->fmt8($sellQty),
                        'available' => is_float($avail) ? $this->fmt8($avail) : null,
                        'wallet_balance' => is_float($wallet) ? $this->fmt8($wallet) : null,
                        'transfer_balance' => is_float($transfer) ? $this->fmt8($transfer) : null,
                        'buffer' => $this->fmt8($this->sellQtyBuffer),
                    ]);
                }
            }

            $targetPrice = $price * (1.0 + ((float)$p['sell_markup_pct'] / 100.0));
            if ($qtyStepStr !== '' && (float)$qtyStepStr > 0) {
                $sellQty = $this->floorToStep($sellQty, (float)$qtyStepStr);
            }
            if ($sellQty <= 0) {
                $this->logger->warn('HOLDING sell qty below step; skipping limit sell', [
                    'purchase_id' => $p['id'],
                    'sell_qty' => $this->fmt8($sellQty),
                    'qty_step' => $qtyStepStr,
                ]);
                $this->markNeedsFunds($p, 'sell_qty_below_step', [
                    'sell_qty' => $sellQty,
                    'qty_step' => $qtyStepStr,
                ]);
                continue;
            }
            if ($minQty !== null && $sellQty + 1e-12 < $minQty) {
                $this->logger->warn('HOLDING sell qty below minOrderQty; skipping limit sell', [
                    'purchase_id' => $p['id'],
                    'sell_qty' => $this->fmt8($sellQty),
                    'min_order_qty' => $this->fmt8($minQty),
                ]);
                $this->markNeedsFunds($p, 'sell_qty_below_min_order_qty', [
                    'sell_qty' => $sellQty,
                    'min_order_qty' => $minQty,
                ]);
                continue;
            }
            if ($minAmt !== null && ($sellQty * $targetPrice) + 1e-8 < $minAmt) {
                $this->logger->warn('HOLDING sell notional below minOrderAmt; skipping limit sell', [
                    'purchase_id' => $p['id'],
                    'sell_qty' => $this->fmt8($sellQty),
                    'sell_price' => $this->fmt8($targetPrice),
                    'min_order_amt' => $this->fmt8($minAmt),
                ]);
                $this->markNeedsFunds($p, 'sell_notional_below_min_order_amt', [
                    'sell_qty' => $sellQty,
                    'sell_price' => $targetPrice,
                    'min_order_amt' => $minAmt,
                ]);
                continue;
            }
            try {
                $sellOrderId = $this->bybit->createLimitSell($this->symbolTrade, $sellQty, $targetPrice);
            } catch (Throwable $e) {
                $baseAsset = $this->baseAssetFromSymbol($this->symbolTrade);
                $avail = null;
                $wallet = null;
                if ($baseAsset !== '') {
                    try {
                        $balanceInfo = $this->bybit->walletBalanceInfo($baseAsset);
                        $avail = $balanceInfo['available'] ?? null;
                        $wallet = $balanceInfo['walletBalance'] ?? null;
                    } catch (Throwable) {
                        $avail = null;
                    }
                }
                $this->logger->error('Retry sell placement failed', [
                    'purchase_id' => $p['id'],
                    'error' => $e->getMessage(),
                    'symbol' => $this->symbolTrade,
                    'attempt_qty' => $this->fmt8($sellQty),
                    'attempt_price' => $this->fmt8($targetPrice),
                    'base_asset' => $baseAsset,
                    'available_base' => is_float($avail) ? $this->fmt8($avail) : null,
                    'wallet_balance_base' => is_float($wallet) ? $this->fmt8($wallet) : null,
                    'recorded_buy_qty' => $this->fmt8((float)($p['buy_qty'] ?? 0.0)),
                ]);
                continue;
            }

            $this->logger->info('Limit sell placed (retry from HOLDING)', [
                'purchase_id' => (int)$p['id'],
                'symbol' => $this->symbolTrade,
                'sell_order_id' => $sellOrderId,
                'sell_qty' => $this->fmt8($sellQty),
                'sell_price' => $this->fmt8($targetPrice),
                'sell_markup_pct' => $this->fmt8((float)($p['sell_markup_pct'] ?? 0.0)),
            ]);

            try {
                $this->db->begin();
                $this->db->exec(
                    'UPDATE purchases SET
                        sell_order_id = :so,
                        sell_price = :sp,
                        sell_qty = :sq,
                        status = :st
                     WHERE id = :id',
                    [
                        ':so' => $sellOrderId,
                        ':sp' => $targetPrice,
                        ':sq' => $sellQty,
                        ':st' => self::STATUS_OPEN,
                        ':id' => $p['id'],
                    ]
                );
                $this->insertEvent('SELL_PLACED_RETRY', [
                    'purchase_id' => (int)$p['id'],
                    'sell_order_id' => $sellOrderId,
                    'sell_price' => $this->fmt8($targetPrice),
                    'sell_qty' => $this->fmt8($sellQty),
                    'symbol' => $this->symbolTrade,
                ]);
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }
    }

    private function syncOpenSells(): void
    {
        $rows = $this->db->fetchAll('SELECT * FROM purchases WHERE status = :s ORDER BY id ASC', [
            ':s' => self::STATUS_OPEN,
        ]);

        foreach ($rows as $p) {
            if (($p['sell_order_id'] ?? '') === '') {
                $this->logger->warn('OPEN purchase without sell_order_id', ['purchase_id' => $p['id']]);
                continue;
            }

            if ($this->dryRun) {
                continue;
            }

            $order = $this->bybit->getOrder($this->symbolTrade, (string)$p['sell_order_id']);
            if (!is_array($order)) {
                continue;
            }

            $status = (string)($order['orderStatus'] ?? '');
            if ($status !== 'Filled') {
                $cumExecQty = isset($order['cumExecQty']) ? (float)$order['cumExecQty'] : 0.0;
                $sellQty = (float)($p['sell_qty'] ?? 0.0);
                if ($cumExecQty > 0 && $sellQty > 0 && $cumExecQty + 1e-12 < $sellQty) {
                    $this->logger->warn('Partial sell fill detected; waiting for full fill', [
                        'purchase_id' => $p['id'],
                        'cumExecQty' => $cumExecQty,
                        'sell_qty' => $sellQty,
                        'orderStatus' => $status,
                    ]);
                }
                continue;
            }

            $sellQty = isset($order['cumExecQty']) ? (float)$order['cumExecQty'] : (float)($p['sell_qty'] ?? 0.0);
            $sellUsdt = isset($order['cumExecValue']) ? (float)$order['cumExecValue'] : 0.0;
            if ($sellQty <= 0 || $sellUsdt <= 0) {
                $this->logger->warn('Filled sell order missing qty/value', [
                    'purchase_id' => $p['id'],
                    'order' => $order,
                ]);
                continue;
            }

            $buyUsdt = (float)$p['buy_usdt'];
            $profitUsdt = $sellUsdt - $buyUsdt;
            if ($profitUsdt < 0) {
                $profitUsdt = 0.0;
            }

            $baseAsset = $this->baseAssetFromSymbol($this->symbolTrade);
            if ($baseAsset !== '') {
                $balanceInfo = $this->bybit->walletBalanceInfo($baseAsset);
                $this->logger->info('Base asset balance snapshot after sell fill', [
                    'purchase_id' => $p['id'],
                    'asset' => $baseAsset,
                    'available' => $this->fmtNullable($balanceInfo['available'] ?? null),
                    'available_to_transfer' => $this->fmtNullable($balanceInfo['availableToTransfer'] ?? null),
                    'available_to_withdraw' => $this->fmtNullable($balanceInfo['availableToWithdraw'] ?? null),
                    'wallet_balance' => $this->fmtNullable($balanceInfo['walletBalance'] ?? null),
                ]);
            }

            $this->logger->info('Sell filled; realizing principal and profit', [
                'purchase_id' => $p['id'],
                'sell_usdt' => $sellUsdt,
                'profit_usdt' => $profitUsdt,
            ]);

            $profitTransferred = false;
            $principalTransferred = false;
            if ($this->transferEnabled && !$this->dryRun) {
                if ($this->transferFromAccount === '') {
                    $this->logger->warn('Transfer enabled but from_account is empty; skipping transfers', [
                        'purchase_id' => $p['id'],
                    ]);
                } else {
                    if (
                        $this->transferProfitToAccount !== ''
                        && strcasecmp($this->transferProfitToAccount, $this->transferFromAccount) !== 0
                        && $profitUsdt > 0
                    ) {
                        try {
                            $transferId = $this->bybit->interTransfer(
                                'USDT',
                                $profitUsdt,
                                $this->transferFromAccount,
                                $this->transferProfitToAccount
                            );
                            $profitTransferred = true;
                            $this->insertEvent('TRANSFER_PROFIT', [
                                'purchase_id' => (int)$p['id'],
                                'amount' => $profitUsdt,
                                'coin' => 'USDT',
                                'from' => $this->transferFromAccount,
                                'to' => $this->transferProfitToAccount,
                                'transfer_id' => $transferId,
                            ]);
                        } catch (Throwable $e) {
                            $this->logger->error('Profit transfer failed', [
                                'purchase_id' => $p['id'],
                                'error' => $e->getMessage(),
                                'amount' => $profitUsdt,
                                'coin' => 'USDT',
                                'from' => $this->transferFromAccount,
                                'to' => $this->transferProfitToAccount,
                            ]);
                        }
                    }
                    if (
                        $this->transferPrincipalToAccount !== ''
                        && strcasecmp($this->transferPrincipalToAccount, $this->transferFromAccount) !== 0
                        && $buyUsdt > 0
                    ) {
                        try {
                            $transferId = $this->bybit->interTransfer(
                                'USDT',
                                $buyUsdt,
                                $this->transferFromAccount,
                                $this->transferPrincipalToAccount
                            );
                            $principalTransferred = true;
                            $this->insertEvent('TRANSFER_PRINCIPAL', [
                                'purchase_id' => (int)$p['id'],
                                'amount' => $buyUsdt,
                                'coin' => 'USDT',
                                'from' => $this->transferFromAccount,
                                'to' => $this->transferPrincipalToAccount,
                                'transfer_id' => $transferId,
                            ]);
                        } catch (Throwable $e) {
                            $this->logger->error('Principal transfer failed', [
                                'purchase_id' => $p['id'],
                                'error' => $e->getMessage(),
                                'amount' => $buyUsdt,
                                'coin' => 'USDT',
                                'from' => $this->transferFromAccount,
                                'to' => $this->transferPrincipalToAccount,
                            ]);
                        }
                    }
                }
            }

            try {
                $this->db->begin();
                $this->db->exec(
                    'UPDATE purchases SET
                        sell_filled_at = datetime(\'now\'),
                        sell_usdt = :su,
                        profit_usdt = :pu,
                        status = :st
                     WHERE id = :id',
                    [
                        ':su' => $sellUsdt,
                        ':pu' => $profitUsdt,
                        ':st' => self::STATUS_SOLD,
                        ':id' => $p['id'],
                    ]
                );

                $this->addBalance('ETH', -$sellQty);
                $ledgerUsdt = 0.0;
                if (!$principalTransferred) {
                    $ledgerUsdt += $buyUsdt;
                }
                if (!$profitTransferred) {
                    $ledgerUsdt += $profitUsdt;
                }
                if ($ledgerUsdt > 0) {
                    $this->addBalance('USDT', $ledgerUsdt);
                }

                $this->insertEvent('SOLD', [
                    'purchase_id' => (int)$p['id'],
                    'sell_order_id' => (string)$p['sell_order_id'],
                    'sell_usdt' => $sellUsdt,
                    'principal_usdt' => $buyUsdt,
                    'profit_usdt' => $profitUsdt,
                ]);
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            if (!$this->dryRun && $this->notifier !== null) {
                $this->notifier->sold((int)$p['id'], $sellUsdt, $profitUsdt);
            }
        }
    }

    private function placeNewPurchaseIfDue(): void
    {
        $nowUtc = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $latest = $this->db->fetchOne('SELECT created_at FROM purchases ORDER BY id DESC LIMIT 1');
        if ($latest !== null) {
            $last = new DateTimeImmutable((string)$latest['created_at'] . ' UTC');
            $dueAt = $last->add(new DateInterval('P' . $this->dcaIntervalDays . 'D'));
            if ($this->dcaOffsetHours !== 0) {
                $offset = new DateInterval('PT' . abs($this->dcaOffsetHours) . 'H');
                $dueAt = $this->dcaOffsetHours > 0 ? $dueAt->add($offset) : $dueAt->sub($offset);
            }
            if ($nowUtc < $dueAt) {
                if (
                    !$this->dryRun
                    && $this->notifier !== null
                    && $this->noFundsLeadHours > 0
                ) {
                    $secondsUntilDue = $dueAt->getTimestamp() - $nowUtc->getTimestamp();
                    $leadSeconds = $this->noFundsLeadHours * 3600;
                    if ($secondsUntilDue > 0 && $secondsUntilDue <= $leadSeconds) {
                        $usdt = $this->getBalance('USDT');
                        if ($usdt + 1e-9 < $this->dcaAmountUsdt) {
                            $this->notifier->insufficientFundsLead($this->dcaAmountUsdt, $usdt, $dueAt, $this->noFundsLeadHours);
                        }
                    }
                }
                return;
            }
        }

        $usdt = $this->getBalance('USDT');
        if ($usdt + 1e-9 < $this->dcaAmountUsdt) {
            $this->logger->info('Not enough USDT in bot balance for DCA', [
                'need' => $this->dcaAmountUsdt,
                'have' => $usdt,
            ]);
            if (!$this->dryRun && $this->notifier !== null) {
                $this->notifier->insufficientFunds($this->dcaAmountUsdt, $usdt);
            }
            return;
        }

            $this->logger->info('Creating new purchase', [
                'amount_usdt' => $this->dcaAmountUsdt,
                'symbol' => $this->symbolTrade,
                'sell_markup_pct' => $this->sellMarkupPct,
                'dry_run' => $this->dryRun,
            ]);

        if ($this->dryRun) {
            $ticker = $this->bybit->tickerLastPrice($this->symbolTrade);
            $buyPrice = $ticker ?? 0.0;
            if ($buyPrice <= 0) {
                $buyPrice = 0.0;
            }

            $buyQty = $buyPrice > 0 ? ($this->dcaAmountUsdt / $buyPrice) : 0.0;
            $targetPrice = $buyPrice > 0 ? ($buyPrice * (1.0 + $this->sellMarkupPct / 100.0)) : 0.0;

            $this->logger->info('DRY-RUN would create purchase and place orders', [
                'amount_usdt' => $this->dcaAmountUsdt,
                'symbol' => $this->symbolTrade,
                'last_price' => $ticker,
                'buy_price' => $this->fmt8($buyPrice),
                'buy_qty' => $this->fmt8($buyQty),
                'sell_target_price' => $this->fmt8($targetPrice),
                'sell_markup_pct' => $this->sellMarkupPct,
            ]);
            return;
        }

        $purchaseId = 0;
        try {
            $this->db->begin();
            $purchaseId = $this->db->insert(
                'INSERT INTO purchases(buy_usdt, status, sell_markup_pct) VALUES(:u, :s, :m)',
                [
                    ':u' => $this->dcaAmountUsdt,
                    ':s' => self::STATUS_BUYING,
                    ':m' => $this->sellMarkupPct,
                ]
            );
            $this->addBalance('USDT', -$this->dcaAmountUsdt);
            $this->insertEvent('BUY_CREATED', [
                'purchase_id' => $purchaseId,
                'buy_usdt' => $this->dcaAmountUsdt,
                'symbol' => $this->symbolTrade,
                'dry_run' => $this->dryRun,
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($purchaseId <= 0) {
            throw new RuntimeException('Failed to create purchase.');
        }

        if (!$this->dryRun && $this->notifier !== null) {
            $this->notifier->purchaseCreated($purchaseId, $this->dcaAmountUsdt, $this->symbolTrade);
        }

        try {
            $buyOrderId = $this->bybit->createMarketBuyByQuote($this->symbolTrade, $this->dcaAmountUsdt);
            $this->db->exec(
                'UPDATE purchases SET buy_order_id = :o WHERE id = :id',
                [':o' => $buyOrderId, ':id' => $purchaseId]
            );
            $this->insertEvent('BUY_ORDER_PLACED', [
                'purchase_id' => $purchaseId,
                'buy_order_id' => $buyOrderId,
                'symbol' => $this->symbolTrade,
            ]);
            $this->logger->info('Market buy placed', ['purchase_id' => $purchaseId, 'orderId' => $buyOrderId]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to place market buy; refunding ledger USDT and marking purchase ERROR', [
                'purchase_id' => $purchaseId,
                'error' => $e->getMessage(),
            ]);
            try {
                $this->db->begin();
                $this->db->exec('UPDATE purchases SET status = :st WHERE id = :id', [
                    ':st' => 'ERROR',
                    ':id' => $purchaseId,
                ]);
                $this->addBalance('USDT', $this->dcaAmountUsdt);
                $this->insertEvent('BUY_FAILED', [
                    'purchase_id' => $purchaseId,
                    'symbol' => $this->symbolTrade,
                    'error' => $e->getMessage(),
                ]);
                $this->db->commit();
            } catch (Throwable $e2) {
                $this->db->rollBack();
                throw $e2;
            }
        }
    }

    private function insertEvent(string $type, array $payload): void
    {
        $this->db->insert(
            'INSERT INTO events_log(type, payload_json) VALUES(:type, :payload)',
            [
                ':type' => $type,
                ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    private function getBalance(string $asset): float
    {
        $row = $this->db->fetchOne('SELECT amount FROM balances WHERE asset = :a', [':a' => $asset]);
        return $row === null ? 0.0 : (float)$row['amount'];
    }

    private function addBalance(string $asset, float $delta): void
    {
        // Compatible with older SQLite versions (no UPSERT syntax).
        $this->db->exec('INSERT OR IGNORE INTO balances(asset, amount) VALUES(:a, 0)', [':a' => $asset]);
        $this->db->exec('UPDATE balances SET amount = amount + :d WHERE asset = :a', [
            ':a' => $asset,
            ':d' => $delta,
        ]);
    }

    private function baseAssetFromSymbol(string $symbol): string
    {
        // This bot is designed for ETH/USDT on Bybit Spot (symbol like ETHUSDT).
        if (str_ends_with($symbol, 'USDT')) {
            return substr($symbol, 0, -4);
        }
        return '';
    }

    private function fmt8(float $n): string
    {
        // Fixed 8 decimals for logs/UI consistency.
        return number_format($n, 8, '.', '');
    }

    private function fmtNullable(?float $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return $this->fmt8($value);
    }

    private function floorToStep(float $value, float $step): float
    {
        if ($step <= 0) {
            return $value;
        }
        $factor = 1 / $step;
        return floor(($value + 1e-12) * $factor) / $factor;
    }

    private function markNeedsFunds(array $purchase, string $reason, array $context = []): void
    {
        if ((string)($purchase['status'] ?? '') === self::STATUS_NEEDS_FUNDS) {
            return;
        }
        try {
            $this->db->exec('UPDATE purchases SET status = :st WHERE id = :id', [
                ':st' => self::STATUS_NEEDS_FUNDS,
                ':id' => $purchase['id'],
            ]);
            $payload = array_merge([
                'purchase_id' => (int)$purchase['id'],
                'reason' => $reason,
            ], $context);
            $this->insertEvent('NEEDS_FUNDS', $payload);
        } catch (Throwable $e) {
            $this->logger->warn('Failed to mark purchase as NEEDS_FUNDS', [
                'purchase_id' => $purchase['id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isBelowMinTradeQty(float $qty, string $qtyStepStr, ?float $minQty): bool
    {
        $minStep = null;
        if ($qtyStepStr !== '' && (float)$qtyStepStr > 0) {
            $minStep = (float)$qtyStepStr;
        }
        $minAllowed = $minQty ?? $minStep ?? 0.0;
        if ($minStep !== null) {
            $minAllowed = max($minAllowed, $minStep);
        }
        return $minAllowed > 0 && $qty + 1e-12 < $minAllowed;
    }
}
