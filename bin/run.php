<?php
declare(strict_types=1);

use BoringBot\Bot\DcaBot;
use BoringBot\Bot\Notifier;
use BoringBot\Bot\ProfitConverter;
use BoringBot\Bot\PurchaseManager;
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
    $logger->info('Run tick start', ['dry_run' => $dryRun]);

    $bybit = new BybitClient(
        $cfg['bybit']['base_url'],
        $cfg['bybit']['api_key'],
        $cfg['bybit']['api_secret'],
        (int)$cfg['bybit']['recv_window'],
        (string)($cfg['bybit']['account_type'] ?? 'SPOT'),
    );

    $profitConverter = new ProfitConverter(
        $bybit,
        $logger,
        (string)$cfg['symbols']['profit_convert'],
        $dryRun,
    );

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
            (int)($cfg['notify']['cooldown_minutes'] ?? 720),
        );
    }

    $purchases = new PurchaseManager(
        $db,
        $bybit,
        $profitConverter,
        $notifier,
        $logger,
        (string)$cfg['symbols']['trade'],
        (float)$cfg['strategy']['dca_amount_usdt'],
        (int)$cfg['strategy']['dca_interval_days'],
        (float)$cfg['strategy']['sell_markup_pct'],
        (int)($cfg['notify']['no_funds_lead_hours'] ?? 48),
        $dryRun,
    );

    $bot = new DcaBot($db, $purchases, $logger);
    $code = $bot->run();

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
