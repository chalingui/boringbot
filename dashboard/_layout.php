<?php
declare(strict_types=1);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dashBase(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === '') {
        return '/dashboard';
    }
    $dir = str_replace('\\', '/', dirname($script));
    $dir = $dir === '.' ? '' : $dir;
    return rtrim($dir, '/');
}

function dashUrl(string $path = ''): string
{
    $base = dashBase();
    if ($path === '') {
        return $base . '/';
    }
    if (str_starts_with($path, '?')) {
        return $base . '/' . $path;
    }
    return $base . '/' . ltrim($path, '/');
}

function renderHeader(string $title): void
{
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">';
    echo '<meta http-equiv="Pragma" content="no-cache">';
    echo '<meta http-equiv="Expires" content="0">';
    echo '<title>BoringBot | ' . h($title) . '</title>';
    echo '<link rel="icon" href="' . h(dashUrl('favicon.svg')) . '" type="image/svg+xml">';
    echo '<script>(function(){try{var saved=localStorage.getItem("bb_theme");var t=(saved==="light"||saved==="dark")?saved:((window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches)?"dark":"light");document.documentElement.setAttribute("data-theme",t);}catch(e){document.documentElement.setAttribute("data-theme","dark");}})();</script>';
    echo '<style>
      :root{color-scheme:light;--bg:#f3f6fb;--card:#ffffff;--text:#15213d;--muted:#5f6d8d;--line:#d6deee;--ok:#1e9f63;--warn:#a87500;--bad:#ca3f3f;--link:#315bcb;--nav-bg:#f7f9ff;--pre-bg:#eef3fb;--bar-bg:#e7edf8;--summary-bg:linear-gradient(180deg,rgba(49,91,203,.05),rgba(49,91,203,.015));}
      :root[data-theme="dark"]{color-scheme:dark;--bg:#0b1020;--card:#121a33;--text:#e8ecff;--muted:#9aa7d6;--line:#263158;--ok:#41d18b;--warn:#ffcd57;--bad:#ff6b6b;--link:#9fb7ff;--nav-bg:rgba(255,255,255,.03);--pre-bg:rgba(0,0,0,.2);--bar-bg:rgba(255,255,255,.06);--summary-bg:linear-gradient(180deg,rgba(255,255,255,.035),rgba(255,255,255,.015));}
      body{margin:0;background:var(--bg);color:var(--text);font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif}
      a{color:var(--link);text-decoration:none} a:hover{text-decoration:underline}
      .wrap{max-width:1100px;margin:0 auto;padding:18px}
      .top{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:14px}
      .brand{display:flex;gap:10px;align-items:center}
      .brand img{width:34px;height:34px;display:block}
      .top-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
      .nav{display:flex;gap:10px;flex-wrap:wrap}
      .nav a{padding:6px 10px;border:1px solid var(--line);border-radius:10px;background:var(--nav-bg)}
      .theme-toggle{appearance:none;border:1px solid var(--line);background:var(--nav-bg);color:var(--text);border-radius:10px;padding:6px 10px;cursor:pointer;font:inherit;line-height:1.3}
      .theme-toggle:hover{filter:brightness(1.04)}
      .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:10px}
      .card{grid-column:span 12;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px}
      @media (min-width:900px){.col9{grid-column:span 9}.col6{grid-column:span 6}.col4{grid-column:span 4}.col3{grid-column:span 3}}
      .kpi{display:flex;gap:12px;flex-wrap:wrap}
      .kpi .item{min-width:130px}
      .kpi.stack{flex-direction:column;gap:8px}
      .kpi.stack .item{min-width:0}
      .item.profit{border:1px solid rgba(65,209,139,.35);background:rgba(65,209,139,.08);padding:8px;border-radius:10px}
      .muted{color:var(--muted)}
      table{width:100%;border-collapse:collapse}
      th,td{padding:8px;border-bottom:1px solid var(--line);vertical-align:top}
      th{text-align:left;color:var(--muted);font-weight:600}
      .table-wrap{overflow-x:auto}
      code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
      pre{white-space:pre-wrap;word-break:break-word;background:var(--pre-bg);padding:10px;border-radius:10px;border:1px solid var(--line);max-height:520px;overflow:auto;font-size:11px;line-height:1.3}
      .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:11px;line-height:1.3;white-space:pre-wrap;word-break:break-word}
      .pill{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--line);font-size:11px}
      .pill.OPEN{border-color:rgba(65,209,139,.4);color:var(--ok)}
      .pill.SOLD{border-color:rgba(159,183,255,.5);color:#9fb7ff}
      .pill.BUYING,.pill.HOLDING{border-color:rgba(255,205,87,.4);color:var(--warn)}
      .pill.NEEDS_FUNDS{border-color:rgba(255,205,87,.7);color:var(--warn)}
      .pill.ERROR{border-color:rgba(255,107,107,.4);color:var(--bad)}
      .bar{height:10px;background:var(--bar-bg);border:1px solid var(--line);border-radius:999px;overflow:hidden}
      .bar > span{display:block;height:100%;background:linear-gradient(90deg,#6ea8ff,#41d18b);width:0%}
      .progress-cell{min-width:150px}
      .progress-cell .progress-label{margin-top:2px;font-weight:600}
      .progress-cell .progress-meta{margin-top:2px;font-size:11px}
      .progress-cell.progress-below .bar > span{background:linear-gradient(90deg,#ff9b6b,#ff6b6b)}
      .progress-cell.progress-below .progress-label{color:var(--bad)}
      .progress-cell.progress-below .progress-meta{color:var(--bad)}
      .progress-cell.progress-mid .bar > span{background:linear-gradient(90deg,#6ea8ff,#41d18b)}
      .progress-cell.progress-mid .progress-label{color:var(--text)}
      .progress-cell.progress-mid .progress-meta{color:var(--muted)}
      .progress-cell.progress-ready .bar > span,
      .progress-cell.progress-sold .bar > span{background:linear-gradient(90deg,#41d18b,#9cffc9)}
      .progress-cell.progress-ready .progress-label,
      .progress-cell.progress-ready .progress-meta,
      .progress-cell.progress-sold .progress-label,
      .progress-cell.progress-sold .progress-meta{color:var(--ok)}
      .purchase-row td:first-child{border-left:3px solid transparent}
      .purchase-row.pcolor-0 td:first-child{border-left-color:#f4b6b2}
      .purchase-row.pcolor-1 td:first-child{border-left-color:#bde5b8}
      .purchase-row.pcolor-2 td:first-child{border-left-color:#ffe3a3}
      .purchase-row.pcolor-3 td:first-child{border-left-color:#f6c6d7}
      .purchase-row.pcolor-4 td:first-child{border-left-color:#d9c7f7}
      .purchase-row.pcolor-5 td:first-child{border-left-color:#ffd3b6}
      .purchase-id{font-weight:600}
      .purchase-row.pcolor-0 .purchase-id{color:#f4b6b2}
      .purchase-row.pcolor-1 .purchase-id{color:#bde5b8}
      .purchase-row.pcolor-2 .purchase-id{color:#ffe3a3}
      .purchase-row.pcolor-3 .purchase-id{color:#f6c6d7}
      .purchase-row.pcolor-4 .purchase-id{color:#d9c7f7}
      .purchase-row.pcolor-5 .purchase-id{color:#ffd3b6}
      .asset-card{position:relative;overflow:hidden}
      .asset-badge{display:flex;align-items:center;gap:8px;font-weight:700}
      .asset-logo{width:28px;height:28px;border-radius:50%;box-shadow:0 6px 14px rgba(0,0,0,.25)}
      .asset-logo.floating{position:absolute;top:-10px;right:-10px;width:40px;height:40px;opacity:.95}
      .summary-card{padding:10px 12px}
      .summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
      @media (max-width:900px){.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
      @media (max-width:600px){.summary-grid{grid-template-columns:1fr}}
      .summary-item{display:flex;flex-direction:column;gap:4px;padding:9px 10px;border:1px solid var(--line);border-radius:10px;background:var(--summary-bg)}
      .summary-item-highlight{border-color:rgba(110,168,255,.45);box-shadow:inset 0 0 0 1px rgba(110,168,255,.12)}
      .summary-label{font-size:11px;color:var(--muted);letter-spacing:.02em;text-transform:uppercase}
      .summary-value{font-size:16px;font-weight:600;line-height:1.25;word-break:break-word}
      .summary-meta{font-size:11px;color:var(--muted)}
    </style></head><body><div class="wrap">';
    echo '<div class="top"><div class="brand"><a href="' . h(dashUrl()) . '" aria-label="Dashboard home" style="display:inline-flex"><img src="' . h(dashUrl('assets/boringbot-logo.svg')) . '" alt="boringbot"></a><div><div class="muted">boringbot</div><h1 style="margin:2px 0 0;font-size:18px">' . h($title) . '</h1></div></div>';
    echo '<div class="top-actions"><div class="nav">';
    echo '<a href="' . h(dashUrl()) . '">Dashboard</a>';
    echo '<a href="' . h(dashUrl('?view=moves')) . '">Movimientos</a>';
    echo '<a href="' . h(dashUrl('?view=logs')) . '">Logs</a>';
    echo '</div><button type="button" id="theme-toggle" class="theme-toggle" aria-label="Cambiar tema">Modo claro</button></div></div>';
}

function renderFooter(): void
{
    echo '<div class="muted" style="margin-top:14px;font-size:12px">Auth: HTTP Basic (DASHBOARD_USER / DASHBOARD_PASS)</div>';
    echo '<script>
    (function(){
      var btn=document.getElementById("theme-toggle");
      if(!btn){return;}
      function apply(theme){
        document.documentElement.setAttribute("data-theme",theme);
        btn.textContent=theme==="dark"?"Modo claro":"Modo oscuro";
        btn.setAttribute("aria-pressed", theme==="dark" ? "true" : "false");
      }
      var current=document.documentElement.getAttribute("data-theme")||"dark";
      apply(current);
      btn.addEventListener("click",function(){
        var next=(document.documentElement.getAttribute("data-theme")==="dark")?"light":"dark";
        apply(next);
        try{localStorage.setItem("bb_theme",next);}catch(e){}
      });
    })();
    </script>';
    echo '</div></body></html>';
}
