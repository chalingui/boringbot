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

function tradeSymbols(array $cfg): array
{
    $symbols = [
        (string)($cfg['symbols']['trade'] ?? 'ETHUSDT'),
        (string)($cfg['symbols']['trade_btc'] ?? 'BTCUSDT'),
    ];
    $out = [];
    foreach ($symbols as $sym) {
        $sym = strtoupper(trim($sym));
        if ($sym === '') {
            continue;
        }
        $out[] = $sym;
    }
    return array_values(array_unique($out));
}

function lastPricesForSymbols(BybitClient $bybit, array $symbols): array
{
    $out = [];
    foreach ($symbols as $symbol) {
        $out[$symbol] = $bybit->tickerLastPrice($symbol);
    }
    return $out;
}

function renderPurchasesTable(array $rows, array $lastPrices, string $priceFetchedAt): void
{
    echo '<div class="card">';
    if ($lastPrices === []) {
        echo '<div class="muted" style="margin-bottom:8px">Ticker n/a <span class="muted">(fetch ' . h($priceFetchedAt) . ')</span></div>';
    } else {
        $tickerBits = [];
        foreach ($lastPrices as $sym => $price) {
            $tickerBits[] = h($sym) . ': <b>' . h($price === null ? 'n/a' : number_format((float)$price, 2, '.', '')) . '</b>';
        }
        echo '<div class="muted" style="margin-bottom:8px">Tickers ' . implode(' | ', $tickerBits) . ' <span class="muted">(fetch ' . h($priceFetchedAt) . ')</span></div>';
    }
    echo '<div class="table-wrap"><table><thead><tr>';
    echo '<th>ID</th><th>Symbol</th><th>Status</th><th>Created</th><th>Duration</th><th>Buy USDT</th><th>Buy Px</th><th>Buy Qty</th><th>Target Px</th><th>Sell Avg Px</th><th>Last Px</th><th>Δ Px</th><th>Progress</th><th>Profit</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $p) {
        $id = (int)$p['id'];
        $status = (string)$p['status'];
        $symbol = (string)($p['symbol'] ?? '');
        if ($symbol === '') {
            $symbol = 'ETHUSDT';
        }
        $baseAsset = str_ends_with($symbol, 'USDT') ? substr($symbol, 0, -4) : $symbol;
        $lastPrice = $lastPrices[$symbol] ?? null;
        $colorClass = $status === 'SOLD' ? '' : ('pcolor-' . ($id % 6));

        $targetPx = $p['sell_price'] !== null ? (float)$p['sell_price'] : null;
        $deltaPx = null;
        if ($status !== 'SOLD' && $lastPrice !== null && $targetPx !== null) {
            $deltaPx = $targetPx - $lastPrice; // USDT per base asset
        }
        $sellQty = $p['sell_qty'] !== null ? (float)$p['sell_qty'] : null;
        $sellUsdt = $p['sell_usdt'] !== null ? (float)$p['sell_usdt'] : null;
        $sellAvgPx = ($sellQty !== null && $sellUsdt !== null && $sellQty > 0) ? ($sellUsdt / $sellQty) : null;

        $buyTs = $p['buy_filled_at'] ?? $p['created_at'] ?? null;
        $sellTs = $status === 'SOLD' ? ($p['sell_filled_at'] ?? null) : null;
        $durationLabel = '—';
        $buySec = parseDbUtcSeconds(is_string($buyTs) ? $buyTs : null);
        if ($buySec !== null) {
            if ($status === 'SOLD') {
                $sellSec = parseDbUtcSeconds(is_string($sellTs) ? $sellTs : null);
                if ($sellSec !== null) {
                    $durationLabel = fmtDurationSeconds(max(0, $sellSec - $buySec));
                }
            } else {
                $nowSec = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
                $durationLabel = fmtDurationSeconds(max(0, $nowSec - $buySec));
            }
        }

        echo '<tr id="p' . h((string)$id) . '" class="purchase-row ' . h($colorClass) . '">';
        echo '<td><a class="purchase-id" href="' . h(dashUrl('?view=purchases')) . '#p' . h((string)$id) . '">#' . h((string)$id) . '</a></td>';
        echo '<td>' . h($symbol) . '</td>';
        echo '<td><span class="pill ' . h($status) . '">' . h($status) . '</span></td>';
        echo '<td>' . h(fmtDbDt((string)$p['created_at'])) . '<br><span class="muted">' . h(agoDbDt((string)$p['created_at'])) . '</span></td>';
        echo '<td>' . h($durationLabel) . '</td>';
        echo '<td>' . h(number_format((float)$p['buy_usdt'], 2, '.', '')) . '</td>';
        echo '<td>' . h(v($p['buy_price'] ?? null)) . '</td>';
        echo '<td>' . h(v($p['buy_qty'] ?? null)) . '</td>';
        echo '<td>' . h($targetPx === null ? '—' : number_format($targetPx, 2, '.', '')) . '</td>';
        echo '<td>' . h($sellAvgPx === null ? '—' : number_format($sellAvgPx, 2, '.', '')) . '</td>';
        if ($status === 'SOLD') {
            echo '<td>—</td>';
        } else {
            echo '<td>' . h($lastPrice === null ? '—' : number_format($lastPrice, 2, '.', '')) . '</td>';
        }

        if ($deltaPx === null) {
            echo '<td>—</td>';
        } else {
            if ($deltaPx <= 0) {
                echo '<td><span class="pill OPEN">ready</span></td>';
            } else {
                echo '<td>' . h(number_format($deltaPx, 2, '.', '')) . ' USDT/' . h($baseAsset) . '</td>';
            }
        }

        // Progress bar from buy_price -> sell_price using last price.
        $buyPx = $p['buy_price'] !== null ? (float)$p['buy_price'] : null;
        $sellPx = $p['sell_price'] !== null ? (float)$p['sell_price'] : null;
        $progress = null;
        if ($status === 'SOLD') {
            $progress = 1.0;
        } elseif ($lastPrice !== null && $buyPx !== null && $sellPx !== null && $sellPx > $buyPx) {
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

        $profitUsdt = $p['profit_usdt'] !== null ? (float)$p['profit_usdt'] : null;
        echo '<td>' . h($profitUsdt === null ? '—' : number_format($profitUsdt, 2, '.', '') . ' USDT') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div></div>';
}

renderHeader(match ($view) {
    'purchases' => 'Compras',
    'moves' => 'Movimientos',
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
        ? '<a href="' . h(dashUrl('?view=moves')) . '">Ocultar eventos de tick</a>'
        : '<a href="' . h(dashUrl('?view=moves&all=1')) . '">Mostrar eventos de tick (RUN/RECONCILE)</a>';
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
            $payload = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: (string)$e['payload_json'];
        }
        if (strlen($payload) > 1200) {
            $payload = substr($payload, 0, 1200) . "\n…";
        }

        echo '<tr>';
        echo '<td>' . h(fmtDbDt((string)$e['created_at'])) . '</td>';
        echo '<td><span class="mono">' . h($type) . '</span></td>';
        echo '<td><pre class="mono" style="margin:0;max-height:180px;overflow:auto">' . h($payload) . '</pre></td>';
        echo '</tr>';

        $shown++;
        if ($shown >= 200) {
            break;
        }
    }

    echo '</tbody></table></div>';
}

