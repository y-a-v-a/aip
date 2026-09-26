<?php
require_once __DIR__ . '/../config.php';

/** Escape for HTML output. */
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function page_top(string $title, bool $nav = true): void {
    $app = e(APP_TITLE);
    echo "<!doctype html><html lang='en'><head><meta charset='utf-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1'>";
    echo "<title>" . e($title) . " &middot; {$app}</title>";
    echo "<style>
      :root { --bg:#f7f4ee; --card:#fff; --ink:#2b2b28; --accent:#a8552f; --line:#e4ddcf; }
      * { box-sizing:border-box; }
      body { margin:0; font:16px/1.55 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
             background:var(--bg); color:var(--ink); }
      .wrap { max-width:680px; margin:0 auto; padding:16px; }
      header { display:flex; flex-wrap:wrap; align-items:baseline; justify-content:space-between; gap:8px;
               border-bottom:1px solid var(--line); padding-bottom:12px; margin-bottom:20px; }
      h1 { font-size:1.3rem; margin:0; }
      nav a { color:var(--accent); text-decoration:none; margin-left:14px; font-size:.95rem; }
      nav a:hover { text-decoration:underline; }
      .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:18px; margin-bottom:18px; }
      label { display:block; font-weight:600; margin:0 0 6px; }
      .meals { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
      .meals label { font-weight:500; background:var(--bg); border:1px solid var(--line); border-radius:999px;
                     padding:8px 16px; cursor:pointer; margin:0; }
      .meals input { position:absolute; opacity:0; }
      .meals input:checked + span { font-weight:700; color:var(--accent); }
      textarea { width:100%; min-height:70px; padding:10px; border:1px solid var(--line); border-radius:8px;
                 font:inherit; resize:vertical; }
      button { background:var(--accent); color:#fff; border:0; border-radius:8px; padding:12px 20px;
               font:inherit; font-weight:600; cursor:pointer; }
      button:hover { filter:brightness(1.05); }
      button:disabled { opacity:.75; cursor:progress; }
      .spin { display:inline-block; width:14px; height:14px; margin-right:8px; vertical-align:-2px;
              border:2px solid rgba(255,255,255,.45); border-top-color:#fff; border-radius:50%;
              animation:spin .7s linear infinite; }
      @keyframes spin { to { transform:rotate(360deg); } }
      .recipe { white-space:pre-wrap; }
      .recipe .title { font-size:1.25rem; font-weight:700; display:block; margin-bottom:4px; }
      .err { background:#fdecea; border:1px solid #f3c0b8; color:#9b2c1c; padding:10px 14px; border-radius:8px; margin-bottom:16px; }
      .ok { background:#eaf7ee; border:1px solid #b8e0c4; color:#1c7a3c; padding:10px 14px; border-radius:8px; margin-bottom:16px; }
      .muted { color:#8a8577; font-size:.9rem; }
      .list a { display:block; padding:12px 0; border-bottom:1px solid var(--line); text-decoration:none; color:var(--ink); }
      .list a:hover .t { color:var(--accent); }
      .list .t { font-weight:600; }
      input[type=text],input[type=password] { width:100%; padding:11px; border:1px solid var(--line);
               border-radius:8px; font:inherit; margin-bottom:14px; }
      label.check { font-weight:500; display:flex; align-items:center; gap:8px; margin:-4px 0 16px; }
      .users { list-style:none; padding:0; margin:0; }
      .users li { padding:12px 0; border-bottom:1px solid var(--line); }
      .users .id { font-weight:600; word-break:break-all; }
      .users .acts { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
      .users form { margin:0; }
      button.small { padding:6px 12px; font-size:.9rem; font-weight:500; }
      button.ghost { background:transparent; color:var(--accent); border:1px solid var(--line); }
      .tag { display:inline-block; font-size:.75rem; background:var(--bg); border:1px solid var(--line);
             border-radius:999px; padding:1px 8px; margin-left:6px; color:#6b665a; }
      .link { width:100%; padding:10px; border:1px dashed var(--accent); border-radius:8px; background:var(--bg);
              font:13px/1.4 ui-monospace,Menlo,monospace; word-break:break-all; margin-bottom:10px; }
      .btnrow { display:flex; flex-wrap:wrap; gap:8px; }
      a.btn { display:inline-block; background:var(--accent); color:#fff; border-radius:8px; padding:8px 14px;
              text-decoration:none; font-weight:600; font-size:.95rem; }
    </style></head><body><div class='wrap'>";
    echo "<header><h1>{$app}</h1>";
    if ($nav) {
        echo "<nav><a href='/'>New&nbsp;recipe</a><a href='/recipes.php'>Saved</a><a href='/ingredients.php'>Ingredients</a>";
        if (function_exists('is_admin') && is_admin()) {
            echo "<a href='/admin.php'>Admin</a>";
        }
        echo "<a href='/logout.php'>Log&nbsp;out</a></nav>";
    }
    echo "</header><main>";
}

function page_bottom(): void {
    echo "</main></div></body></html>";
}

/** Render recipe text: first line as a title, the rest as a preformatted block. */
function render_recipe(string $text): void {
    $lines = explode("\n", trim($text));
    $title = array_shift($lines);
    echo "<div class='recipe'><span class='title'>" . e($title) . "</span>";
    echo e(implode("\n", $lines));
    echo "</div>";
}
