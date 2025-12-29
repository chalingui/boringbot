<?php
declare(strict_types=1);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderHeader(string $title): void
{
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">';
    echo '<meta http-equiv="Pragma" content="no-cache">';
    echo '<meta http-equiv="Expires" content="0">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>
      :root{--bg:#0b1020;--card:#121a33;--text:#e8ecff;--muted:#9aa7d6;--line:#263158;--ok:#41d18b;--warn:#ffcd57;--bad:#ff6b6b;}
      body{margin:0;background:var(--bg);color:var(--text);font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif}
      a{color:#9fb7ff;text-decoration:none} a:hover{text-decoration:underline}
      .wrap{max-width:1100px;margin:0 auto;padding:18px}
      .top{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:14px}
      .nav{display:flex;gap:10px;flex-wrap:wrap}
      .nav a{padding:6px 10px;border:1px solid var(--line);border-radius:10px;background:rgba(255,255,255,.03)}
      .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px}
      .card{grid-column:span 12;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px}
      @media (min-width:900px){.col6{grid-column:span 6}.col4{grid-column:span 4}}
      .kpi{display:flex;gap:16px;flex-wrap:wrap}
      .kpi .item{min-width:160px}
      .muted{color:var(--muted)}
      table{width:100%;border-collapse:collapse}
      th,td{padding:8px;border-bottom:1px solid var(--line);vertical-align:top}
      th{text-align:left;color:var(--muted);font-weight:600}
      .table-wrap{overflow-x:auto}
      code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
      pre{white-space:pre-wrap;word-break:break-word;background:rgba(0,0,0,.2);padding:10px;border-radius:10px;border:1px solid var(--line);max-height:520px;overflow:auto;font-size:11px;line-height:1.3}
      .pill{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--line);font-size:12px}
      .pill.OPEN{border-color:rgba(65,209,139,.4);color:var(--ok)}
      .pill.SOLD{border-color:rgba(159,183,255,.5);color:#9fb7ff}
      .pill.BUYING,.pill.HOLDING,.pill.SOLD_PENDING_CONVERT{border-color:rgba(255,205,87,.4);color:var(--warn)}
      .pill.ERROR{border-color:rgba(255,107,107,.4);color:var(--bad)}
      .bar{height:10px;background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:999px;overflow:hidden}
      .bar > span{display:block;height:100%;background:linear-gradient(90deg,#6ea8ff,#41d18b);width:0%}
    </style></head><body><div class="wrap">';
    echo '<div class="top"><div><div class="muted">boringbot</div><h1 style="margin:2px 0 0;font-size:18px">' . h($title) . '</h1></div>';
    echo '<div class="nav">';
    echo '<a href="/dashboard/">Dashboard</a>';
    echo '<a href="/dashboard/?view=moves">Movimientos</a>';
    echo '<a href="/dashboard/?view=chart">Gráfico</a>';
    echo '<a href="/dashboard/?view=logs">Logs</a>';
    echo '</div></div>';
}

function renderFooter(): void
{
    echo '<div class="muted" style="margin-top:14px;font-size:12px">Auth: HTTP Basic (DASHBOARD_USER / DASHBOARD_PASS)</div>';
    echo '</div></body></html>';
}
