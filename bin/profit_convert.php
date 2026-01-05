<?php
declare(strict_types=1);

use BoringBot\Bot\ProfitConverter;
use BoringBot\DB\Database;
use BoringBot\Exchange\BybitClient;
use BoringBot\Utils\Config;
use BoringBot\Utils\Logger;

require __DIR__ . '/../src/autoload.php';

function usage(): void
{
    echo "Usage:\n";
    echo "  php bin/profit_convert.php --id N [--execute]\n";
    echo "  php bin/profit_convert.php --usdt X [--execute]\n";
    echo "\n";
    echo "Default is dry (prints payload/mins, does not place order).\n";
    echo "Use --execute to actually place the market conversion order.\n";
}

function formatNumber(float $n): string
{
    $s = rtrim(rtrim(number_format($n, 10, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

$id = null;
$usdt = null;
$execute = false;

foreach ($argv as $i => $arg) {
    if ($arg === '--help' || $arg === '-h') {
        usage();
        exit(0);
    }
    if ($arg === '--execute') {
        $execute = true;
    }
    if ($arg === '--id' && isset($argv[$i + 1])) {
        $id = (int)$argv[$i + 1];
    }
    if ($arg === '--usdt' && isset($argv[$i + 1])) {
        $usdt = (float)$argv[$i + 1];
    }
}

$root = dirname(__DIR__);
$cfg = Config::load($root);
$logger = new Logger($cfg['log_path']);
$db = new Database($cfg['db_path']);
$db->migrateFromFile($root . '/db/schema.sql');

if ($id !== null && $id > 0) {
    $p = $db->fetchOne('SELECT * FROM purchases WHERE id = :id', [':id' => $id]);
    if ($p === null) {
        echo "Purchase #{$id} not found.\n";
        exit(1);
    }
    $usdt = (float)($p['profit_usdt'] ?? 0.0);
    echo "Purchase #{$id} profit_usdt={$usdt}\n";
}

if (!is_float($usdt) || $usdt <= 0) {
    usage();
    exit(1);
}

$bybit = new BybitClient(
    $cfg['bybit']['base_url'],
    $cfg['bybit']['api_key'],
    $cfg['bybit']['api_secret'],
    (int)$cfg['bybit']['recv_window'],
    (string)($cfg['bybit']['account_type'] ?? 'SPOT'),
);

$symbol = (string)$cfg['symbols']['profit_convert'];
$minOrderAmt = null;
try {
    $minOrderAmt = $bybit->minOrderAmount($symbol);
} catch (Throwable $e) {
    $minOrderAmt = null;
    echo "WARN: Could not fetch minOrderAmt: {$e->getMessage()}\n";
}

$payload = [
    'category' => 'spot',
    'symbol' => $symbol,
    'side' => 'Buy',
    'orderType' => 'Market',
    'qty' => formatNumber($usdt),
    'marketUnit' => 'quoteCoin',
    'timeInForce' => 'IOC',
];

echo "Profit convert debug\n";
echo "- symbol: {$symbol}\n";
echo "- usdt: {$usdt}\n";
echo "- qty(sent): {$payload['qty']}\n";
echo "- minOrderAmt: " . ($minOrderAmt === null ? 'null' : (string)$minOrderAmt) . "\n";
echo "- execute: " . ($execute ? 'yes' : 'no') . "\n";
echo "\n";

if (!$execute) {
    echo "Payload (what would be sent)\n";
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

$profitConverter = new ProfitConverter(
    $bybit,
    $logger,
    $symbol,
    false
);

[$orderId, $usdcQty, $usdtSpent] = $profitConverter->convertUsdtToUsdc($usdt);
echo "Order placed\n";
echo "- orderId: {$orderId}\n";
echo "- usdcQty: {$usdcQty}\n";
echo "- usdtSpent: {$usdtSpent}\n";
