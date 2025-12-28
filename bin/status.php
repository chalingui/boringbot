<?php
declare(strict_types=1);

use BoringBot\DB\Database;
use BoringBot\Exchange\BybitClient;
use BoringBot\Utils\Config;
use BoringBot\Utils\Logger;

require __DIR__ . '/../src/autoload.php';

$root = dirname(__DIR__);
$cfg = Config::load($root);
$logger = new Logger($cfg['log_path']);
$db = new Database($cfg['db_path']);
$db->migrateFromFile($root . '/db/schema.sql');

$id = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--id' && isset($argv[$i + 1])) {
        $id = (int)$argv[$i + 1];
    }
}

$balances = $db->fetchAll('SELECT asset, amount FROM balances ORDER BY asset ASC');
echo "Balances (bot ledger)\n";
foreach ($balances as $b) {
    echo sprintf("- %s: %.8f\n", $b['asset'], (float)$b['amount']);
}
echo "\n";

$bybit = new BybitClient(
    $cfg['bybit']['base_url'],
    $cfg['bybit']['api_key'],
    $cfg['bybit']['api_secret'],
    (int)$cfg['bybit']['recv_window'],
    (string)($cfg['bybit']['account_type'] ?? 'SPOT'),
);

if ($id !== null && $id > 0) {
    $p = $db->fetchOne('SELECT * FROM purchases WHERE id = :id', [':id' => $id]);
    if ($p === null) {
        echo "Purchase #{$id} not found.\n";
        exit(1);
    }

    echo "Purchase #{$id}\n";
    echo "- status: {$p['status']}\n";
    echo "- created_at: {$p['created_at']}\n";
    echo "- buy_usdt: {$p['buy_usdt']}\n";
    echo "- buy_order_id: {$p['buy_order_id']}\n";
    echo "- buy_price: {$p['buy_price']}\n";
    echo "- buy_qty: {$p['buy_qty']}\n";
    echo "- sell_markup_pct: {$p['sell_markup_pct']}\n";
    echo "- sell_order_id: {$p['sell_order_id']}\n";
    echo "- sell_price: {$p['sell_price']}\n";
    echo "- sell_qty: {$p['sell_qty']}\n";
    echo "- sell_usdt: {$p['sell_usdt']}\n";
    echo "- profit_usdt: {$p['profit_usdt']}\n";
    echo "- profit_usdc: {$p['profit_usdc']}\n";
    echo "\n";

    if ($p['status'] === 'OPEN' && $p['sell_price'] !== null && $p['sell_qty'] !== null) {
        $last = $bybit->tickerLastPrice((string)$cfg['symbols']['trade']);
        if ($last === null) {
            echo "Target gap: N/A (cannot fetch ticker)\n";
            exit(0);
        }

        $target = (float)$p['sell_price'];
        $qty = (float)$p['sell_qty'];
        if ($last >= $target) {
            echo "Target gap: reached (last >= target)\n";
        } else {
            $missingPerEth = $target - $last;
            $missingTotal = $missingPerEth * $qty;
            $missingPct = (($target / $last) - 1.0) * 100.0;
            echo sprintf("Target gap: %.4f%% | %.4f USDT/ETH | %.4f USDT total\n", $missingPct, $missingPerEth, $missingTotal);
        }
    }

    exit(0);
}

echo "Purchases\n";
$rows = $db->fetchAll('SELECT id, created_at, status, buy_usdt, buy_price, buy_qty, sell_price, sell_usdt FROM purchases ORDER BY id DESC');
foreach ($rows as $p) {
    echo sprintf(
        "- #%d %s %s buy_usdt=%.2f buy_price=%s buy_qty=%s sell_price=%s sell_usdt=%s\n",
        (int)$p['id'],
        $p['created_at'],
        $p['status'],
        (float)$p['buy_usdt'],
        $p['buy_price'] === null ? 'null' : (string)$p['buy_price'],
        $p['buy_qty'] === null ? 'null' : (string)$p['buy_qty'],
        $p['sell_price'] === null ? 'null' : (string)$p['sell_price'],
        $p['sell_usdt'] === null ? 'null' : (string)$p['sell_usdt'],
    );
}

echo "\nTip: php bin/status.php --id N\n";

