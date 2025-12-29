<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';
require __DIR__ . '/_layout.php';

use BoringBot\DB\Database;
use BoringBot\Exchange\BybitClient;
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

function fmtDbDt(?string $sqliteDt): string
{
    if ($sqliteDt === null || $sqliteDt === '') {
        return '';
    }
    try {
        // SQLite datetime('now') is UTC.
        $dt = new DateTimeImmutable($sqliteDt . ' UTC');
        $local = $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $local->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $sqliteDt;
    }
}

function fmtAtomLocal(?string $atom): string
{
    if ($atom === null || $atom === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($atom);
        return $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $atom;
    }
}

function ago(?string $atom): string
{
    if ($atom === null || $atom === '') {
        return 'n/a';
    }
    try {
        $dt = new DateTimeImmutable($atom);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $dt->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 60) {
            return 'hace ' . $diff . 's';
        }
        if ($diff < 3600) {
            return 'hace ' . (int)floor($diff / 60) . 'm';
        }
        if ($diff < 86400) {
            return 'hace ' . (int)floor($diff / 3600) . 'h';
        }
        return 'hace ' . (int)floor($diff / 86400) . 'd';
    } catch (Throwable) {
        return 'n/a';
    }
}

function v($x): string
{
    if ($x === null) {
        return '—';
    }
    $s = (string)$x;
    return $s === '' ? '—' : $s;
}

renderHeader(match ($view) {
    'purchases' => 'Compras',
    'events' => 'Eventos',
    'logs' => 'Logs',
    default => 'Dashboard',
});

if ($view === 'purchases') {
    $bybit = new BybitClient(
        (string)($cfg['bybit']['base_url'] ?? 'https://api.bybit.com'),
        '',
        '',
    );
    $symbolTrade = (string)($cfg['symbols']['trade'] ?? 'ETHUSDT');
    $lastPrice = $bybit->tickerLastPrice($symbolTrade);
    $priceFetchedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $rows = $db->fetchAll('SELECT * FROM purchases ORDER BY id DESC LIMIT 200');
    echo '<div class="card">';
    echo '<div class="muted" style="margin-bottom:8px">Ticker ' . h($symbolTrade) . ': <b>' . h($lastPrice === null ? 'n/a' : number_format($lastPrice, 2, '.', '')) . '</b> <span class="muted">(fetch ' . h($priceFetchedAt) . ')</span></div>';
    echo '<div class="table-wrap"><table><thead><tr>';
    echo '<th>ID</th><th>Status</th><th>Created</th><th>Buy USDT</th><th>Buy Px</th><th>Buy Qty</th><th>Target Px</th><th>Last Px</th><th>Δ Px</th><th>Δ %</th><th>Profit</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $p) {
        $id = (int)$p['id'];
        $status = (string)$p['status'];
        $targetPx = $p['sell_price'] !== null ? (float)$p['sell_price'] : null;
        $deltaPx = ($lastPrice !== null && $targetPx !== null) ? ($targetPx - $lastPrice) : null; // USDT per ETH
        $deltaPct = ($lastPrice !== null && $targetPx !== null && $lastPrice > 0) ? ((($targetPx / $lastPrice) - 1.0) * 100.0) : null;

        echo '<tr>';
        echo '<td><a href="/dashboard/?view=purchases#p' . h((string)$id) . '">#' . h((string)$id) . '</a></td>';
        echo '<td><span class="pill ' . h($status) . '">' . h($status) . '</span></td>';
        echo '<td>' . h(fmtDbDt((string)$p['created_at'])) . '</td>';
        echo '<td>' . h(number_format((float)$p['buy_usdt'], 2, '.', '')) . '</td>';
        echo '<td>' . h(v($p['buy_price'] ?? null)) . '</td>';
        echo '<td>' . h(v($p['buy_qty'] ?? null)) . '</td>';
        echo '<td>' . h(v($p['sell_price'] ?? null)) . '</td>';
        echo '<td>' . h($lastPrice === null ? '—' : number_format($lastPrice, 2, '.', '')) . '</td>';
        if ($deltaPx === null) {
            echo '<td>—</td><td>—</td>';
        } else {
            if ($deltaPx <= 0) {
                echo '<td><span class="pill OPEN">ready</span></td><td>0.00%</td>';
            } else {
                echo '<td>' . h(number_format($deltaPx, 2, '.', '')) . ' USDT/ETH</td>';
                echo '<td>' . h($deltaPct === null ? '—' : number_format($deltaPct, 2, '.', '') . '%') . '</td>';
            }
        }
        echo '<td>';
        echo 'profit=' . h(v($p['profit_usdt'] ?? null)) . ' USDT';
        echo '<br><span class="muted">usdc=' . h(v($p['profit_usdc'] ?? null)) . '</span>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';
    echo '<div class="muted" style="margin-top:8px">Nota: la compra pasa de BUYING→OPEN cuando el cron detecta el fill y coloca la LIMIT SELL.</div>';
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
        echo '<td>' . h(fmtDbDt((string)$e['created_at'])) . '</td>';
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
$meta = $db->fetchAll('SELECT k, v FROM meta WHERE k IN ("last_run_finished_at","last_reconcile_finished_at")');
$metaMap = [];
foreach ($meta as $m) {
    $metaMap[(string)$m['k']] = (string)$m['v'];
}
$lastRun = $metaMap['last_run_finished_at'] ?? null;
$lastRecon = $metaMap['last_reconcile_finished_at'] ?? null;
$lastAny = null;
if (is_string($lastRun) && $lastRun !== '') {
    $lastAny = $lastRun;
}
if (is_string($lastRecon) && $lastRecon !== '') {
    if ($lastAny === null) {
        $lastAny = $lastRecon;
    } else {
        try {
            $a = new DateTimeImmutable($lastAny);
            $b = new DateTimeImmutable($lastRecon);
            if ($b > $a) {
                $lastAny = $lastRecon;
            }
        } catch (Throwable) {
            // ignore
        }
    }
}

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
echo '<div class="item"><div class="muted">Última actualización</div><div style="font-size:18px">' . h(ago($lastAny)) . '</div><div class="muted" style="margin-top:2px">' . h(fmtAtomLocal($lastAny)) . '</div></div>';
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
    echo '<td>' . h(fmtDbDt((string)$e['created_at'])) . '</td>';
    echo '<td><code>' . h((string)$e['type']) . '</code></td>';
    echo '<td><code>' . h($payload) . '</code></td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
echo '</div>';

renderFooter();
