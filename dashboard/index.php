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
// Backward-compatible alias (old tab name).
if ($view === 'events') {
    $view = 'moves';
}

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

function agoDbDt(?string $sqliteDt): string
{
    if ($sqliteDt === null || $sqliteDt === '') {
        return 'n/a';
    }
    try {
        $dt = new DateTimeImmutable($sqliteDt . ' UTC');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $dt->getTimestamp();
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

function renderPurchasesTable(array $rows, ?float $lastPrice, string $symbolTrade, string $priceFetchedAt): void
{
    echo '<div class="card">';
    echo '<div class="muted" style="margin-bottom:8px">Ticker ' . h($symbolTrade) . ': <b>' . h($lastPrice === null ? 'n/a' : number_format($lastPrice, 2, '.', '')) . '</b> <span class="muted">(fetch ' . h($priceFetchedAt) . ')</span></div>';
    echo '<div class="table-wrap"><table><thead><tr>';
    echo '<th>ID</th><th>Status</th><th>Created</th><th>Buy USDT</th><th>Buy Px</th><th>Buy Qty</th><th>Target Px</th><th>Last Px</th><th>Δ Px</th><th>Δ %</th><th>Progress</th><th>Profit</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $p) {
        $id = (int)$p['id'];
        $status = (string)$p['status'];

        $targetPx = $p['sell_price'] !== null ? (float)$p['sell_price'] : null;
        $deltaPx = ($lastPrice !== null && $targetPx !== null) ? ($targetPx - $lastPrice) : null; // USDT per ETH
        $deltaPct = ($lastPrice !== null && $targetPx !== null && $lastPrice > 0) ? ((($targetPx / $lastPrice) - 1.0) * 100.0) : null;

        echo '<tr id="p' . h((string)$id) . '">';
        echo '<td><a href="/dashboard/?view=purchases#p' . h((string)$id) . '">#' . h((string)$id) . '</a></td>';
        echo '<td><span class="pill ' . h($status) . '">' . h($status) . '</span></td>';
        echo '<td>' . h(fmtDbDt((string)$p['created_at'])) . '<br><span class="muted">' . h(agoDbDt((string)$p['created_at'])) . '</span></td>';
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

        // Progress bar from buy_price -> sell_price using last price.
        $buyPx = $p['buy_price'] !== null ? (float)$p['buy_price'] : null;
        $sellPx = $p['sell_price'] !== null ? (float)$p['sell_price'] : null;
        $progress = null;
        if ($lastPrice !== null && $buyPx !== null && $sellPx !== null && $sellPx > $buyPx) {
            $progress = ($lastPrice - $buyPx) / ($sellPx - $buyPx);
            if ($progress < 0) {
                $progress = 0.0;
            } elseif ($progress > 1) {
                $progress = 1.0;
            }
        }
        if ($progress === null) {
            echo '<td>—</td>';
        } else {
            $pct = (int)round($progress * 100);
            echo '<td><div class="bar" title="' . h((string)$pct) . '%"><span style="width:' . h((string)$pct) . '%"></span></div><div class="muted" style="margin-top:2px">' . h((string)$pct) . '%</div></td>';
        }

        echo '<td>';
        echo 'profit=' . h(v($p['profit_usdt'] ?? null)) . ' USDT';
        echo '<br><span class="muted">usdc=' . h(v($p['profit_usdc'] ?? null)) . '</span>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div></div>';
}

renderHeader(match ($view) {
    'purchases' => 'Compras',
    'moves' => 'Movimientos',
    'chart' => 'Gráfico',
    'logs' => 'Logs',
    default => 'Dashboard',
});

function eventIsNoise(string $type): bool
{
    return in_array($type, ['RUN_START', 'RUN_FINISH', 'RECONCILE_START', 'RECONCILE_FINISH'], true);
}

function normalizePayloadForDisplay(mixed $value): mixed
{
    if (is_float($value)) {
        return number_format($value, 8, '.', '');
    }
    if (is_int($value) || is_string($value) || $value === null || is_bool($value)) {
        return $value;
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = normalizePayloadForDisplay($v);
        }
        return $out;
    }
    return (string)$value;
}

function renderMovementsTable(Database $db, int $limit = 50): void
{
    $showAll = isset($_GET['all']) && $_GET['all'] === '1';
    $rows = $db->fetchAll('SELECT id, created_at, type, payload_json FROM events_log ORDER BY id DESC LIMIT ' . (int)$limit);

    echo '<div class="muted" style="margin-bottom:8px">';
    echo $showAll
        ? '<a href="/dashboard/?view=moves">Ocultar eventos de tick</a>'
        : '<a href="/dashboard/?view=moves&all=1">Mostrar eventos de tick (RUN/RECONCILE)</a>';
    echo '</div>';

    echo '<div class="card"><div class="muted">Últimos movimientos</div>';
    echo '<table><thead><tr><th>When</th><th>Type</th><th>Detalle</th></tr></thead><tbody>';

    $shown = 0;
    foreach ($rows as $e) {
        $type = (string)$e['type'];
        if (!$showAll && eventIsNoise($type)) {
            continue;
        }

        $payload = (string)$e['payload_json'];
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $decoded = normalizePayloadForDisplay($decoded);
            $payload = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: (string)$e['payload_json'];
        }
        if (strlen($payload) > 240) {
            $payload = substr($payload, 0, 240) . '…';
        }

        echo '<tr>';
        echo '<td>' . h(fmtDbDt((string)$e['created_at'])) . '</td>';
        echo '<td><span class="mono">' . h($type) . '</span></td>';
        echo '<td><span class="mono">' . h($payload) . '</span></td>';
        echo '</tr>';

        $shown++;
        if ($shown >= 200) {
            break;
        }
    }

    echo '</tbody></table></div>';
}

