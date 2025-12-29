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
    private const STATUS_SOLD_PENDING_CONVERT = 'SOLD_PENDING_CONVERT';
    private const STATUS_SOLD = 'SOLD';

    public function __construct(
        private readonly Database $db,
        private readonly BybitClient $bybit,
        private readonly ProfitConverter $profitConverter,
        private readonly ?Notifier $notifier,
        private readonly Logger $logger,
        private readonly string $symbolTrade,
        private readonly float $dcaAmountUsdt,
        private readonly int $dcaIntervalDays,
        private readonly float $sellMarkupPct,
        private readonly bool $dryRun,
    ) {
    }

    public function tick(): void
    {
        $this->ensureBalanceRows();
        $this->syncBuyingPurchases();
        $this->syncHoldingPurchases();
        $this->syncOpenSells();
        $this->syncSoldPendingConvert();
        $this->placeNewPurchaseIfDue();
    }

    private function ensureBalanceRows(): void
    {
        foreach (['USDT', 'ETH', 'USDC'] as $asset) {
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
                    'buy_qty' => $netQty,
                    'buy_price' => $avgPrice,
                    'buy_fee' => $fee,
                    'buy_fee_currency' => $feeCurrency,
                    'sell_order_id' => $sellOrderId,
                    'sell_price' => $targetPrice,
                ]);
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }
    }

    private function syncHoldingPurchases(): void
    {
        if ($this->dryRun) {
            return;
        }

        $rows = $this->db->fetchAll('SELECT * FROM purchases WHERE status = :s ORDER BY id ASC', [
            ':s' => self::STATUS_HOLDING,
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
            if ($baseAsset !== '') {
                $avail = $this->bybit->walletBalance($baseAsset);
                if (is_float($avail) && $avail >= 0 && $avail + 1e-12 < $qty) {
                    $this->logger->warn('Adjusting HOLDING qty to available balance (likely fees)', [
                        'purchase_id' => $p['id'],
                        'recorded_qty' => $this->fmt8($qty),
                        'available' => $this->fmt8($avail),
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

            $targetPrice = $price * (1.0 + ((float)$p['sell_markup_pct'] / 100.0));
            try {
                $sellOrderId = $this->bybit->createLimitSell($this->symbolTrade, $qty, $targetPrice);
            } catch (Throwable $e) {
                $baseAsset = $this->baseAssetFromSymbol($this->symbolTrade);
                $avail = null;
                if ($baseAsset !== '') {
                    try {
                        $avail = $this->bybit->walletBalance($baseAsset);
                    } catch (Throwable) {
                        $avail = null;
                    }
                }
                $this->logger->error('Retry sell placement failed', [
                    'purchase_id' => $p['id'],
                    'error' => $e->getMessage(),
                    'symbol' => $this->symbolTrade,
                    'attempt_qty' => $this->fmt8($qty),
                    'attempt_price' => $this->fmt8($targetPrice),
                    'base_asset' => $baseAsset,
                    'available_base' => is_float($avail) ? $this->fmt8($avail) : null,
                    'recorded_buy_qty' => $this->fmt8((float)($p['buy_qty'] ?? 0.0)),
                ]);
                continue;
            }

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
                        ':sq' => $qty,
                        ':st' => self::STATUS_OPEN,
                        ':id' => $p['id'],
                    ]
                );
                $this->insertEvent('SELL_PLACED_RETRY', [
                    'purchase_id' => (int)$p['id'],
                    'sell_order_id' => $sellOrderId,
                    'sell_price' => $targetPrice,
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

            $this->logger->info('Sell filled; realizing principal and converting profit', [
                'purchase_id' => $p['id'],
                'sell_usdt' => $sellUsdt,
                'profit_usdt' => $profitUsdt,
            ]);

            $convOrderId = null;
            $profitUsdc = 0.0;
            $usdtSpent = 0.0;
            $convertError = null;
            try {
                [$convOrderId, $profitUsdc, $usdtSpent] = $this->profitConverter->convertUsdtToUsdc($profitUsdt);
            } catch (Throwable $e) {
                $convertError = $e->getMessage();
                $this->logger->error('Profit conversion failed; keeping profit as USDT for retry', [
                    'purchase_id' => $p['id'],
                    'error' => $convertError,
                    'profit_usdt' => $profitUsdt,
                ]);
            }

            try {
                $this->db->begin();
                $this->db->exec(
                    'UPDATE purchases SET
                        sell_filled_at = datetime(\'now\'),
                        sell_usdt = :su,
                        profit_usdt = :pu,
                        profit_usdc = :pc,
                        status = :st
                     WHERE id = :id',
                    [
                        ':su' => $sellUsdt,
                        ':pu' => $profitUsdt,
                        ':pc' => $profitUsdc,
                        ':st' => $convertError === null ? self::STATUS_SOLD : self::STATUS_SOLD_PENDING_CONVERT,
                        ':id' => $p['id'],
                    ]
                );

                $this->addBalance('ETH', -$sellQty);
                $this->addBalance('USDT', $buyUsdt + ($convertError === null ? 0.0 : $profitUsdt));
                $this->addBalance('USDC', $profitUsdc);

                $this->insertEvent($convertError === null ? 'SOLD' : 'SOLD_PROFIT_PENDING', [
                    'purchase_id' => (int)$p['id'],
                    'sell_order_id' => (string)$p['sell_order_id'],
                    'sell_usdt' => $sellUsdt,
                    'principal_usdt' => $buyUsdt,
                    'profit_usdt' => $profitUsdt,
                    'profit_convert_symbol' => $this->profitConverter->symbol(),
                    'profit_convert_order_id' => $convOrderId,
                    'profit_usdc' => $profitUsdc,
                    'profit_convert_usdt_spent' => $usdtSpent,
                    'profit_convert_error' => $convertError,
                ]);
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            if ($convertError === null && !$this->dryRun && $this->notifier !== null) {
                $this->notifier->sold((int)$p['id'], $sellUsdt, $profitUsdt, $profitUsdc);
            }
        }
    }

    private function syncSoldPendingConvert(): void
    {
        if ($this->dryRun) {
            return;
        }

        $rows = $this->db->fetchAll('SELECT * FROM purchases WHERE status = :s ORDER BY id ASC', [
            ':s' => self::STATUS_SOLD_PENDING_CONVERT,
        ]);

        foreach ($rows as $p) {
            $profitUsdt = (float)($p['profit_usdt'] ?? 0.0);
            if ($profitUsdt <= 0) {
                $this->db->exec('UPDATE purchases SET status = :st WHERE id = :id', [
                    ':st' => self::STATUS_SOLD,
                    ':id' => $p['id'],
                ]);
                continue;
            }

            $usdt = $this->getBalance('USDT');
            if ($usdt + 1e-9 < $profitUsdt) {
                $this->logger->warn('Insufficient USDT to convert pending profit (ledger)', [
                    'purchase_id' => $p['id'],
                    'need' => $profitUsdt,
                    'have' => $usdt,
                ]);
                continue;
            }

            try {
                [$convOrderId, $profitUsdc, $usdtSpent] = $this->profitConverter->convertUsdtToUsdc($profitUsdt);
            } catch (Throwable $e) {
                $this->logger->error('Retry profit conversion failed', [
                    'purchase_id' => $p['id'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            try {
                $this->db->begin();
                $this->db->exec('UPDATE purchases SET profit_usdc = :pc, status = :st WHERE id = :id', [
                    ':pc' => $profitUsdc,
                    ':st' => self::STATUS_SOLD,
                    ':id' => $p['id'],
                ]);
                // Move from USDT to USDC in ledger.
                $this->addBalance('USDT', -$usdtSpent);
                $this->addBalance('USDC', $profitUsdc);
                $this->insertEvent('PROFIT_CONVERT_RETRY', [
                    'purchase_id' => (int)$p['id'],
                    'profit_usdt' => $profitUsdt,
                    'profit_convert_symbol' => $this->profitConverter->symbol(),
                    'profit_convert_order_id' => $convOrderId,
                    'profit_usdc' => $profitUsdc,
                    'profit_convert_usdt_spent' => $usdtSpent,
                ]);
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }
    }

    private function placeNewPurchaseIfDue(): void
    {
        $latest = $this->db->fetchOne('SELECT created_at FROM purchases ORDER BY id DESC LIMIT 1');
        if ($latest !== null) {
            $last = new DateTimeImmutable((string)$latest['created_at'] . ' UTC');
            $dueAt = $last->add(new DateInterval('P' . $this->dcaIntervalDays . 'D'));
            if (new DateTimeImmutable('now', new \DateTimeZone('UTC')) < $dueAt) {
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
}
