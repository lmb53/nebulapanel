<?php
/** Rendered inside the shell for unknown (404) and role-blocked (403) routes. */
$errorCode = (int) ($errorCode ?? 404);
$forbidden = $errorCode === 403;
?>
<div class="card">
  <div class="empty-state" style="padding:64px 20px">
    <div class="es-icon"><i data-lucide="<?= $forbidden ? 'shield-alert' : 'compass' ?>"></i></div>
    <h1 class="page-title" style="font-size:20px;color:var(--text-primary);margin-top:4px"><?= $forbidden ? 'You don’t have access to this page' : 'Page not found' ?></h1>
    <div class="es-body">
      <?= $forbidden
        ? 'Your role (' . e(panel_roles()[current_role()]['label'] ?? current_role()) . ') cannot open this area. Ask an administrator if you need access.'
        : 'The address <span class="mono">' . e((string) ($_GET['r'] ?? '')) . '</span> does not match any page in ' . e($config['panel_name']) . '.' ?>
    </div>
    <div class="es-actions">
      <a class="btn btn-primary" href="<?= e(url('dashboard')) ?>"><i data-lucide="layout-dashboard"></i>Go to dashboard</a>
      <button class="btn btn-secondary" type="button" data-open-search><i data-lucide="search"></i>Search pages</button>
    </div>
  </div>
</div>
