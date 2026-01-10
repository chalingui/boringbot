<?php
declare(strict_types=1);

use BoringBot\Exchange\BybitClient;
use BoringBot\Utils\Config;
use BoringBot\Utils\Logger;

require __DIR__ . '/../src/autoload.php';

$root = dirname(__DIR__);
$cfg = Config::load($root);
$logger = new Logger($cfg['log_path']);

$orderId = null;
$symbol = (string)($cfg['symbols']['trade'] ?? 'ETHUSDT');
$showRaw = false;

foreach ($argv as $i => $arg) {
    if (($arg === '--order-id' || $arg === '--id') && isset($argv[$i + 1])) {
        $orderId = trim((string)$argv[$i + 1]);
    }
    if ($arg === '--symbol' && isset($argv[$i + 1])) {
        $symbol = trim((string)$argv[$i + 1]);
    }
    if ($arg === '--raw') {
        $showRaw = true;
    }
}

if ($orderId === null || $orderId === '') {
    echo "Usage: php bin/order.php --order-id <id> [--symbol ETHUSDT] [--raw]\n";
    exit(1);
}

$bybit = new BybitClient(
    $cfg['bybit']['base_url'],
    $cfg['bybit']['api_key'],
    $cfg['bybit']['api_secret'],
    (int)$cfg['bybit']['recv_window'],
    (string)($cfg['bybit']['account_type'] ?? 'SPOT'),
);

try {
    $order = $bybit->getOrder($symbol, $orderId);
} catch (Throwable $e) {
    $logger->error('Order lookup failed', [
        'order_id' => $orderId,
        'symbol' => $symbol,
        'error' => $e->getMessage(),
    ]);
    echo "Order lookup failed: {$e->getMessage()}\n";
    exit(1);
}

if (!is_array($order)) {
    echo "Order not found for {$symbol} / {$orderId}\n";
    exit(1);
}

if ($showRaw) {
    echo json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$fields = [
    'orderId' => $order['orderId'] ?? $orderId,
    'symbol' => $order['symbol'] ?? $symbol,
    'status' => $order['orderStatus'] ?? null,
    'side' => $order['side'] ?? null,
    'orderType' => $order['orderType'] ?? null,
    'qty' => $order['qty'] ?? null,
    'price' => $order['price'] ?? null,
    'avgPrice' => $order['avgPrice'] ?? null,
    'cumExecQty' => $order['cumExecQty'] ?? null,
    'cumExecValue' => $order['cumExecValue'] ?? null,
    'createdTime' => $order['createdTime'] ?? null,
    'updatedTime' => $order['updatedTime'] ?? null,
];

foreach ($fields as $label => $value) {
    if ($value === null || $value === '') {
        continue;
    }
    echo "{$label}: {$value}\n";
}
