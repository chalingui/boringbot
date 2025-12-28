<?php
declare(strict_types=1);

namespace BoringBot\Bot;

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

        if ($this->dryRun) {
            $this->logger->info('DRY-RUN profit convert USDT->USDC', [
                'symbol' => $this->symbolProfitConvert,
                'usdt' => $profitUsdt,
            ]);
            return ['DRYRUN', $profitUsdt, $profitUsdt];
        }

        $orderId = $this->bybit->createMarketBuyByQuote($this->symbolProfitConvert, $profitUsdt);
        $this->logger->info('Profit convert order placed', [
            'symbol' => $this->symbolProfitConvert,
            'orderId' => $orderId,
            'usdt' => $profitUsdt,
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
}
