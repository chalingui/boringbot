<?php
declare(strict_types=1);

namespace BoringBot\Bot;

use BoringBot\Exchange\BybitApiException;
use BoringBot\Exchange\BybitClient;
use BoringBot\Utils\Logger;

final class ProfitConverter
{
    public function __construct(
        private readonly BybitClient $bybit,
        private readonly Logger $logger,
        private readonly string $symbolProfitConvert,
        private readonly bool $dryRun,
    ) {
    }

    public function symbol(): string
    {
        return $this->symbolProfitConvert;
    }

    /**
     * Converts USDT profit to USDC using market Buy on USDCUSDT (spend USDT).
     * Returns: [orderId|null, usdcQty, usdtSpent]
     */
    public function convertUsdtToUsdc(float $profitUsdt): array
    {
        if ($profitUsdt <= 0) {
            return [null, 0.0, 0.0];
        }

        $quoteQtyStr = self::formatNumber($profitUsdt);

        if ($this->dryRun) {
            $this->logger->info('DRY-RUN profit convert USDT->USDC', [
                'symbol' => $this->symbolProfitConvert,
                'usdt' => $profitUsdt,
                'bybit_payload' => [
                    'category' => 'spot',
                    'symbol' => $this->symbolProfitConvert,
                    'side' => 'Buy',
                    'orderType' => 'Market',
                    'qty' => $quoteQtyStr,
                    'marketUnit' => 'quoteCoin',
                    'timeInForce' => 'IOC',
                ],
            ]);
            return ['DRYRUN', $profitUsdt, $profitUsdt];
        }

        $this->logger->info('Profit convert attempt USDT->USDC', [
            'symbol' => $this->symbolProfitConvert,
            'usdt' => $profitUsdt,
            'bybit_payload' => [
                'category' => 'spot',
                'symbol' => $this->symbolProfitConvert,
                'side' => 'Buy',
                'orderType' => 'Market',
                'qty' => $quoteQtyStr,
                'marketUnit' => 'quoteCoin',
                'timeInForce' => 'IOC',
            ],
        ]);

        try {
            $orderId = $this->bybit->createMarketBuyByQuote($this->symbolProfitConvert, $profitUsdt);
        } catch (BybitApiException $e) {
            $this->logger->error('Profit convert Bybit API error', [
                'symbol' => $this->symbolProfitConvert,
                'usdt' => $profitUsdt,
                'httpCode' => $e->httpCode,
                'retCode' => $e->retCode,
                'retMsg' => $e->retMsg,
                'bybit_payload' => [
                    'category' => 'spot',
                    'symbol' => $this->symbolProfitConvert,
                    'side' => 'Buy',
                    'orderType' => 'Market',
                    'qty' => $quoteQtyStr,
                    'marketUnit' => 'quoteCoin',
                    'timeInForce' => 'IOC',
                ],
            ]);
            throw $e;
        }

        $this->logger->info('Profit convert order placed', [
            'symbol' => $this->symbolProfitConvert,
            'orderId' => $orderId,
            'usdt' => $profitUsdt,
            'qty' => $quoteQtyStr,
        ]);

        // Best-effort: fetch fill info (market should fill quickly).
        $order = $this->bybit->getOrder($this->symbolProfitConvert, $orderId);
        if (!is_array($order)) {
            return [$orderId, 0.0, $profitUsdt];
        }

        $usdcQty = isset($order['cumExecQty']) ? (float)$order['cumExecQty'] : 0.0;
        $usdtSpent = isset($order['cumExecValue']) ? (float)$order['cumExecValue'] : $profitUsdt;
        return [$orderId, $usdcQty, $usdtSpent];
    }

    private static function formatNumber(float $n): string
    {
        // Keep Bybit happy: trim trailing zeros, avoid scientific notation.
        $s = rtrim(rtrim(number_format($n, 10, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