function renderChartCard(Database $db, array $cfg, string $interval = '15', int $limit = 400): void
{
    $bybit = new BybitClient(
        (string)($cfg['bybit']['base_url'] ?? 'https://api.bybit.com'),
        '',
        '',
    );
    $symbol = (string)($cfg['symbols']['trade'] ?? 'ETHUSDT');

    if ($limit < 50) {
        $limit = 50;
    }
    if ($limit > 1000) {
        $limit = 1000;
    }

    $purchases = $db->fetchAll('SELECT * FROM purchases ORDER BY id DESC LIMIT 12');
    $startDt = null;
    foreach ($purchases as $p) {
        $t = (string)($p['buy_filled_at'] ?? $p['created_at'] ?? '');
        if ($t === '') {
            continue;
        }
        try {
            $dt = new DateTimeImmutable($t . ' UTC');
            $startDt = $startDt === null ? $dt : ($dt < $startDt ? $dt : $startDt);
        } catch (Throwable) {
            // ignore
        }
    }
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($startDt === null) {
        $startDt = $nowUtc->sub(new DateInterval('P7D'));
    }
    $minStart = $nowUtc->sub(new DateInterval('P30D'));
    if ($startDt < $minStart) {
        $startDt = $minStart;
    }

    $startMs = (int)($startDt->getTimestamp() * 1000);
    $endMs = (int)($nowUtc->getTimestamp() * 1000);

    $series = $bybit->klines($symbol, $interval, $startMs, $endMs, $limit);

    echo '<div class="card">';
    echo '<div class="muted">Precio ETH vs tiempo</div>';

    if ($series === []) {
        echo '<div class="muted" style="margin-top:8px">No hay datos de kline para ' . h($symbol) . '.</div></div>';
        return;
    }

    $prices = array_map(static fn(array $pt) => (float)$pt[1], $series);
    $minY = min($prices);
    $maxY = max($prices);

    foreach ($purchases as $p) {
        if ($p['buy_price'] !== null) {
            $minY = min($minY, (float)$p['buy_price']);
            $maxY = max($maxY, (float)$p['buy_price']);
        }
        if ($p['sell_price'] !== null) {
            $minY = min($minY, (float)$p['sell_price']);
            $maxY = max($maxY, (float)$p['sell_price']);
        }
    }
    $pad = max(1.0, ($maxY - $minY) * 0.06);
    $minY -= $pad;
    $maxY += $pad;

    $w = 1100;
    $h = 420;
    $pl = 60;
    $pr = 20;
    $pt = 20;
    $pb = 50;
    $innerW = $w - $pl - $pr;
    $innerH = $h - $pt - $pb;

    $x0 = (float)$series[0][0];
    $x1 = (float)$series[count($series) - 1][0];
    if ($x1 <= $x0) {
        $x1 = $x0 + 1;
    }

    $sx = static function (float $ts) use ($x0, $x1, $pl, $innerW): float {
        return $pl + (($ts - $x0) / ($x1 - $x0)) * $innerW;
    };
    $sy = static function (float $price) use ($minY, $maxY, $pt, $innerH): float {
        return $pt + ($maxY - $price) / ($maxY - $minY) * $innerH;
    };

    $points = [];
    foreach ($series as $ptRow) {
        $points[] = $sx((float)$ptRow[0]) . ',' . $sy((float)$ptRow[1]);
    }
    $priceLine = implode(' ', $points);

    $palette = ['#6ea8ff', '#41d18b', '#ffcd57', '#ff6b6b', '#b388ff', '#4dd0e1', '#ff8fab', '#a3e635'];

    echo '<div class="muted" style="margin-top:6px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">';
    echo '<div>Symbol: <code>' . h($symbol) . '</code> | interval: <code>' . h($interval) . '</code> | points: <code>' . h((string)count($series)) . '</code></div>';
    echo '<div>Window: <code>' . h((new DateTimeImmutable('@' . (int)($x0 / 1000)))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i')) . '</code> → <code>' . h((new DateTimeImmutable('@' . (int)($x1 / 1000)))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i')) . '</code></div>';
    echo '</div>';

    echo '<div class="table-wrap" style="margin-top:10px">';
    echo '<svg viewBox="0 0 ' . h((string)$w) . ' ' . h((string)$h) . '" width="100%" height="auto" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Price chart">';
    for ($i = 0; $i <= 4; $i++) {
        $yy = $pt + ($innerH / 4) * $i;
        echo '<line x1="' . h((string)$pl) . '" y1="' . h((string)$yy) . '" x2="' . h((string)($w - $pr)) . '" y2="' . h((string)$yy) . '" stroke="rgba(255,255,255,.06)" />';
    }
    echo '<polyline fill="none" stroke="rgba(159,183,255,.35)" stroke-width="2" points="' . h($priceLine) . '" />';

    $i = 0;
    foreach ($purchases as $p) {
        $id = (int)$p['id'];
        $buyPrice = $p['buy_price'] !== null ? (float)$p['buy_price'] : null;
        $sellPrice = $p['sell_price'] !== null ? (float)$p['sell_price'] : null;
        $tStr = (string)($p['buy_filled_at'] ?? $p['created_at'] ?? '');
        if ($tStr === '' || $buyPrice === null) {
            continue;
        }
        try {
            $buyDt = new DateTimeImmutable($tStr . ' UTC');
        } catch (Throwable) {
            continue;
        }
        $buyMs = (float)($buyDt->getTimestamp() * 1000);
        if ($buyMs < $x0 || $buyMs > $x1) {
            continue;
        }

        $color = $palette[$i % count($palette)];
        $i++;

        $seg = [];
        foreach ($series as $ptRow) {
            if ((float)$ptRow[0] + 1 < $buyMs) {
                continue;
            }
            $seg[] = $sx((float)$ptRow[0]) . ',' . $sy((float)$ptRow[1]);
        }
        if (count($seg) >= 2) {
            echo '<polyline fill="none" stroke="' . h($color) . '" stroke-width="2" opacity="0.85" points="' . h(implode(' ', $seg)) . '" />';
        }

        if ($sellPrice !== null) {
            $yy = $sy($sellPrice);
            // Sell target line (solid).
            echo '<line x1="' . h((string)$pl) . '" y1="' . h((string)$yy) . '" x2="' . h((string)($w - $pr)) . '" y2="' . h((string)$yy) . '" stroke="' . h($color) . '" stroke-width="1.6" opacity="0.85" />';
        }

        // Buy price reference (very subtle).
        $by = $sy($buyPrice);
        echo '<line x1="' . h((string)$pl) . '" y1="' . h((string)$by) . '" x2="' . h((string)($w - $pr)) . '" y2="' . h((string)$by) . '" stroke="' . h($color) . '" stroke-width="1" opacity="0.18" />';

        $cx = $sx($buyMs);
        $cy = $sy($buyPrice);
        echo '<circle cx="' . h((string)$cx) . '" cy="' . h((string)$cy) . '" r="4" fill="' . h($color) . '" />';
        echo '<text x="' . h((string)($cx + 6)) . '" y="' . h((string)($cy - 6)) . '" fill="' . h($color) . '" font-size="12">#' . h((string)$id) . '</text>';
    }

    echo '<text x="' . h((string)10) . '" y="' . h((string)($pt + 12)) . '" fill="rgba(255,255,255,.6)" font-size="12">' . h(number_format($maxY, 2, '.', '')) . '</text>';
    echo '<text x="' . h((string)10) . '" y="' . h((string)($pt + $innerH)) . '" fill="rgba(255,255,255,.6)" font-size="12">' . h(number_format($minY, 2, '.', '')) . '</text>';

    echo '</svg></div>';
    echo '<div class="muted" style="margin-top:10px">Línea azul = precio | color = evolución desde compra | punteada = target de venta.</div>';
    echo '</div>';
}

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
    renderPurchasesTable($rows, $lastPrice, $symbolTrade, $priceFetchedAt);
    echo '<div class="muted" style="margin-top:8px">Nota: la compra pasa de BUYING→OPEN cuando el cron detecta el fill y coloca la LIMIT SELL.</div>';
    renderFooter();
    exit;
}

if ($view === 'moves') {
    renderMovementsTable($db, 300);
    renderFooter();
    exit;
}

if ($view === 'chart') {
    $interval = (string)($_GET['interval'] ?? '15'); // minutes
    $limit = (int)($_GET['limit'] ?? 400);
    renderChartCard($db, $cfg, $interval, $limit);

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

renderChartCard($db, $cfg, '15', 400);

// Purchases box on home (same as Purchases view, limited rows).
$bybitHome = new BybitClient(
    (string)($cfg['bybit']['base_url'] ?? 'https://api.bybit.com'),
    '',
    '',
);
$symbolTradeHome = (string)($cfg['symbols']['trade'] ?? 'ETHUSDT');
$lastPriceHome = $bybitHome->tickerLastPrice($symbolTradeHome);
$priceFetchedAtHome = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$rowsHome = $db->fetchAll('SELECT * FROM purchases ORDER BY id DESC LIMIT 50');
renderPurchasesTable($rowsHome, $lastPriceHome, $symbolTradeHome, $priceFetchedAtHome);
echo '</div>';

renderFooter();
