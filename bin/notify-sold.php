<?php
declare(strict_types=1);

use BoringBot\Bot\Notifier;
use BoringBot\DB\Database;
use BoringBot\Utils\Config;
use BoringBot\Utils\Logger;
use BoringBot\Utils\Mailer;

require __DIR__ . '/../src/autoload.php';

function usage(): void
{
    echo "Usage:\n";
    echo "  php bin/notify-sold.php --id N\n";
    echo "\n";
    echo "Sends the \"sold\" email for an existing purchase (manual/one-off).\n";
}

$id = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--help' || $arg === '-h') {
        usage();
        exit(0);
    }
    if ($arg === '--id' && isset($argv[$i + 1])) {
        $id = (int)$argv[$i + 1];
    }
}

if ($id === null || $id <= 0) {
    usage();
    exit(1);
}

$root = dirname(__DIR__);
$cfg = Config::load($root);
$logger = new Logger($cfg['log_path']);
$db = new Database($cfg['db_path']);
$db->migrateFromFile($root . '/db/schema.sql');

if (($cfg['notify']['enabled'] ?? false) !== true) {
    fwrite(STDERR, "NOTIFY_ENABLED=0 (disabled)\n");
    exit(1);
}

$mailer = new Mailer(
    $logger,
    (string)($cfg['notify']['smtp']['host'] ?? ''),
    (int)($cfg['notify']['smtp']['port'] ?? 587),
    (string)($cfg['notify']['smtp']['user'] ?? ''),
    (string)($cfg['notify']['smtp']['pass'] ?? ''),
    (string)($cfg['notify']['smtp']['encryption'] ?? 'starttls'),
    false
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
    fwrite(STDERR, "Notifier not fully configured (check NOTIFY_EMAIL_TO/NOTIFY_EMAIL_FROM/SMTP_*).\n");
    exit(1);
}

$p = $db->fetchOne('SELECT * FROM purchases WHERE id = :id', [':id' => $id]);
if ($p === null) {
    fwrite(STDERR, "Purchase #{$id} not found.\n");
    exit(1);
}

$sellUsdt = (float)($p['sell_usdt'] ?? 0.0);
$profitUsdt = (float)($p['profit_usdt'] ?? 0.0);
$symbol = (string)($p['symbol'] ?? '');
if ($sellUsdt <= 0) {
    fwrite(STDERR, "Purchase #{$id} has no sell_usdt recorded.\n");
    exit(1);
}

try {
    $notifier->sold($id, $symbol === '' ? 'ETHUSDT' : $symbol, $sellUsdt, $profitUsdt);
    fwrite(STDOUT, "OK\n");
    exit(0);
} catch (Throwable $e) {
    $logger->error('Manual sold notify failed', ['purchase_id' => $id, 'error' => $e->getMessage()]);
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
