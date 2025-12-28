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

$dryRun = in_array('--dry-run', $argv, true);
$root = dirname(__DIR__);
$cfg = Config::load($root);

$logger = new Logger($cfg['log_path']);
$lock = new Lock($cfg['lock_path']);
if (!$lock->acquire()) {
    $logger->warn('Another instance is running; exiting.');
    fwrite(STDERR, "Locked (already running).\n");
    exit(0);
}

try {
    $db = new Database($cfg['db_path']);
    $db->migrateFromFile($root . '/db/schema.sql');

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
        $dryRun,
    );

    $bot = new DcaBot($db, $purchases, $logger);
    $code = $bot->run();
    exit($code);
} finally {
    $lock->release();
}