function renderChartCard(Database $db, array $cfg, string $symbol, string $interval = '15', int $limit = 400): void
{
    $bybit = new BybitClient(
        (string)($cfg['bybit']['base_url'] ?? 'https://api.bybit.com'),
        '',
        '',
    );
    $baseAsset = str_ends_with($symbol, 'USDT') ? substr($symbol, 0, -4) : $symbol;

    if ($limit < 50) {
        $limit = 50;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    $purchases = $db->fetchAll(
        'SELECT * FROM purchases WHERE symbol = :sym AND status IN ("BUYING","HOLDING","OPEN","NEEDS_FUNDS") AND buy_price IS NOT NULL ORDER BY id DESC',
        [':sym' => $symbol]
    );
    $hasOpenPurchases = $purchases !== [];
    if (!$hasOpenPurchases) {
        $purchases = $db->fetchAll(
            'SELECT * FROM purchases WHERE symbol = :sym AND buy_price IS NOT NULL ORDER BY id DESC LIMIT 50',
            [':sym' => $symbol]
        );
    }
    $lastSold = null;
    if (!$hasOpenPurchases) {
        $lastSold = $db->fetchOne(
            'SELECT * FROM purchases WHERE symbol = :sym AND status = "SOLD" AND sell_price IS NOT NULL ORDER BY COALESCE(sell_filled_at, created_at) DESC, id DESC LIMIT 1',
            [':sym' => $symbol]
        );
        if (!is_array($lastSold)) {
            $lastSold = null;
        }
    }
    $primaryPurchase = $db->fetchOne(
        'SELECT * FROM purchases WHERE symbol = :sym AND status IN ("BUYING","HOLDING","OPEN","NEEDS_FUNDS") AND buy_price IS NOT NULL ORDER BY id DESC LIMIT 1',
        [':sym' => $symbol]
    );
    if (!is_array($primaryPurchase)) {
        $primaryPurchase = $db->fetchOne(
            'SELECT * FROM purchases WHERE symbol = :sym AND buy_price IS NOT NULL ORDER BY id DESC LIMIT 1',
            [':sym' => $symbol]
        );
    }

    $startDt = null;
    $primaryBuyMs = null;
    $primaryId = null;
    $openLatest = $db->fetchOne(
        'SELECT COALESCE(buy_filled_at, created_at) AS last_at
         FROM purchases
         WHERE symbol = :sym AND status IN ("BUYING","HOLDING","OPEN","NEEDS_FUNDS")
         ORDER BY id DESC
         LIMIT 1',
        [':sym' => $symbol]
    );
    if (is_array($openLatest) && ($openLatest['last_at'] ?? '') !== '') {
        try {
            $startDt = new DateTimeImmutable((string)$openLatest['last_at'] . ' UTC');
        } catch (Throwable) {
            $startDt = null;
        }
    }
    if (is_array($primaryPurchase)) {
        $primaryId = isset($primaryPurchase['id']) ? (int)$primaryPurchase['id'] : null;
        $t = (string)($primaryPurchase['buy_filled_at'] ?? $primaryPurchase['created_at'] ?? '');
        if ($t !== '') {
            try {
                $primaryDt = new DateTimeImmutable($t . ' UTC');
                $primaryBuyMs = (float)($primaryDt->getTimestamp() * 1000);
            } catch (Throwable) {
                // ignore
            }
        }
    }
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($startDt === null) {
        $startDt = $nowUtc->sub(new DateInterval('P7D'));
    }

    if ($startDt === null) {
        $latestAny = $db->fetchOne(
            'SELECT COALESCE(buy_filled_at, created_at) AS last_at
             FROM purchases
             WHERE symbol = :sym
             ORDER BY id DESC
             LIMIT 1',
            [':sym' => $symbol]
        );
        if (is_array($latestAny) && ($latestAny['last_at'] ?? '') !== '') {
            try {
                $startDt = new DateTimeImmutable((string)$latestAny['last_at'] . ' UTC');
            } catch (Throwable) {
                $startDt = null;
            }
        }
    }

    $startMs = (int)($startDt->getTimestamp() * 1000);
    $endMs = (int)($nowUtc->getTimestamp() * 1000);

    $intervalOriginal = $interval;
    $spanMinutes = max(1.0, ($endMs - $startMs) / 60000.0);
    $intervalMinutes = null;
    if ($interval === 'D' || $interval === 'd') {
        $intervalMinutes = 1440;
    } elseif (ctype_digit($interval)) {
        $intervalMinutes = (int)$interval;
    }

    $allowedIntervalsMinutes = [1, 3, 5, 15, 30, 60, 120, 240, 360, 720, 1440];
    if ($intervalMinutes !== null) {
        $needPoints = (int)ceil($spanMinutes / max(1, $intervalMinutes));
        if ($needPoints > $limit) {
            foreach ($allowedIntervalsMinutes as $m) {
                if (ceil($spanMinutes / $m) <= $limit) {
                    $intervalMinutes = $m;
                    break;
                }
            }
            $interval = $intervalMinutes >= 1440 ? 'D' : (string)$intervalMinutes;
        }
    }

    $seriesEndMs = $endMs;
    $series = $bybit->klines($symbol, $interval, $startMs, $seriesEndMs, $limit);
    if ($series === []) {
        $fallbackStart = $seriesEndMs - (7 * 24 * 60 * 60 * 1000);
        if ($fallbackStart < $endMs) {
            $series = $bybit->klines($symbol, $interval, $fallbackStart, $seriesEndMs, min(200, $limit));
        }
    }

    echo '<div class="card">';
    echo '<div class="muted">Precio ' . h($baseAsset) . ' vs tiempo</div>';

    if ($series === []) {
        echo '<div class="muted" style="margin-top:8px">No hay datos de kline para ' . h($symbol) . ' (usando compras para el rango).</div>';
    }

    $minY = null;
    $maxY = null;

    $purchasesAxis = $db->fetchAll(
        'SELECT buy_price, sell_price FROM purchases WHERE symbol = :sym AND buy_price IS NOT NULL',
        [':sym' => $symbol]
    );
    $purchasesSorted = $purchases;
    usort($purchasesSorted, static fn(array $a, array $b) => ((int)$b['id']) <=> ((int)$a['id']));
    if (!$hasOpenPurchases) {
        $purchasesSorted = [];
    }

    foreach ($purchasesAxis as $p) {
        if ($p['buy_price'] !== null) {
            $minY = $minY === null ? (float)$p['buy_price'] : min($minY, (float)$p['buy_price']);
            $maxY = $maxY === null ? (float)$p['buy_price'] : max($maxY, (float)$p['buy_price']);
        }
        if ($p['sell_price'] !== null) {
            $minY = $minY === null ? (float)$p['sell_price'] : min($minY, (float)$p['sell_price']);
            $maxY = $maxY === null ? (float)$p['sell_price'] : max($maxY, (float)$p['sell_price']);
        }
    }
    if (!$hasOpenPurchases && $lastSold !== null && $lastSold['sell_price'] !== null) {
        $minY = $minY === null ? (float)$lastSold['sell_price'] : min($minY, (float)$lastSold['sell_price']);
        $maxY = $maxY === null ? (float)$lastSold['sell_price'] : max($maxY, (float)$lastSold['sell_price']);
    }
    if (($minY === null || $maxY === null) && $series !== []) {
        $prices = array_map(static fn(array $pt) => (float)$pt[1], $series);
        $minY = min($prices);
        $maxY = max($prices);
    }
    if ($minY === null || $maxY === null) {
        $minY = 0.0;
        $maxY = 1.0;
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

    // Next DCA marker (based on last purchase created_at + interval days, like the bot does).
    $nextBuyMs = null;
    $nextBuyLocal = null;
    $lastDca = $db->fetchOne('SELECT v FROM meta WHERE k = "last_dca_at"');
    $latest = $db->fetchOne('SELECT created_at FROM purchases ORDER BY id DESC LIMIT 1');
    $lastDcaAt = is_array($lastDca) && isset($lastDca['v']) ? (string)$lastDca['v'] : null;
    if (($lastDcaAt !== null && $lastDcaAt !== '') || (is_array($latest) && isset($latest['created_at']))) {
        $days = (int)($cfg['strategy']['dca_interval_days'] ?? 7);
        if ($days > 0) {
            try {
                $lastSource = ($lastDcaAt !== null && $lastDcaAt !== '') ? $lastDcaAt : (string)$latest['created_at'];
                $last = new DateTimeImmutable($lastSource . ' UTC');
                $dueAt = $last->add(new DateInterval('P' . $days . 'D'));
                $offsetHours = (int)($cfg['strategy']['dca_offset_hours'] ?? 0);
                if ($offsetHours !== 0) {
                    $offset = new DateInterval('PT' . abs($offsetHours) . 'H');
                    $dueAt = $offsetHours > 0 ? $dueAt->add($offset) : $dueAt->sub($offset);
                }
                $nextBuyMs = (float)($dueAt->getTimestamp() * 1000);
                $nextBuyLocal = $dueAt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i');
            } catch (Throwable) {
                $nextBuyMs = null;
                $nextBuyLocal = null;
            }
        }
    }

    $x0 = $series !== [] ? (float)$series[0][0] : (float)$startMs;
    $x1 = $series !== [] ? (float)$series[count($series) - 1][0] : (float)$endMs;
    if ($nextBuyMs !== null) {
        $x1 = max($x1, $nextBuyMs);
    }
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

    // Purchase overlays: keep colors consistent with table accents.
    $palette = ['#f4b6b2', '#bde5b8', '#ffe3a3', '#f6c6d7', '#d9c7f7', '#ffd3b6'];

    // Extra chart (Chart.js) for clearer stacked sell lines.
    static $chartJsIncluded = false;
    if (!$chartJsIncluded) {
        echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>';
        $chartJsIncluded = true;
    }
    $chartSeries = [];
    foreach ($series as $ptRow) {
        $chartSeries[] = ['x' => (float)$ptRow[0], 'y' => (float)$ptRow[1]];
    }
    $chartBuyLines = [];
    $chartSellLines = [];
    $chartMarkers = [];
    $chartOpenLines = [];
    $lastPurchaseMs = null;
    $openStartMs = null;
    $maxPurchaseId = null;
    if ($hasOpenPurchases) {
        $sortedForChart = $purchasesSorted;
        usort($sortedForChart, static fn(array $a, array $b) => ((int)$a['id']) <=> ((int)$b['id']));
        foreach ($sortedForChart as $pRow) {
            $id = (int)$pRow['id'];
            $tStr = (string)($pRow['buy_filled_at'] ?? $pRow['created_at'] ?? '');
            if ($tStr === '') {
                continue;
            }
            try {
                $dt = new DateTimeImmutable($tStr . ' UTC');
            } catch (Throwable) {
                continue;
            }
            $ms = (float)($dt->getTimestamp() * 1000);
            $chartMarkers[] = ['id' => $id, 'ms' => $ms, 'color' => $palette[$id % count($palette)]];
            if ($openStartMs === null || $ms < $openStartMs) {
                $openStartMs = $ms;
            }
            if ($lastPurchaseMs === null || $ms > $lastPurchaseMs) {
                $lastPurchaseMs = $ms;
            }
            if ($maxPurchaseId === null || $id > $maxPurchaseId) {
                $maxPurchaseId = $id;
            }
        }
        foreach ($purchasesSorted as $pRow) {
            $id = (int)$pRow['id'];
            $color = $palette[$id % count($palette)];
            $buyPx = $pRow['buy_price'] !== null ? (float)$pRow['buy_price'] : null;
            $sellPx = $pRow['sell_price'] !== null ? (float)$pRow['sell_price'] : null;
            $status = (string)($pRow['status'] ?? '');
            $tStr = (string)($pRow['buy_filled_at'] ?? $pRow['created_at'] ?? '');
            $buyMs = null;
            if ($tStr !== '') {
                try {
                    $buyMs = (float)((new DateTimeImmutable($tStr . ' UTC'))->getTimestamp() * 1000);
                } catch (Throwable) {
                    $buyMs = null;
                }
            }
            if ($buyPx !== null) {
                $chartBuyLines[] = ['id' => $id, 'price' => $buyPx, 'color' => $color, 'ms' => $buyMs];
            }
            if ($sellPx !== null && in_array($status, ['OPEN', 'HOLDING', 'NEEDS_FUNDS'], true)) {
                $chartSellLines[] = ['id' => $id, 'price' => $sellPx, 'color' => $color, 'ms' => $buyMs];
                $chartOpenLines[] = ['id' => $id, 'color' => $color];
            }
        }
    }
    if (!$hasOpenPurchases && $lastSold !== null) {
        $soldId = isset($lastSold['id']) ? (int)$lastSold['id'] : 0;
        $soldColor = $palette[$soldId % count($palette)];
        $soldPx = $lastSold['sell_price'] !== null ? (float)$lastSold['sell_price'] : null;
        $soldPxLabel = $soldPx === null ? null : number_format($soldPx, 2, '.', '');
        $soldMs = null;
        $soldTs = (string)($lastSold['sell_filled_at'] ?? $lastSold['created_at'] ?? '');
        if ($soldTs !== '') {
            try {
                $soldMs = (float)((new DateTimeImmutable($soldTs . ' UTC'))->getTimestamp() * 1000);
            } catch (Throwable) {
                $soldMs = null;
            }
        }
        if ($soldPx !== null) {
            $chartSellLines[] = [
                'id' => $soldId,
                'price' => $soldPx,
                'color' => $soldColor,
                'ms' => $soldMs,
                'labelText' => 'Venta #' . $soldId . ' @ ' . $soldPxLabel,
                'labelMs' => $soldMs,
            ];
        }
    }
    $chartSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($symbol));
    $chartId = 'chartjs-' . trim((string)$chartSlug, '-');
    $xMin = (float)$startMs;
    $xMax = $nextBuyMs !== null ? $nextBuyMs : (float)$endMs;
    if ($xMax <= $xMin) {
        $xMax = $xMin + 1;
    }
    echo '<div style="margin-bottom:8px">';
    echo '<canvas id="' . h($chartId) . '" height="200"></canvas>';
    echo '<script>
      (function(){
        const ctx = document.getElementById("' . h($chartId) . '").getContext("2d");
        const priceData = ' . json_encode($chartSeries, JSON_UNESCAPED_SLASHES) . ';
        const buys = ' . json_encode($chartBuyLines, JSON_UNESCAPED_SLASHES) . ';
        const sells = ' . json_encode($chartSellLines, JSON_UNESCAPED_SLASHES) . ';
        const openLines = ' . json_encode($chartOpenLines, JSON_UNESCAPED_SLASHES) . ';
        const xMin = ' . json_encode($xMin) . ';
        const xMax = ' . json_encode($xMax) . ';
        const markers = ' . json_encode($chartMarkers, JSON_UNESCAPED_SLASHES) . ';
        const lastBuyMs = ' . json_encode($lastPurchaseMs) . ';
        const nextBuy = ' . json_encode($nextBuyMs) . ';
        const nextBuyColor = ' . json_encode($palette[(($maxPurchaseId ?? 0) + 1) % count($palette)]) . ';
        const datasets = [];
        const sortedMarkers = markers.slice().sort((a,b)=>a.ms-b.ms);
        const priceSegments = [];
        if (sortedMarkers.length === 0) {
          priceSegments.push({label:"Price", data: priceData, color:"rgba(159,183,255,0.7)"});
        } else {
          for (let i = 0; i < sortedMarkers.length; i++) {
            const start = sortedMarkers[i].ms;
            const end = (i + 1 < sortedMarkers.length) ? sortedMarkers[i + 1].ms : xMax;
            const seg = priceData.filter(p => p.x >= start && p.x <= end);
            if (seg.length < 2) continue;
            priceSegments.push({label: "Price #"+sortedMarkers[i].id, data: seg, color: sortedMarkers[i].color});
          }
        }
        if (openLines.length > 0) {
          const offsets = [-1.5, 1.5, -3.0, 3.0];
          const openSorted = openLines.slice().sort((a, b) => a.id - b.id);
          openSorted.forEach((o, i) => {
            const off = offsets[i % offsets.length];
            priceSegments.forEach((seg) => {
              const data = seg.data.map((p) => {
                if (lastBuyMs !== null && p.x >= lastBuyMs) {
                  return {x: p.x, y: p.y + off};
                }
                return {x: p.x, y: p.y};
              });
              datasets.push({
                label: "#"+o.id+" price",
                data,
                borderColor: o.color,
                backgroundColor: "transparent",
                borderWidth: 1.6,
                pointRadius: 0,
                tension: 0,
              });
            });
          });
        } else {
          priceSegments.forEach((seg) => {
            datasets.push({
              label: seg.label,
              data: seg.data,
              borderColor: seg.color,
              backgroundColor: "transparent",
              borderWidth: 2,
              pointRadius: 0,
              tension: 0,
            });
          });
        }
        buys.forEach((b) => {
            datasets.push({
              label: "#"+b.id+" buy",
              data: [{x: b.ms || xMin, y: b.price}, {x: xMax, y: b.price}],
              borderColor: b.color,
              borderWidth: 1.2,
              borderDash: [4,4],
              pointRadius: 0,
              fill: false,
              tension: 0,
              _labelText: "#"+b.id,
              _labelColor: b.color,
              _labelX: b.ms || xMin,
              _labelY: b.price,
            });
        });
        markers.forEach((m) => {
          datasets.push({
            label: "#"+m.id+" time",
            data: [{x: m.ms, y: 0}, {x: m.ms, y: 1}],
            borderColor: m.color,
            borderWidth: 1,
            borderDash: [4,4],
            pointRadius: 0,
            fill: false,
            tension: 0,
            yAxisID: "yLine",
          });
        });
        if (nextBuy) {
          datasets.push({
            label: "next buy",
            data: [{x: nextBuy, y: 0}, {x: nextBuy, y: 1}],
            borderColor: nextBuyColor,
            borderWidth: 1,
            borderDash: [4,4],
            pointRadius: 0,
            fill: false,
            tension: 0,
            yAxisID: "yLine",
          });
        }
        sells.forEach((s, idx) => {
          const offsets = [0, -8, 8, -16, 16, -24, 24, -32, 32];
          const off = offsets[idx % offsets.length];
          const y = s.price + 0 * off; // use pixel offset via segment styles instead
          datasets.push({
            label: "#"+s.id+" sell",
            data: [{x: s.ms || xMin, y: s.price}, {x: xMax, y: s.price}],
            borderColor: s.color,
            borderWidth: 1.0,
            pointRadius: 0,
            fill: false,
            tension: 0,
            _labelText: s.labelText || null,
            _labelColor: s.color,
            _labelX: (s.labelMs || s.ms || xMax),
            _labelY: s.price,
          });
        });
        const labelPlugin = {
          id: "lineLabels",
          afterDatasetsDraw(chart) {
            const {ctx} = chart;
            chart.data.datasets.forEach((ds, i) => {
              if (!ds._labelText) return;
              const meta = chart.getDatasetMeta(i);
              if (!meta || meta.data.length === 0) return;
              let pt = null;
              if (ds._labelX !== undefined && ds._labelY !== undefined) {
                pt = chart.scales.x && chart.scales.y
                  ? {x: chart.scales.x.getPixelForValue(ds._labelX), y: chart.scales.y.getPixelForValue(ds._labelY)}
                  : null;
              } else {
                pt = meta.data[meta.data.length - 1];
              }
              if (!pt) return;
              ctx.save();
              ctx.fillStyle = ds._labelColor || "#9aa7d6";
              ctx.font = "11px system-ui,-apple-system,Segoe UI,Roboto";
              ctx.textAlign = "left";
              ctx.textBaseline = "middle";
              const text = String(ds._labelText);
              const pad = 3;
              const metrics = ctx.measureText(text);
              const textW = metrics.width;
              const textH = 12;
              const x = pt.x + 6;
              const y = pt.y;
              ctx.fillStyle = "rgba(11,16,32,0.8)";
              ctx.fillRect(x - pad, y - textH / 2 - pad, textW + pad * 2, textH + pad * 2);
              ctx.fillStyle = ds._labelColor || "#9aa7d6";
              ctx.fillText(text, x, y);
              ctx.restore();
            });
          }
        };

        new Chart(ctx, {
          type: "line",
          data: { datasets },
          options: {
            animation: false,
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            scales: {
              x: {
                type: "linear",
                min: xMin,
                max: xMax,
                ticks: {
                  callback: (val) => {
                    const d = new Date(val);
                    return d.toLocaleDateString(undefined,{month:"short",day:"numeric"});
                  }
                },
              },
              y: {
                ticks: { callback: (v) => v.toFixed(0) }
              },
              yLine: {
                display: false,
                min: 0,
                max: 1,
              }
            },
            interaction: { mode: "nearest", intersect: false },
            elements: { line: { cubicInterpolationMode: "monotone" } }
          },
          plugins: [labelPlugin]
        });
      })();
    </script>';
    echo '</div>';
    echo '</div>';

}

function parseDbUtcSeconds(?string $ts): ?int
{
    if ($ts === null || $ts === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($ts . ' UTC');
    } catch (Throwable) {
        return null;
    }
    return $dt->getTimestamp();
}

function fmtDurationSeconds(int $seconds): string
{
    if ($seconds <= 0) {
        return '0s';
    }
    $days = intdiv($seconds, 86400);
    $seconds -= $days * 86400;
    $hours = intdiv($seconds, 3600);
    $seconds -= $hours * 3600;
    $mins = intdiv($seconds, 60);
    if ($days > 0) {
        return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
        return $hours . 'h ' . $mins . 'm';
    }
    if ($mins > 0) {
        return $mins . 'm';
    }
    return $seconds . 's';
}

if ($view === 'purchases') {
    $bybit = new BybitClient(
        (string)($cfg['bybit']['base_url'] ?? 'https://api.bybit.com'),
        '',
        '',
    );
    $symbols = tradeSymbols($cfg);
    $lastPrices = lastPricesForSymbols($bybit, $symbols);
    $priceFetchedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $rows = $db->fetchAll('SELECT * FROM purchases ORDER BY id DESC LIMIT 200');
    renderPurchasesTable($rows, $lastPrices, $priceFetchedAt);
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
    // Backward-compat: chart is now shown on home dashboard.
    header('Location: ' . dashUrl(), true, 302);
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
$open = $db->fetchOne('SELECT COUNT(1) as c FROM purchases WHERE status IN ("BUYING","HOLDING","OPEN","NEEDS_FUNDS")');
$sold = $db->fetchOne('SELECT COUNT(1) as c FROM purchases WHERE status = "SOLD"');
$profit = $db->fetchOne('SELECT COALESCE(SUM(profit_usdt), 0) as p FROM purchases WHERE profit_usdt IS NOT NULL');
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

$nextBuyLocal = null;
$nextBuyIn = null;
$lastDcaRow = $db->fetchOne('SELECT v FROM meta WHERE k = "last_dca_at"');
$latest = $db->fetchOne('SELECT created_at FROM purchases ORDER BY id DESC LIMIT 1');
$lastBuy = null;
$lastBuyLocal = null;
$lastBuyAgo = null;
$lastDcaAt = is_array($lastDcaRow) && isset($lastDcaRow['v']) ? (string)$lastDcaRow['v'] : null;
if (($lastDcaAt !== null && $lastDcaAt !== '') || (is_array($latest) && isset($latest['created_at']))) {
    $lastBuy = ($lastDcaAt !== null && $lastDcaAt !== '') ? $lastDcaAt : (string)$latest['created_at'];
    $lastBuyAgo = agoDbDt($lastBuy);
    $lastBuyLocal = fmtDbDt($lastBuy);
    if ($lastBuyLocal !== '') {
        try {
            $lastBuyLocal = (new DateTimeImmutable($lastBuy . ' UTC'))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i');
        } catch (Throwable) {
            // keep fallback formatting
        }
    }
    $days = (int)($cfg['strategy']['dca_interval_days'] ?? 7);
    if ($days > 0) {
        try {
            $last = new DateTimeImmutable($lastBuy . ' UTC');
            $dueAt = $last->add(new DateInterval('P' . $days . 'D'));
            $offsetHours = (int)($cfg['strategy']['dca_offset_hours'] ?? 0);
            if ($offsetHours !== 0) {
                $offset = new DateInterval('PT' . abs($offsetHours) . 'H');
                $dueAt = $offsetHours > 0 ? $dueAt->add($offset) : $dueAt->sub($offset);
            }
            $nextBuyLocal = $dueAt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i');
            $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $secondsUntil = $dueAt->getTimestamp() - $nowUtc->getTimestamp();
            if ($secondsUntil < 0) {
                $secondsUntil = 0;
            }
            $nextBuyIn = 'en ' . fmtDurationSeconds((int)$secondsUntil);
        } catch (Throwable) {
            $nextBuyLocal = null;
            $nextBuyIn = null;
        }
    }
}

$symbols = tradeSymbols($cfg);
$balanceMap = [];
foreach ($balances as $b) {
    $balanceMap[(string)$b['asset']] = (float)$b['amount'];
}
$bybitHome = new BybitClient(
    (string)($cfg['bybit']['base_url'] ?? 'https://api.bybit.com'),
    '',
    '',
);
$lastPricesHome = lastPricesForSymbols($bybitHome, $symbols);
$symbolStats = [];
foreach ($symbols as $symbol) {
    $symbolOpen = $db->fetchOne(
        'SELECT COUNT(1) as c FROM purchases WHERE symbol = :sym AND status IN ("BUYING","HOLDING","OPEN","NEEDS_FUNDS")',
        [':sym' => $symbol]
    );
    $symbolSold = $db->fetchOne(
        'SELECT COUNT(1) as c FROM purchases WHERE symbol = :sym AND status = "SOLD"',
        [':sym' => $symbol]
    );
    $symbolLatest = $db->fetchOne(
        'SELECT created_at FROM purchases WHERE symbol = :sym ORDER BY id DESC LIMIT 1',
        [':sym' => $symbol]
    );
    $symbolLastBuy = null;
    $symbolLastBuyLocal = null;
    $symbolLastBuyAgo = null;
    if (is_array($symbolLatest) && isset($symbolLatest['created_at'])) {
        $symbolLastBuy = (string)$symbolLatest['created_at'];
        $symbolLastBuyAgo = agoDbDt($symbolLastBuy);
        $symbolLastBuyLocal = fmtDbDt($symbolLastBuy);
        if ($symbolLastBuyLocal !== '') {
            try {
                $symbolLastBuyLocal = (new DateTimeImmutable($symbolLastBuy . ' UTC'))
                    ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i');
            } catch (Throwable) {
                // keep fallback formatting
            }
        }
    }
    $symbolStats[$symbol] = [
        'open' => (string)($symbolOpen['c'] ?? '0'),
        'sold' => (string)($symbolSold['c'] ?? '0'),
        'last_buy_local' => $symbolLastBuyLocal,
        'last_buy_ago' => $symbolLastBuyAgo,
        'last_price' => $lastPricesHome[$symbol] ?? null,
    ];
}
echo '<div class="grid">';
echo '<div class="card col3"><div class="muted">Ledger USDT</div><div class="kpi stack">';
$usdtBalance = $balanceMap['USDT'] ?? 0.0;
echo '<div class="item"><div class="muted">USDT</div><div style="font-size:18px">' . h(number_format((float)$usdtBalance, 8, '.', '')) . '</div></div>';
echo '<div class="item profit"><div class="muted">Profit</div><div style="font-size:18px">' . h(number_format((float)($profit['p'] ?? 0.0), 8, '.', '')) . '</div></div>';
echo '</div></div>';

echo '<div class="card col9 summary-card"><div class="muted">Resumen</div>';
echo '<div class="summary-grid">';
echo '<div class="summary-item"><div class="summary-label">Activas</div><div class="summary-value">' . h((string)($open['c'] ?? '0')) . '</div></div>';
echo '<div class="summary-item"><div class="summary-label">Vendidas</div><div class="summary-value">' . h((string)($sold['c'] ?? '0')) . '</div></div>';
echo '<div class="summary-item"><div class="summary-label">Symbols</div><div class="summary-value">' . h(implode(', ', $symbols)) . '</div></div>';
echo '<div class="summary-item"><div class="summary-label">DCA</div><div class="summary-value">' . h((string)($cfg['strategy']['dca_amount_usdt'] ?? '')) . ' USDT</div></div>';
echo '<div class="summary-item"><div class="summary-label">Sell markup</div><div class="summary-value">' . h((string)($cfg['strategy']['sell_markup_pct'] ?? '')) . '%</div></div>';
echo '<div class="summary-item"><div class="summary-label">Última compra</div><div class="summary-value">' . h($lastBuyLocal ?? '—') . '</div><div class="muted" style="font-size:11px">' . h($lastBuyAgo ?? 'n/a') . '</div></div>';
echo '<div class="summary-item"><div class="summary-label">Próxima compra</div><div class="summary-value">' . h($nextBuyLocal ?? '—') . '</div><div class="muted" style="font-size:11px">' . h($nextBuyIn ?? 'n/a') . '</div></div>';
echo '<div class="summary-item"><div class="summary-label">Última actualización</div><div class="summary-value">' . h(ago($lastAny)) . '</div><div class="muted" style="font-size:11px">' . h(fmtAtomLocal($lastAny)) . '</div></div>';
echo '</div>';
echo '</div>';

foreach ($symbols as $symbol) {
    $baseAsset = str_ends_with($symbol, 'USDT') ? substr($symbol, 0, -4) : $symbol;
    $logo = strtolower($baseAsset) === 'btc'
        ? dashUrl('assets/btc.svg')
        : dashUrl('assets/eth.svg');
    $stats = $symbolStats[$symbol] ?? [];
    echo '<div class="card col6 asset-card">';
    echo '<img class="asset-logo floating" src="' . h($logo) . '" alt="' . h($baseAsset) . '">';
    echo '<div class="asset-badge"><span>' . h($symbol) . '</span></div>';
    echo '<div class="kpi" style="margin-top:10px">';
    $baseBalance = $balanceMap[$baseAsset] ?? 0.0;
    echo '<div class="item"><div class="muted">Balance</div><div style="font-size:18px">' . h(number_format((float)$baseBalance, 8, '.', '')) . ' ' . h($baseAsset) . '</div></div>';
    echo '<div class="item"><div class="muted">Activas</div><div style="font-size:18px">' . h((string)($stats['open'] ?? '0')) . '</div></div>';
    echo '<div class="item"><div class="muted">Vendidas</div><div style="font-size:18px">' . h((string)($stats['sold'] ?? '0')) . '</div></div>';
    $lastPx = $stats['last_price'] ?? null;
    echo '<div class="item"><div class="muted">Last Px</div><div style="font-size:18px">' . h($lastPx === null ? '—' : number_format((float)$lastPx, 2, '.', '')) . '</div></div>';
    echo '<div class="item"><div class="muted">Última compra</div><div style="font-size:18px">' . h($stats['last_buy_local'] ?? '—') . '</div><div class="muted" style="margin-top:2px">' . h($stats['last_buy_ago'] ?? 'n/a') . '</div></div>';
    echo '</div>';
    echo '</div>';
}

foreach ($symbols as $symbol) {
    renderChartCard($db, $cfg, $symbol, '15', 400);
}

// Purchases box on home (same as Purchases view, limited rows).
$priceFetchedAtHome = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$rowsHome = $db->fetchAll('SELECT * FROM purchases ORDER BY id DESC LIMIT 50');
renderPurchasesTable($rowsHome, $lastPricesHome, $priceFetchedAtHome);
echo '</div>';

renderFooter();
