<?php
declare(strict_types=1);

use BoringBot\Bot\Reconciler;
use BoringBot\DB\Database;
use BoringBot\Exchange\BybitClient;
use BoringBot\Utils\Config;
use BoringBot\Utils\Logger;

require __DIR__ . '/../src/autoload.php';

$stdout = fopen('php://stdout', 'wb') ?: null;
$stderr = fopen('php://stderr', 'wb') ?: null;

$dryRun = in_array('--dry-run', $argv, true);
$root = dirname(__DIR__);
$cfg = Config::load($root);

$logger = new Logger($cfg['log_path']);
$db = new Database($cfg['db_path']);
$db->migrateFromFile($root . '/db/schema.sql');

$bybit = new BybitClient(
    $cfg['bybit']['base_url'],
    $cfg['bybit']['api_key'],
    $cfg['bybit']['api_secret'],
    (int)$cfg['bybit']['recv_window'],
    (string)($cfg['bybit']['account_type'] ?? 'SPOT'),
);

try {
    $reconciler = new Reconciler($db, $bybit, $logger, $dryRun);
    $reconciler->reconcileUsdt();
    if ($stdout) {
        fwrite($stdout, "OK\n");
    } else {
        echo "OK\n";
    }
    exit(0);
} catch (Throwable $e) {
    $logger->error('Reconcile failed', ['error' => $e->getMessage(), 'class' => get_class($e)]);
    $msg = "ERROR: " . $e->getMessage();
    if ($stderr) {
        fwrite($stderr, $msg . "\n");
    } else {
        error_log($msg);
    }
    exit(1);
}
