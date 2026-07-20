<?php
/** @var string $__view  absolute path to the inner view */
/** @var string $__active current route for nav highlighting */
/** @var array  $config */
$active = $__active ?? 'dashboard';
$nav = [
    'Pinned' => [
        ['dashboard', 'layout-dashboard', 'Dashboard'],
        ['files',     'folder-tree',      'File Manager'],
        ['services',  'server-cog',       'Services'],
        ['sysinfo',   'cpu',              'System Info'],
    ],
];
function nav_link(string $route, string $icon, string $label, string $active): string
{
    $cls = $route === $active ? 'nav-item active' : 'nav-item';
    return '<a class="' . $cls . '" href="' . e(url($route)) . '">'
        . '<i data-lucide="' . e($icon) . '"></i><span class="nav-label">' . e($label) . '</span></a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($config['panel_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <div class="logo-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg></div>
      <span class="brand-name"><?= e($config['panel_name']) ?></span>
    </div>
    <div class="sidebar-scroll">
      <?php foreach ($nav as $section => $items): ?>
        <div class="nav-section-title"><?= e($section) ?></div>
        <?php foreach ($items as $it): ?>
          <?= nav_link($it[0], $it[1], $it[2], $active) ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
    <div class="sidebar-footer">
      <a class="collapse-btn" href="<?= e(url('logout')) ?>" style="text-decoration:none">
        <i data-lucide="log-out"></i><span class="nav-label">Sign out</span>
      </a>
      <button class="collapse-btn" id="collapseBtn"><i data-lucide="panel-left-close"></i><span class="nav-label">Collapse</span></button>
    </div>
  </aside>

  <div class="main-col">
    <header class="topbar">
      <div class="server-select"><span class="dot"></span><span><?= e(gethostname() ?: 'server') ?></span></div>
      <div class="topbar-spacer"></div>
      <div class="mini-health" id="miniHealth">
        <div class="mh-item"><i data-lucide="cpu" style="width:12px;height:12px"></i><span data-mh="cpu">–</span><div class="mh-bar"><div class="mh-fill" data-mh-bar="cpu" style="width:0;background:var(--emerald-500)"></div></div></div>
        <div class="mh-item"><i data-lucide="memory-stick" style="width:12px;height:12px"></i><span data-mh="mem">–</span><div class="mh-bar"><div class="mh-fill" data-mh-bar="mem" style="width:0;background:var(--orange-500)"></div></div></div>
        <div class="mh-item"><i data-lucide="hard-drive" style="width:12px;height:12px"></i><span data-mh="disk">–</span><div class="mh-bar"><div class="mh-fill" data-mh-bar="disk" style="width:0;background:var(--blue-500)"></div></div></div>
      </div>
      <button class="icon-btn" id="themeToggle" title="Toggle theme"><i data-lucide="moon"></i></button>
      <div class="avatar" title="<?= e(current_user() ?? '') ?>"><?= e(strtoupper(substr(current_user() ?? 'U', 0, 2))) ?></div>
    </header>

    <main class="page">
      <?php require $__view; ?>
    </main>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
