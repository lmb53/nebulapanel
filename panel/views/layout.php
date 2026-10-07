<?php
/** @var string $__view  absolute path to the inner view */
/** @var string $__active current route for nav highlighting */
/** @var array  $config */
require_once APP_ROOT . '/lib/mod_apps.php';
$active = $__active ?? 'dashboard';
$navActive = nav_active_route($active);
$pageTitle = isset($errorCode) ? ((int) $errorCode === 403 ? 'Access denied' : 'Page not found') : page_title_for($active);
$roleLabel = panel_roles()[current_role()]['label'] ?? ucfirst(current_role());
$userName = (string) (current_user() ?? '');
// Build nav grouped by section from the module registry.
$nav = [];
foreach (nebula_modules() as $route => $m) {
    if (!role_route_allowed($route)) { continue; }
    $nav[$m[2]][] = [$route, $m[0], $m[1]];
}
function nav_link(string $route, string $icon, string $label, string $active): string
{
    $isActive = $route === $active;
    return '<a class="nav-item' . ($isActive ? ' active' : '') . '" href="' . e(url($route)) . '" title="' . e($label) . '"'
        . ($isActive ? ' aria-current="page"' : '') . '>'
        . '<i data-lucide="' . e($icon) . '" aria-hidden="true"></i><span class="nav-label">' . e($label) . '</span></a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e(($pageTitle !== '' ? $pageTitle . ' · ' : '') . $config['panel_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="<?= e(asset('vendor/lucide-1.8.0.min.js')) ?>"></script>
<?php if ($active === 'dashboard'): ?>
<script src="<?= e(asset('vendor/chart-4.4.9.umd.min.js')) ?>"></script>
<?php endif; ?>
<?= theme_boot_script() ?>
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<meta name="health-thresholds" content="<?= (int) ($config['health_warn_percent'] ?? 80) ?>,<?= (int) ($config['health_critical_percent'] ?? 90) ?>">
</head>
<body class="<?= $active === 'file-edit' ? 'editor-window' : '' ?>">
<a class="sr-only" href="#main">Skip to content</a>
<div class="app-shell">
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-brand">
      <div class="logo-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg></div>
      <span class="brand-name"><?= e($config['panel_name']) ?></span>
    </div>
    <nav class="sidebar-scroll">
      <?php foreach ($nav as $section => $items): ?>
        <?php $sectionId = 'nav-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $section)); ?>
        <div class="nav-group" data-nav-group="<?= e($section) ?>">
          <button class="nav-section-title" type="button" aria-expanded="true" aria-controls="<?= e($sectionId) ?>"><span class="nav-section-label"><?= e($section) ?></span><i data-lucide="chevron-down" aria-hidden="true"></i></button>
          <div id="<?= e($sectionId) ?>">
          <?php foreach ($items as $it): ?>
            <?= nav_link($it[0], $it[1], $it[2], $navActive) ?>
          <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <form method="post" action="<?= e(url('logout')) ?>" style="margin:0">
        <?= csrf_field() ?>
        <button class="collapse-btn" type="submit" title="Sign out">
          <i data-lucide="log-out" aria-hidden="true"></i><span class="nav-label">Sign out</span>
        </button>
      </form>
      <button class="collapse-btn" id="collapseBtn" type="button" title="Collapse sidebar" aria-pressed="false"><i data-lucide="panel-left-close" aria-hidden="true"></i><span class="nav-label">Collapse</span></button>
    </div>
  </aside>

  <div class="main-col">
    <header class="topbar">
      <button class="icon-btn nav-toggle" id="navToggle" type="button" aria-label="Open navigation" title="Menu" aria-controls="sidebar" aria-expanded="false"><i data-lucide="menu"></i></button>
      <button class="search-trigger" id="searchTrigger" type="button" aria-haspopup="dialog" aria-controls="cmdk" aria-label="Search pages (Ctrl+K)"><i data-lucide="search" aria-hidden="true"></i><span>Search or jump to…</span><span class="kbd" aria-hidden="true">⌘K</span></button>
      <div class="server-select" title="Server hostname"><span class="dot" aria-hidden="true"></span><span><?= e(gethostname() ?: 'server') ?></span></div>
      <div class="topbar-spacer"></div>
      <div class="mini-health" id="miniHealth" role="group" aria-label="Server resources">
        <div class="mh-item" title="CPU usage"><i data-lucide="cpu" aria-hidden="true" style="width:13px;height:13px"></i><span class="sr-only">CPU</span><span data-mh="cpu">–</span><div class="mh-bar"><div class="mh-fill" data-mh-bar="cpu" style="width:0"></div></div></div>
        <div class="mh-item" title="Memory usage"><i data-lucide="memory-stick" aria-hidden="true" style="width:13px;height:13px"></i><span class="sr-only">Memory</span><span data-mh="mem">–</span><div class="mh-bar"><div class="mh-fill" data-mh-bar="mem" style="width:0"></div></div></div>
        <div class="mh-item" title="Disk usage (/)"><i data-lucide="hard-drive" aria-hidden="true" style="width:13px;height:13px"></i><span class="sr-only">Disk</span><span data-mh="disk">–</span><div class="mh-bar"><div class="mh-fill" data-mh-bar="disk" style="width:0"></div></div></div>
      </div>
      <button class="icon-btn" id="themeToggle" type="button" title="Toggle theme" aria-label="Toggle theme"><i data-lucide="moon"></i></button>
      <div class="topbar-menu">
        <button class="icon-btn" id="notificationTrigger" type="button" title="Notifications" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" aria-controls="notificationMenu"><i data-lucide="bell"></i><span class="dot-badge hidden" id="notificationDot"></span></button>
        <div class="dropdown-menu notification-menu hidden" id="notificationMenu">
          <div class="dropdown-head"><div><strong>Notifications</strong><span id="notificationCount">Loading…</span></div><button class="btn btn-ghost btn-sm" type="button" id="notificationReadAll">Mark all as read</button></div>
          <div class="notification-menu-list" id="notificationMenuList"><div class="fm-empty-hint">Loading notifications…</div></div>
          <a class="dropdown-foot" href="<?= e(url('notifications')) ?>">View all notifications <i data-lucide="arrow-right" aria-hidden="true"></i></a>
        </div>
      </div>
      <div class="topbar-menu">
        <button class="avatar" id="accountTrigger" type="button" title="<?= e($userName . ' · ' . $roleLabel) ?>" aria-label="Account menu for <?= e($userName) ?>" aria-haspopup="true" aria-expanded="false" aria-controls="accountMenu"><?= e(strtoupper(substr($userName !== '' ? $userName : 'U', 0, 2))) ?></button>
        <div class="dropdown-menu account-menu hidden" id="accountMenu">
          <div class="dropdown-head"><div><strong><?= e($userName) ?></strong><span>Signed in as <?= e($roleLabel) ?></span></div></div>
          <div class="menu-list">
            <a class="menu-item" href="<?= e(url('account')) ?>"><i data-lucide="user-round-cog" aria-hidden="true"></i>My account &amp; password</a>
            <button class="menu-item" type="button" data-theme-toggle><i data-lucide="sun-moon" aria-hidden="true"></i>Switch theme</button>
            <form method="post" action="<?= e(url('logout')) ?>" style="margin:0"><?= csrf_field() ?><button class="menu-item danger" type="submit"><i data-lucide="log-out" aria-hidden="true"></i>Sign out</button></form>
          </div>
        </div>
      </div>
    </header>

    <main class="page<?= $active === 'files' ? ' page-file-manager' : ($active === 'file-edit' ? ' page-file-editor' : '') ?>" id="main" tabindex="-1">
      <?php require $__view; ?>
    </main>
  </div>
</div>

<!-- Command palette -->
<div class="cmdk-overlay hidden" id="cmdk">
  <div class="cmdk" aria-label="Search pages">
    <div class="cmdk-input"><i data-lucide="search" aria-hidden="true" style="width:16px;height:16px;color:var(--text-tertiary)"></i><input id="cmdkInput" placeholder="Search pages and features…" autocomplete="off" aria-label="Search pages and features" aria-controls="cmdkList"></div>
    <div class="cmdk-list" id="cmdkList" role="listbox">
      <?php foreach (nebula_modules() as $route => $m): ?>
        <?php if (!role_route_allowed($route)) continue; ?>
        <div class="cmdk-item" role="option" data-href="<?= e(url($route)) ?>" data-label="<?= e(strtolower($m[1] . ' ' . $m[2] . ' ' . ($m[3] ?? ''))) ?>">
          <i data-lucide="<?= e($m[0]) ?>" aria-hidden="true"></i><span><?= e($m[1]) ?></span>
          <span class="cmdk-section"><?= e($m[2]) ?></span>
        </div>
      <?php endforeach; ?>
      <div class="cmdk-item" role="option" data-href="<?= e(url('account')) ?>" data-label="my account password profile sign in">
        <i data-lucide="user-round-cog" aria-hidden="true"></i><span>My account</span><span class="cmdk-section">Account</span>
      </div>
      <div class="cmdk-empty hidden" id="cmdkEmpty">No pages match your search.</div>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack" role="status" aria-live="polite"></div>
<script>window.NEBULA_PAGE = window.NEBULA_PAGE || <?= json_encode($active) ?>;</script>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
