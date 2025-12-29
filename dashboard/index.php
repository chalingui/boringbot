<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';
require __DIR__ . '/_layout.php';

use BoringBot\DB\Database;
use BoringBot\Utils\Config;

$root = dirname(__DIR__);
$cfg = Config::load($root);
$db = new Database($cfg['db_path']);
$db->migrateFromFile($root . '/db/schema.sql');

$view = (string)($_GET['view'] ?? 'home');

function tailFile(string $path, int $maxLines = 200, bool $newestFirst = false): string
{
    if (!is_file($path)) {
        return "File not found: {$path}\n";
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return "Cannot open: {$path}\n";
    }

    $pos = -1;
    $lines = [];
    $buffer = '';
    fseek($fh, 0, SEEK_END);
    $size = ftell($fh) ?: 0;
    while ($size + $pos >= 0 && count($lines) < $maxLines) {
        fseek($fh, $pos, SEEK_END);
        $ch = fgetc($fh);
        if ($ch === false) {
            break;
        }
        if ($ch === "\n") {
            $lines[] = strrev($buffer);
            $buffer = '';
        } else {
            $buffer .= $ch;
        }
        $pos--;
    }
    if ($buffer !== '' && count($lines) < $maxLines) {
        $lines[] = strrev($buffer);
    }
    fclose($fh);
    $lines = array_reverse($lines);
    if ($newestFirst) {
        $lines = array_reverse($lines);
    }
    return trim(implode("\n", $lines)) . "\n";
}

renderHeader(match ($view) {
    'purchases' => 'Compras',
    'events' => 'Eventos',
    'logs' => 'Logs',
    default => 'Dashboard',
});

if ($view === 'purchases') {
    $rows = $db->fetchAll('SELECT * FROM purchases ORDER BY id DESC LIMIT 200');
    echo '<div class="card"><table><thead><tr>';
    echo '<th>ID</th><th>Status</th><th>Created</th><th>Buy</th><th>Sell</th><th>Profit</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $p) {
        $id = (int)$p['id'];
        $status = (string)$p['status'];
        echo '<tr>';
        echo '<td>#' . h((string)$id) . '</td>';
        echo '<td><span class="pill ' . h($status) . '">' . h($status) . '</span></td>';
        echo '<td>' . h((string)$p['created_at']) . '</td>';
        echo '<td>';
        echo h((string)$p['buy_usdt']) . " USDT<br><span class=\"muted\">price=" . h((string)($p['buy_price'] ?? '')) . " qty=" . h((string)($p['buy_qty'] ?? '')) . '</span>';
        echo '</td>';
        echo '<td>';
        echo '<span class="muted">target=' . h((string)($p['sell_price'] ?? '')) . ' qty=' . h((string)($p['sell_qty'] ?? '')) . '</span><br>';
        echo 'filled=' . h((string)($p['sell_usdt'] ?? '')) . ' USDT';
        echo '</td>';
        echo '<td>';
        echo 'profit=' . h((string)($p['profit_usdt'] ?? '')) . ' USDT<br><span class="muted">usdc=' . h((string)($p['profit_usdc'] ?? '')) . '</span>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    renderFooter();
    exit;
}

if ($view === 'events') {
    $rows = $db->fetchAll('SELECT id, created_at, type, payload_json FROM events_log ORDER BY id DESC LIMIT 200');
    echo '<div class="card"><table><thead><tr><th>ID</th><th>Created</th><th>Type</th><th>Payload</th></tr></thead><tbody>';
    foreach ($rows as $e) {
        $payload = (string)$e['payload_json'];
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $payload = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: (string)$e['payload_json'];
        }
        echo '<tr>';
        echo '<td>' . h((string)$e['id']) . '</td>';
        echo '<td>' . h((string)$e['created_at']) . '</td>';
        echo '<td><code>' . h((string)$e['type']) . '</code></td>';
        echo '<td><pre style="margin:0">' . h($payload) . '</pre></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    renderFooter();
    exit;
}

if ($view === 'logs') {
    $logPath = $cfg['log_path'];
    $cronPath = $root . '/logs/cron.log';
    $reconcilePath = $root . '/logs/reconcile.log';

    echo '<div class="grid">';
    echo '<div class="card"><div class="muted">boringbot.log (newest first)</div><pre>' . h(tailFile($logPath, 250, true)) . '</pre></div>';
    echo '<div class="card"><div class="muted">cron.log (newest first)</div><pre>' . h(tailFile($cronPath, 120, true)) . '</pre></div>';
    echo '<div class="card"><div class="muted">reconcile.log (newest first)</div><pre>' . h(tailFile($reconcilePath, 120, true)) . '</pre></div>';
    echo '</div>';
    renderFooter();
    exit;
}

// Home (overview)
$balances = $db->fetchAll('SELECT asset, amount FROM balances ORDER BY asset ASC');
$lastEvents = $db->fetchAll('SELECT id, created_at, type, payload_json FROM events_log ORDER BY id DESC LIMIT 20');
$open = $db->fetchOne('SELECT COUNT(1) as c FROM purchases WHERE status IN ("BUYING","HOLDING","OPEN","SOLD_PENDING_CONVERT")');
$sold = $db->fetchOne('SELECT COUNT(1) as c FROM purchases WHERE status = "SOLD"');

echo '<div class="grid">';
echo '<div class="card col6"><div class="muted">Balances (ledger)</div><div class="kpi">';
foreach ($balances as $b) {
    echo '<div class="item"><div class="muted">' . h((string)$b['asset']) . '</div><div style="font-size:18px">' . h(number_format((float)$b['amount'], 8, '.', '')) . '</div></div>';
}
echo '</div></div>';

echo '<div class="card col6"><div class="muted">Resumen</div>';
echo '<div class="kpi">';
echo '<div class="item"><div class="muted">Activas</div><div style="font-size:18px">' . h((string)($open['c'] ?? '0')) . '</div></div>';
echo '<div class="item"><div class="muted">Vendidas</div><div style="font-size:18px">' . h((string)($sold['c'] ?? '0')) . '</div></div>';
echo '<div class="item"><div class="muted">Trade symbol</div><div style="font-size:18px">' . h((string)($cfg['symbols']['trade'] ?? '')) . '</div></div>';
echo '<div class="item"><div class="muted">DCA</div><div style="font-size:18px">' . h((string)($cfg['strategy']['dca_amount_usdt'] ?? '')) . ' USDT</div></div>';
echo '<div class="item"><div class="muted">Sell markup</div><div style="font-size:18px">' . h((string)($cfg['strategy']['sell_markup_pct'] ?? '')) . '%</div></div>';
echo '</div>';
echo '</div>';

echo '<div class="card"><div class="muted">Últimos eventos</div><table><thead><tr><th>When</th><th>Type</th><th>Payload</th></tr></thead><tbody>';
foreach ($lastEvents as $e) {
    $payload = (string)$e['payload_json'];
    $decoded = json_decode($payload, true);
    if (is_array($decoded)) {
        $payload = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: (string)$e['payload_json'];
    }
    if (strlen($payload) > 240) {
        $payload = substr($payload, 0, 240) . '…';
    }
    echo '<tr>';
    echo '<td>' . h((string)$e['created_at']) . '</td>';
    echo '<td><code>' . h((string)$e['type']) . '</code></td>';
    echo '<td><code>' . h($payload) . '</code></td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
echo '</div>';

renderFooter();
