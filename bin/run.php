<?php
declare(strict_types=1);

use BoringBot\Bot\DcaBot;
use BoringBot\Bot\Notifier;
use BoringBot\Bot\PurchaseManager;
use BoringBot\Bot\Reconciler;
use BoringBot\DB\Database;
use BoringBot\Exchange\BybitClient;
use BoringBot\Utils\Config;
use BoringBot\Utils\Lock;
use BoringBot\Utils\Logger;
use BoringBot\Utils\Mailer;

require __DIR__ . '/../src/autoload.php';

$stdout = fopen('php://stdout', 'wb') ?: null;
$stderr = fopen('php://stderr', 'wb') ?: null;

$dryRun = in_array('--dry-run', $argv, true);
$root = dirname(__DIR__);
$cfg = Config::load($root);

$tradeSymbols = array_values(array_unique(array_filter([
    (string)($cfg['symbols']['trade'] ?? 'ETHUSDT'),
    (string)($cfg['symbols']['trade_btc'] ?? 'BTCUSDT'),
])));

$logger = new Logger($cfg['log_path']);
$lock = new Lock($cfg['lock_path']);
if (!$lock->acquire()) {
    $logger->warn('Another instance is running; exiting.');
    $msg = "Locked (already running).\n";
    if ($stderr) {
        fwrite($stderr, $msg);
    } else {
        error_log(trim($msg));
    }
    exit(0);
}

function setMeta(Database $db, string $k, string $v): void
{
    // Compatible with older SQLite versions.
    $db->exec('INSERT OR REPLACE INTO meta(k, v) VALUES(:k, :v)', [':k' => $k, ':v' => $v]);
}

try {
    $db = new Database($cfg['db_path']);
    $db->migrateFromFile($root . '/db/schema.sql');

    $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    setMeta($db, 'last_run_started_at', $startedAt->format(DATE_ATOM));
    $db->insert('INSERT INTO events_log(type, payload_json) VALUES(:t, :p)', [
        ':t' => 'RUN_START',
        ':p' => json_encode(['dry_run' => $dryRun], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $logger->blankLine();
    $logger->info('Run tick start', ['dry_run' => $dryRun]);

    $bybit = new BybitClient(
        $cfg['bybit']['base_url'],
        $cfg['bybit']['api_key'],
        $cfg['bybit']['api_secret'],
        (int)$cfg['bybit']['recv_window'],
        (string)($cfg['bybit']['account_type'] ?? 'SPOT'),
    );

    $reconciler = new Reconciler($db, $bybit, $logger, $dryRun);
    // Keep USDT ledger in sync before deciding if new DCA purchases are affordable.
    $reconciler->reconcileUsdt();

    $notifier = null;
    if (($cfg['notify']['enabled'] ?? false) === true) {
        $mailer = new Mailer(
            $logger,
            (string)($cfg['notify']['smtp']['host'] ?? ''),
            (int)($cfg['notify']['smtp']['port'] ?? 587),
            (string)($cfg['notify']['smtp']['user'] ?? ''),
            (string)($cfg['notify']['smtp']['pass'] ?? ''),
            (string)($cfg['notify']['smtp']['encryption'] ?? 'starttls'),
            $dryRun,
        );

        $notifier = new Notifier(
            $db,
            $logger,
            $mailer,
            (bool)($cfg['notify']['enabled'] ?? false),
            (string)($cfg['notify']['email_to'] ?? ''),
            (string)($cfg['notify']['email_from'] ?? ''),
            (int)($cfg['notify']['cooldown_minutes'] ?? 60),
        );
    }

    $purchases = new PurchaseManager(
        $db,
        $bybit,
        $notifier,
        $logger,
        $tradeSymbols,
        (float)$cfg['strategy']['dca_amount_usdt'],
        (int)$cfg['strategy']['dca_interval_days'],
        (int)($cfg['strategy']['dca_offset_hours'] ?? 0),
        (float)$cfg['strategy']['sell_markup_pct'],
        (float)($cfg['strategy']['sell_qty_buffer'] ?? 0.0),
        (int)($cfg['notify']['no_funds_lead_hours'] ?? 48),
        (bool)($cfg['transfers']['enabled'] ?? false),
        (string)($cfg['transfers']['from_account'] ?? ''),
        (string)($cfg['transfers']['principal_to_account'] ?? ''),
        (string)($cfg['transfers']['profit_to_account'] ?? ''),
        (bool)($cfg['transfers']['base_asset_enabled'] ?? false),
        (string)($cfg['transfers']['base_asset_from_account'] ?? ''),
        (string)($cfg['transfers']['base_asset_to_account'] ?? ''),
        $dryRun,
    );

    $eventsBeforeRun = (int)(($db->fetchOne('SELECT COALESCE(MAX(id), 0) AS id FROM events_log') ?? [])['id'] ?? 0);

    $bot = new DcaBot($db, $purchases, $logger);
    $code = $bot->run();

    // Reconcile base assets only after buy/sell fills in this run.
    $assetActivity = $db->fetchOne(
        'SELECT COUNT(1) AS c
         FROM events_log
         WHERE id > :id
           AND (
             type = :sold
             OR type = :buy_ok
             OR type = :buy_fail
           )',
        [
            ':id' => $eventsBeforeRun,
            ':sold' => 'SOLD',
            ':buy_ok' => 'BUY_FILLED_SELL_PLACED',
            ':buy_fail' => 'BUY_FILLED_SELL_FAILED',
        ]
    );
    $hasAssetActivity = ((int)($assetActivity['c'] ?? 0)) > 0;
    if ($hasAssetActivity) {
        $reconciler->reconcileEth();
        $reconciler->reconcileBtc();
    }

    $endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    setMeta($db, 'last_run_finished_at', $endedAt->format(DATE_ATOM));
    $db->insert('INSERT INTO events_log(type, payload_json) VALUES(:t, :p)', [
        ':t' => 'RUN_FINISH',
        ':p' => json_encode(['dry_run' => $dryRun, 'exit_code' => $code], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $logger->info('Run tick finish', ['dry_run' => $dryRun, 'exit_code' => $code]);

    exit($code);
} finally {
    $lock->release();
}
