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
    echo '<th>ID</th><th>Status</th><th>Created</th><th>Buy USDT</th><th>Buy Px</th><th>Buy Qty</th><th>Target Px</th><th>Sell Avg Px</th><th>Last Px</th><th>Δ Px</th><th>Progress</th><th>Profit</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $p) {
        $id = (int)$p['id'];
        $status = (string)$p['status'];
        $colorClass = $status === 'SOLD' ? '' : ('pcolor-' . ($id % 6));

        $targetPx = $p['sell_price'] !== null ? (float)$p['sell_price'] : null;
        $deltaPx = ($lastPrice !== null && $targetPx !== null) ? ($targetPx - $lastPrice) : null; // USDT per ETH
        $sellQty = $p['sell_qty'] !== null ? (float)$p['sell_qty'] : null;
        $sellUsdt = $p['sell_usdt'] !== null ? (float)$p['sell_usdt'] : null;
        $sellAvgPx = ($sellQty !== null && $sellUsdt !== null && $sellQty > 0) ? ($sellUsdt / $sellQty) : null;

        echo '<tr id="p' . h((string)$id) . '" class="purchase-row ' . h($colorClass) . '">';
        echo '<td><a class="purchase-id" href="' . h(dashUrl('?view=purchases')) . '#p' . h((string)$id) . '">#' . h((string)$id) . '</a></td>';
        echo '<td><span class="pill ' . h($status) . '">' . h($status) . '</span></td>';
        echo '<td>' . h(fmtDbDt((string)$p['created_at'])) . '<br><span class="muted">' . h(agoDbDt((string)$p['created_at'])) . '</span></td>';
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
                echo '<td>' . h(number_format($deltaPx, 2, '.', '')) . ' USDT/ETH</td>';
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

    $purchases = $db->fetchAll('SELECT * FROM purchases WHERE status IN ("BUYING","HOLDING","OPEN") AND buy_price IS NOT NULL ORDER BY id DESC');
    if ($purchases === []) {
        $purchases = $db->fetchAll('SELECT * FROM purchases WHERE buy_price IS NOT NULL ORDER BY id DESC LIMIT 50');
    }
    $primaryPurchase = $db->fetchOne('SELECT * FROM purchases WHERE status IN ("BUYING","HOLDING","OPEN") AND buy_price IS NOT NULL ORDER BY id DESC LIMIT 1');
    if (!is_array($primaryPurchase)) {
        $primaryPurchase = $db->fetchOne('SELECT * FROM purchases WHERE buy_price IS NOT NULL ORDER BY id DESC LIMIT 1');
    }

    $startDt = null;
    $primaryBuyMs = null;
    $primaryId = null;
    $openStart = $db->fetchOne(
        'SELECT MIN(COALESCE(buy_filled_at, created_at)) AS first_at
         FROM purchases
         WHERE status IN ("BUYING","HOLDING","OPEN") AND buy_price IS NOT NULL'
    );
    if (is_array($openStart) && ($openStart['first_at'] ?? '') !== '') {
        try {
            $startDt = new DateTimeImmutable((string)$openStart['first_at'] . ' UTC');
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

    $purchasesSorted = $purchases;
    usort($purchasesSorted, static fn(array $a, array $b) => ((int)$b['id']) <=> ((int)$a['id']));

    foreach ($purchasesSorted as $p) {
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

    // Next DCA marker (based on last purchase created_at + interval days, like the bot does).
    $nextBuyMs = null;
    $nextBuyLocal = null;
    $latest = $db->fetchOne('SELECT created_at FROM purchases ORDER BY id DESC LIMIT 1');
    if (is_array($latest) && isset($latest['created_at'])) {
        $days = (int)($cfg['strategy']['dca_interval_days'] ?? 7);
        if ($days > 0) {
            try {
                $last = new DateTimeImmutable((string)$latest['created_at'] . ' UTC');
                $dueAt = $last->add(new DateInterval('P' . $days . 'D'));
                $nextBuyMs = (float)($dueAt->getTimestamp() * 1000);
                $nextBuyLocal = $dueAt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i');
            } catch (Throwable) {
                $nextBuyMs = null;
                $nextBuyLocal = null;
            }
        }
    }

    $x0 = (float)$series[0][0];
    $x1 = (float)$series[count($series) - 1][0];
    if ($x1 <= $x0) {
        $x1 = $x0 + 1;
    }

    // Extend chart window to include next buy, even if we don't have price data for the future.
    if ($nextBuyMs !== null && $nextBuyMs > $x1) {
        $x1 = $nextBuyMs;
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
    $palette = ['#6ea8ff', '#41d18b', '#ffcd57', '#ff6b6b', '#9fb7ff', '#f48fb1'];

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
    $lastPurchaseMs = null;
    $chartPriceSegments = [];
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
        if ($lastPurchaseMs === null || $ms > $lastPurchaseMs) {
            $lastPurchaseMs = $ms;
        }
        if ($lastPurchaseMs === null || $ms > $lastPurchaseMs) {
            $lastPurchaseMs = $ms;
        }
    }
    foreach ($purchasesSorted as $pRow) {
        $id = (int)$pRow['id'];
        $color = $palette[$id % count($palette)];
        $buyPx = $pRow['buy_price'] !== null ? (float)$pRow['buy_price'] : null;
        $sellPx = $pRow['sell_price'] !== null ? (float)$pRow['sell_price'] : null;
        $status = (string)($pRow['status'] ?? '');
        if ($buyPx !== null) {
            $chartBuyLines[] = ['id' => $id, 'price' => $buyPx, 'color' => $color];
        }
        if ($sellPx !== null && in_array($status, ['OPEN', 'HOLDING'], true)) {
            $chartSellLines[] = ['id' => $id, 'price' => $sellPx, 'color' => $color];
            $chartOpenLines[] = ['id' => $id, 'color' => $color];
        }
    }
    $chartId = 'chartjs-overlay';
    $xMin = $x0;
    $xMax = $x1;
    echo '<div style="margin-bottom:8px">';
    echo '<canvas id="' . h($chartId) . '" height="120"></canvas>';
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
        const nextBuyColor = ' . json_encode($palette[(($primaryId ?? 0) + 1) % count($palette)]) . ';
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
                _labelText: "#"+o.id,
                _labelColor: o.color,
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
              data: [{x: xMin, y: b.price}, {x: xMax, y: b.price}],
              borderColor: b.color,
              borderWidth: 1.2,
              borderDash: [4,4],
              pointRadius: 0,
              fill: false,
              tension: 0,
              _labelText: "#"+b.id,
              _labelColor: b.color,
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
            data: [{x: xMin, y: s.price}, {x: xMax, y: s.price}],
            borderColor: s.color,
            borderWidth: 1.2,
            pointRadius: 0,
            fill: false,
            tension: 0,
            _labelText: "#"+s.id,
            _labelColor: s.color,
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
              const pt = meta.data[meta.data.length - 1];
              if (!pt) return;
              ctx.save();
              ctx.fillStyle = ds._labelColor || "#9aa7d6";
              ctx.font = "11px system-ui,-apple-system,Segoe UI,Roboto";
              ctx.textAlign = "left";
              ctx.textBaseline = "middle";
              ctx.fillText(ds._labelText, pt.x + 6, pt.y);
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

    echo '<div class="muted" style="margin-top:6px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">';
    $intervalLabel = $intervalOriginal === $interval ? $interval : ($intervalOriginal . ' → ' . $interval);
    echo '<div>Symbol: <code>' . h($symbol) . '</code> | interval: <code>' . h($intervalLabel) . '</code> | points: <code>' . h((string)count($series)) . '</code></div>';
    echo '<div>Window: <code>' . h((new DateTimeImmutable('@' . (int)($x0 / 1000)))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i')) . '</code> → <code>' . h((new DateTimeImmutable('@' . (int)($x1 / 1000)))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i')) . '</code></div>';
    echo '</div>';
    // Intentionally omit detailed purchase legend; chart labels cover ids.

    echo '<div class="table-wrap" style="margin-top:10px">';
    echo '<svg viewBox="0 0 ' . h((string)$w) . ' ' . h((string)$h) . '" width="100%" height="auto" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Price chart">';
    // Horizontal gridlines with round $ ticks (ideally every $100).
    $tickStep = 100.0;
    $minTick = floor($minY / $tickStep) * $tickStep;
    $maxTick = ceil($maxY / $tickStep) * $tickStep;
    $tickCount = (int)floor((($maxTick - $minTick) / $tickStep) + 1.0000001);
    if ($tickCount > 80) {
        $tickStep = 200.0;
        $minTick = floor($minY / $tickStep) * $tickStep;
        $maxTick = ceil($maxY / $tickStep) * $tickStep;
    }
    for ($v = $minTick; $v <= $maxTick + 1e-9; $v += $tickStep) {
        $yy = $sy((float)$v);
        echo '<line x1="' . h((string)$pl) . '" y1="' . h((string)$yy) . '" x2="' . h((string)($w - $pr)) . '" y2="' . h((string)$yy) . '" stroke="rgba(255,255,255,.06)" />';
        echo '<text x="' . h((string)10) . '" y="' . h((string)($yy + 4)) . '" fill="rgba(255,255,255,.55)" font-size="11">' . h(number_format((float)$v, 0, '.', '')) . '</text>';
    }

    // Next DCA vertical marker (subtle).
    if ($nextBuyMs !== null && $nextBuyMs >= $x0 && $nextBuyMs <= $x1) {
        $nx = $sx($nextBuyMs);
        echo '<line x1="' . h((string)$nx) . '" y1="' . h((string)$pt) . '" x2="' . h((string)$nx) . '" y2="' . h((string)($pt + $innerH)) . '" stroke="rgba(179,136,255,.45)" stroke-width="1" stroke-dasharray="3 6" />';
        echo '<text x="' . h((string)($nx + 6)) . '" y="' . h((string)($pt + 14)) . '" fill="rgba(179,136,255,.8)" font-size="11">Próxima compra' . ($nextBuyLocal ? ' (' . h($nextBuyLocal) . ')' : '') . '</text>';
    }
    echo '<polyline fill="none" stroke="rgba(159,183,255,.35)" stroke-width="2" points="' . h($priceLine) . '" />';

    $sellLineOffsets = [];
    $sellLines = [];
    foreach ($purchasesSorted as $p) {
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
        $color = $palette[$id % count($palette)];

        if ($sellPrice !== null && in_array((string)$p['status'], ['OPEN', 'HOLDING'], true)) {
            $sellLines[] = [
                'id' => $id,
                'price' => $sellPrice,
                'color' => $color,
            ];
        }

        if ($buyMs < $x0 || $buyMs > $x1) {
            continue;
        }

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

        // Buy price reference (same style as sell target line, but ~30% opacity).
        $by = $sy($buyPrice);
        echo '<line x1="' . h((string)$pl) . '" y1="' . h((string)$by) . '" x2="' . h((string)($w - $pr)) . '" y2="' . h((string)$by) . '" stroke="' . h($color) . '" stroke-width="1.6" opacity="0.30" />';

        $cx = $sx($buyMs);
        $cy = $sy($buyPrice);
        $buyLocalShort = $buyDt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i');
        $buyUsdt = $p['buy_usdt'] !== null ? (float)$p['buy_usdt'] : null;

        $isPrimary = $primaryId !== null && $id === $primaryId;
        if ($isPrimary) {
            // Buy time marker (vertical) to make the "momento de compra" visible in the chart.
            echo '<line x1="' . h((string)$cx) . '" y1="' . h((string)$pt) . '" x2="' . h((string)$cx) . '" y2="' . h((string)($pt + $innerH)) . '" stroke="' . h($color) . '" stroke-width="1" opacity="0.35" stroke-dasharray="4 4" />';
        }

        $title = 'Compra #' . (string)$id;
        echo '<circle cx="' . h((string)$cx) . '" cy="' . h((string)$cy) . '" r="4" fill="' . h($color) . '"><title>' . h($title) . '</title></circle>';
        echo '<text x="' . h((string)($cx + 6)) . '" y="' . h((string)($cy - 6)) . '" fill="' . h($color) . '" font-size="12">#' . h((string)$id) . '</text>';
    }

    // Draw sell target lines last so overlapping lines remain visible.
    foreach ($sellLines as $line) {
        $price = (float)$line['price'];
        $key = number_format($price, 6, '.', '');
        $idx = $sellLineOffsets[$key] ?? 0;
        $sellLineOffsets[$key] = $idx + 1;
        $offsets = [0, -8, 8, -16, 16, -24, 24, -32, 32];
        $offsetPx = $offsets[$idx % count($offsets)];
        $yy = $sy($price) + $offsetPx;
        $color = (string)$line['color'];
        $id = (int)$line['id'];

        echo '<line x1="' . h((string)$pl) . '" y1="' . h((string)$yy) . '" x2="' . h((string)($w - $pr)) . '" y2="' . h((string)$yy) . '" stroke="' . h($color) . '" stroke-width="1.8" opacity="0.95" />';
        $labelX = $w - $pr - 4;
        $labelY = $yy - 2;
        echo '<text x="' . h((string)$labelX) . '" y="' . h((string)$labelY) . '" text-anchor="end" fill="' . h($color) . '" font-size="10">#' . h((string)$id) . '</text>';
    }

    echo '</svg></div>';
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
$open = $db->fetchOne('SELECT COUNT(1) as c FROM purchases WHERE status IN ("BUYING","HOLDING","OPEN")');
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

echo '<div class="grid">';
echo '<div class="card col6"><div class="muted">Balances (ledger)</div><div class="kpi">';
foreach ($balances as $b) {
    if ((string)$b['asset'] === 'USDC') {
        continue;
    }
    echo '<div class="item"><div class="muted">' . h((string)$b['asset']) . '</div><div style="font-size:18px">' . h(number_format((float)$b['amount'], 8, '.', '')) . '</div></div>';
}
echo '<div class="item"><div class="muted">Profit</div><div style="font-size:18px">' . h(number_format((float)($profit['p'] ?? 0.0), 8, '.', '')) . '</div></div>';
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
