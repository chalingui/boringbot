<?php
declare(strict_types=1);

use BoringBot\Bot\Notifier;
use BoringBot\DB\Database;
use BoringBot\Exchange\BybitClient;
use BoringBot\Utils\Config;
use BoringBot\Utils\Logger;
use BoringBot\Utils\Mailer;

require __DIR__ . '/../src/autoload.php';

$stdout = fopen('php://stdout', 'wb') ?: null;
$stderr = fopen('php://stderr', 'wb') ?: null;

$dryRun = in_array('--dry-run', $argv, true);
$root = dirname(__DIR__);
$cfg = Config::load($root);

$logger = new Logger($cfg['log_path']);
$db = new Database($cfg['db_path']);
$db->migrateFromFile($root . '/db/schema.sql');

if (($cfg['notify']['enabled'] ?? false) !== true) {
    $msg = "NOTIFY_ENABLED=0 (disabled)\n";
    if ($stderr) {
        fwrite($stderr, $msg);
    } else {
        error_log(trim($msg));
    }
    exit(1);
}

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

if (!$notifier->isEnabled()) {
    $msg = "Notifier not fully configured (check NOTIFY_EMAIL_TO/NOTIFY_EMAIL_FROM/SMTP_*).\n";
    if ($stderr) {
        fwrite($stderr, $msg);
    } else {
        error_log(trim($msg));
    }
    exit(1);
}

try {
    $mailer->send(
        (string)($cfg['notify']['email_from'] ?? ''),
        (string)($cfg['notify']['email_to'] ?? ''),
        '[boringbot] Test email',
        "Test OK.\nDry-run: " . ($dryRun ? 'yes' : 'no') . "\n"
    );
    if ($stdout) {
        fwrite($stdout, "OK\n");
    } else {
        echo "OK\n";
    }
    exit(0);
} catch (Throwable $e) {
    $logger->error('Notify test failed', ['error' => $e->getMessage(), 'class' => get_class($e)]);
    $msg = "ERROR: " . $e->getMessage();
    if ($stderr) {
        fwrite($stderr, $msg . "\n");
    } else {
        error_log($msg);
    }
    exit(1);
}
