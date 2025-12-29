<?php
declare(strict_types=1);

namespace BoringBot\Exchange;

use RuntimeException;

final class BybitClient
{
    /** @var array<string, array> */
    private array $instrumentCache = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly int $recvWindowMs = 5000,
        private readonly string $accountType = 'SPOT',
    ) {
    }

    public function tickerLastPrice(string $symbol): ?float
    {
        $data = $this->request('GET', '/v5/market/tickers', [
            'category' => 'spot',
            'symbol' => $symbol,
        ], false);

        $list = $data['result']['list'] ?? [];
        if (!is_array($list) || ($list[0]['lastPrice'] ?? null) === null) {
            return null;
        }

        return (float)$list[0]['lastPrice'];
    }

    public function walletBalance(string $coin): ?float
    {
        try {
            $data = $this->request('GET', '/v5/account/wallet-balance', [
                'accountType' => $this->accountType,
                'coin' => $coin,
            ], true);
        } catch (BybitApiException $e) {
            // Many accounts only support UNIFIED for this endpoint.
            if (
                $e->retCode === 10001
                && str_contains(strtolower($e->retMsg), 'accounttype')
                && str_contains(strtolower($e->retMsg), 'unified')
                && $this->accountType !== 'UNIFIED'
            ) {
                $data = $this->request('GET', '/v5/account/wallet-balance', [
                    'accountType' => 'UNIFIED',
                    'coin' => $coin,
                ], true);
            } else {
                throw $e;
            }
        }

        $list = $data['result']['list'][0]['coin'] ?? null;
        if (!is_array($list)) {
            return null;
        }

        foreach ($list as $row) {
            if (($row['coin'] ?? '') === $coin) {
                // Prefer walletBalance, fallback to availableToWithdraw/transfer.
                $bal = $row['walletBalance'] ?? $row['availableToWithdraw'] ?? $row['availableToTransfer'] ?? null;
                if ($bal === null) {
                    return null;
                }
                return (float)$bal;
            }
        }

        return null;
    }

    public function createMarketBuyByQuote(string $symbol, float $quoteQty): string
    {
        $data = $this->request('POST', '/v5/order/create', [
            'category' => 'spot',
            'symbol' => $symbol,
            'side' => 'Buy',
            'orderType' => 'Market',
            'qty' => $this->formatNumber($quoteQty),
            'marketUnit' => 'quoteCoin',
            'timeInForce' => 'IOC',
        ], true);

        $orderId = $data['result']['orderId'] ?? null;
        if (!is_string($orderId) || $orderId === '') {
            throw new RuntimeException('Bybit did not return orderId for market buy.');
        }
        return $orderId;
    }

    public function createLimitSell(string $symbol, float $qtyBase, float $price): string
    {
        [$qtyBase, $price] = $this->normalizeLimitOrder($symbol, $qtyBase, $price);

        $data = $this->request('POST', '/v5/order/create', [
            'category' => 'spot',
            'symbol' => $symbol,
            'side' => 'Sell',
            'orderType' => 'Limit',
            'qty' => $this->formatNumber($qtyBase),
            'price' => $this->formatNumber($price),
            'timeInForce' => 'GTC',
        ], true);

        $orderId = $data['result']['orderId'] ?? null;
        if (!is_string($orderId) || $orderId === '') {
            throw new RuntimeException('Bybit did not return orderId for limit sell.');
        }
        return $orderId;
    }

    /**
     * Returns realtime order data:
     * - orderStatus, avgPrice, cumExecQty, cumExecValue
     */
    public function getOrder(string $symbol, string $orderId): ?array
    {
        $data = $this->request('GET', '/v5/order/realtime', [
            'category' => 'spot',
            'symbol' => $symbol,
            'orderId' => $orderId,
        ], true);

        $list = $data['result']['list'] ?? [];
        if (!is_array($list) || $list === []) {
            return null;
        }
        return $list[0];
    }

    private function request(string $method, string $path, array $params, bool $auth): array
    {
        $method = strtoupper($method);
        $url = rtrim($this->baseUrl, '/') . $path;

        $body = '';
        $query = '';

        if ($method === 'GET') {
            $query = $this->buildQuery($params);
            if ($query !== '') {
                $url .= '?' . $query;
            }
        } else {
            $body = json_encode($params, JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                throw new RuntimeException('Failed to encode JSON body.');
            }
        }

        $headers = ['Content-Type: application/json'];
        if ($auth) {
            if ($this->apiKey === '' || $this->apiSecret === '') {
                throw new RuntimeException('Missing BYBIT_API_KEY or BYBIT_API_SECRET.');
            }
            $timestamp = (string)intval(microtime(true) * 1000);
            $recvWindow = (string)$this->recvWindowMs;
            $payload = $method === 'GET' ? $query : $body;
            $preSign = $timestamp . $this->apiKey . $recvWindow . $payload;
            $sign = hash_hmac('sha256', $preSign, $this->apiSecret);

            $headers[] = 'X-BAPI-API-KEY: ' . $this->apiKey;
            $headers[] = 'X-BAPI-SIGN: ' . $sign;
            $headers[] = 'X-BAPI-SIGN-TYPE: 2';
            $headers[] = 'X-BAPI-TIMESTAMP: ' . $timestamp;
            $headers[] = 'X-BAPI-RECV-WINDOW: ' . $recvWindow;
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('curl_init failed.');
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $respBody = curl_exec($ch);
        if ($respBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Curl error: ' . $err);
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($respBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response (HTTP ' . $httpCode . '): ' . $respBody);
        }

        $retCode = $decoded['retCode'] ?? null;
        if ($httpCode >= 400 || $retCode !== 0) {
            $retMsg = $decoded['retMsg'] ?? 'Unknown error';
            throw new BybitApiException(
                httpCode: $httpCode,
                retCode: (int)$retCode,
                retMsg: (string)$retMsg,
                message: "Bybit error (HTTP {$httpCode}, retCode {$retCode}): {$retMsg}"
            );
        }

        return $decoded;
    }

    private function buildQuery(array $params): string
    {
        ksort($params);
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function formatNumber(float $n): string
    {
        // Keep Bybit happy: trim trailing zeros, avoid scientific notation.
        $s = rtrim(rtrim(number_format($n, 10, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    /**
     * Normalizes qty/price to symbol filters (qtyStep and tickSize).
     * - qty is floored to step (never exceeds available)
     * - price is ceiled to tick (keeps target at or above requested price)
     */
    private function normalizeLimitOrder(string $symbol, float $qtyBase, float $price): array
    {
        $info = $this->instrumentInfo($symbol);
        $lot = $info['lotSizeFilter'] ?? [];
        $pf = $info['priceFilter'] ?? [];

        $qtyStep = (string)($lot['qtyStep'] ?? '');
        $tickSize = (string)($pf['tickSize'] ?? '');
        $minOrderQty = isset($lot['minOrderQty']) ? (float)$lot['minOrderQty'] : null;
        $minOrderAmt = isset($lot['minOrderAmt']) ? (float)$lot['minOrderAmt'] : null;

        $qty = $qtyBase;
        if ($qtyStep !== '' && (float)$qtyStep > 0) {
            $qty = $this->floorToStep($qtyBase, (float)$qtyStep, $this->decimalsFromStep($qtyStep));
        }

        $p = $price;
        if ($tickSize !== '' && (float)$tickSize > 0) {
            $p = $this->ceilToStep($price, (float)$tickSize, $this->decimalsFromStep($tickSize));
        }

        if ($qty <= 0 || $p <= 0) {
            throw new RuntimeException("Invalid normalized order params for {$symbol}: qty={$qty}, price={$p}");
        }
        if ($minOrderQty !== null && $qty + 1e-12 < $minOrderQty) {
            throw new RuntimeException("Order qty below minOrderQty for {$symbol}: qty={$qty}, min={$minOrderQty}");
        }
        if ($minOrderAmt !== null && ($qty * $p) + 1e-8 < $minOrderAmt) {
            throw new RuntimeException("Order notional below minOrderAmt for {$symbol}: amt=" . ($qty * $p) . ", min={$minOrderAmt}");
        }

        return [$qty, $p];
    }

    private function instrumentInfo(string $symbol): array
    {
        if (isset($this->instrumentCache[$symbol])) {
            return $this->instrumentCache[$symbol];
        }

        $data = $this->request('GET', '/v5/market/instruments-info', [
            'category' => 'spot',
            'symbol' => $symbol,
        ], false);

        $list = $data['result']['list'] ?? [];
        if (!is_array($list) || $list === []) {
            throw new RuntimeException("No instrument info for {$symbol}");
        }
        $this->instrumentCache[$symbol] = $list[0];
        return $this->instrumentCache[$symbol];
    }

    private function decimalsFromStep(string $step): int
    {
        $step = trim($step);
        if ($step === '' || !str_contains($step, '.')) {
            return 0;
        }
        $step = rtrim($step, '0');
        $pos = strpos($step, '.');
        if ($pos === false) {
            return 0;
        }
        return max(0, strlen($step) - $pos - 1);
    }

    private function floorToStep(float $value, float $step, int $decimals): float
    {
        $mult = floor(($value + 1e-12) / $step);
        return round($mult * $step, $decimals);
    }

    private function ceilToStep(float $value, float $step, int $decimals): float
    {
        $mult = ceil(($value - 1e-12) / $step);
        return round($mult * $step, $decimals);
    }
}
