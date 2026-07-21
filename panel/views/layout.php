<?php
/** @var string $__view  absolute path to the inner view */
/** @var string $__active current route for nav highlighting */
/** @var array  $config */
require_once APP_ROOT . '/lib/mod_apps.php';
$active = $__active ?? 'dashboard';
$activeName = $_GET['name'] ?? '';
// Build nav grouped by section from the module registry.
$nav = [];
foreach (nebula_modules() as $route => $m) {
    if (!role_route_allowed($route)) { continue; }
    $nav[$m[2]][] = [$route, $m[0], $m[1]];
}
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
<body class="<?= $active === 'file-edit' ? 'editor-window' : '' ?>">
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
      <form method="post" action="<?= e(url('logout')) ?>" style="margin:0">
        <?= csrf_field() ?>
        <button class="collapse-btn" type="submit" style="width:100%">
          <i data-lucide="log-out"></i><span class="nav-label">Sign out</span>
        </button>
      </form>
      <button class="collapse-btn" id="collapseBtn"><i data-lucide="panel-left-close"></i><span class="nav-label">Collapse</span></button>
    </div>
  </aside>

  <div class="main-col">
    <header class="topbar">
      <div class="search-trigger" id="searchTrigger"><i data-lucide="search"></i><span>Search or jump to…</span><span class="kbd">⌘K</span></div>
      <div class="server-select"><span class="dot"></span><span><?= e(gethostname() ?: 'server') ?></span></div>
      <div class="topbar-spacer"></div>
      <div class="mini-health" id="miniHealth">
        <div class="mh-item"><i data-lucide="cpu" style="width:12px;height:12px"></i><span data-mh="cpu">–</span><div class="mh-bar"><div class="mh-fill" data-mh-bar="cpu" style="width:0;background:var(--emerald-500)"></div></div></div>
        <div class="mh-item"><i data-lucide="memory-stick" style="width:12px;height:12px"></i><span data-mh="mem">–</span><div class="mh-bar"><div class="mh-fill" data-mh-bar="mem" style="width:0;background:var(--orange-500)"></div></div></div>
        <div class="mh-item"><i data-lucide="hard-drive" style="width:12px;height:12px"></i><span data-mh="disk">–</span><div class="mh-bar"><div class="mh-fill" data-mh-bar="disk" style="width:0;background:var(--blue-500)"></div></div></div>
      </div>
      <button class="icon-btn" id="themeToggle" title="Toggle theme"><i data-lucide="moon"></i></button>
      <div class="topbar-menu">
        <button class="icon-btn" id="notificationTrigger" type="button" title="Notifications" aria-expanded="false"><i data-lucide="bell"></i><span class="dot-badge hidden" id="notificationDot"></span></button>
        <div class="dropdown-menu notification-menu hidden" id="notificationMenu">
          <div class="dropdown-head"><div><strong>Notifications</strong><span id="notificationCount">Loading…</span></div><button class="btn btn-ghost btn-sm" id="notificationReadAll">Mark all read</button></div>
          <div class="notification-menu-list" id="notificationMenuList"><div class="fm-empty-hint">Loading notifications…</div></div>
          <a class="dropdown-foot" href="<?= e(url('notifications')) ?>">View all notifications <i data-lucide="arrow-right"></i></a>
        </div>
      </div>
      <div class="avatar" title="<?= e((current_user() ?? '') . ' · ' . ucfirst(current_role())) ?>"><?= e(strtoupper(substr(current_user() ?? 'U', 0, 2))) ?></div>
    </header>

    <main class="page<?= $active === 'files' ? ' page-file-manager' : ($active === 'file-edit' ? ' page-file-editor' : '') ?>">
      <?php require $__view; ?>
    </main>
  </div>
</div>

<!-- Command palette -->
<div class="cmdk-overlay hidden" id="cmdk">
  <div class="cmdk">
    <div class="cmdk-input"><i data-lucide="search" style="width:16px;height:16px;color:var(--text-tertiary)"></i><input id="cmdkInput" placeholder="Search pages…" autocomplete="off"></div>
    <div class="cmdk-list" id="cmdkList">
      <?php foreach (nebula_modules() as $route => $m): ?>
        <?php if (!role_route_allowed($route)) continue; ?>
        <div class="cmdk-item" data-href="<?= e(url($route)) ?>" data-label="<?= e(strtolower($m[1] . ' ' . $m[2])) ?>">
          <i data-lucide="<?= e($m[0]) ?>"></i><span><?= e($m[1]) ?></span>
          <span style="margin-left:auto;font-size:11px;color:var(--text-tertiary)"><?= e($m[2]) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
