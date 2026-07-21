<?php
/** Unified service manager: overview plus one functional tab per instance. */
require_once APP_ROOT . '/lib/mod_apps.php';
require_once APP_ROOT . '/lib/mod_sites.php';

$allowed = array_values(array_unique(array_merge($config['services'], manageable_units())));
$services = services_overview($allowed);
$instances = manageable_services();
$sites = sites_list();

$statusBadge = static function (string $status): array {
    return [
        'active' => ['badge-emerald', 'Running'],
        'inactive' => ['badge-slate', 'Stopped'],
        'failed' => ['badge-red', 'Failed'],
        'not-installed' => ['badge-slate', 'Not installed'],
    ][$status] ?? ['badge-slate', ucfirst($status)];
};
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Services</h1>
    <p class="page-subtitle"><?= count($instances) ?> installed instance<?= count($instances) === 1 ? '' : 's' ?> · manage status, boot behaviour, websites and logs</p>
  </div>
  <div class="page-actions">
    <?php if (role_route_allowed('apps')): ?><a class="btn btn-secondary" href="<?= e(url('apps')) ?>"><i data-lucide="package-plus"></i>Install service</a><?php endif; ?>
    <button class="btn btn-secondary" type="button" id="servicesRefresh"><i data-lucide="refresh-cw"></i>Refresh</button>
  </div>
</div>

<div class="card service-manager">
  <div class="tabs service-instance-tabs" data-service-tabs>
    <button class="tab active" type="button" data-tab-target="svc-overview" data-tab-panel-group="#servicePanels"><i data-lucide="layout-grid"></i>Overview</button>
    <?php foreach ($instances as $instance): ?>
      <button class="tab" type="button" data-tab-target="svc-<?= e(preg_replace('/[^a-z0-9]+/i', '-', $instance['unit'])) ?>" data-tab-panel-group="#servicePanels">
        <i data-lucide="<?= e($instance['icon']) ?>"></i><?= e($instance['label']) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <div id="servicePanels">
    <div id="svc-overview" data-tab-panel>
      <div class="grid grid-4 service-summary-grid">
        <?php foreach ($instances as $instance): $status = service_status($instance['unit']); [$cls, $label] = $statusBadge($status); ?>
          <button class="service-instance-card" type="button" data-open-service="svc-<?= e(preg_replace('/[^a-z0-9]+/i', '-', $instance['unit'])) ?>">
            <span class="svc-icon"><i data-lucide="<?= e($instance['icon']) ?>"></i></span>
            <span><strong><?= e($instance['label']) ?></strong><small class="mono"><?= e($instance['unit']) ?></small></span>
            <span class="badge <?= e($cls) ?>"><span class="bdot"></span><?= e($label) ?></span>
          </button>
        <?php endforeach; ?>
      </div>

      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Service</th><th>Instance</th><th>Status</th><th>Start at boot</th><th style="text-align:right">Quick actions</th></tr></thead>
          <tbody>
          <?php foreach ($services as $svc): [$cls, $label] = $statusBadge($svc['status']); $controllable = $svc['status'] !== 'not-installed' && is_linux(); ?>
            <tr>
              <td style="font-weight:600"><?= e(ucwords(str_replace(['-', '.service'], [' ', ''], $svc['name']))) ?></td>
              <td class="mono text-tertiary"><?= e($svc['name']) ?></td>
              <td><span class="badge <?= e($cls) ?>"><span class="bdot"></span><?= e($label) ?></span></td>
              <td><span class="badge <?= $svc['enabled'] === true ? 'badge-blue' : 'badge-slate' ?>"><?= $svc['enabled'] === true ? 'Enabled' : ($svc['enabled'] === false ? 'Disabled' : 'N/A') ?></span></td>
              <td style="text-align:right">
                <?php if ($controllable): ?>
                  <button class="btn btn-secondary btn-sm" data-service-action="start" data-service-name="<?= e($svc['name']) ?>"><i data-lucide="play"></i></button>
                  <button class="btn btn-secondary btn-sm" data-service-action="restart" data-service-name="<?= e($svc['name']) ?>"><i data-lucide="rotate-cw"></i></button>
                  <button class="btn btn-danger btn-sm" data-service-action="stop" data-service-name="<?= e($svc['name']) ?>"><i data-lucide="square"></i></button>
                <?php else: ?><span class="text-tertiary">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php foreach ($instances as $instance):
      $unit = $instance['unit'];
      $panelId = 'svc-' . preg_replace('/[^a-z0-9]+/i', '-', $unit);
      $status = service_status($unit);
      $enabled = service_enabled($unit);
      [$cls, $label] = $statusBadge($status);
      [$logCode, $logs] = run_cmd('journalctl -u ' . escapeshellarg($unit) . ' -n 80 --no-pager 2>&1', 20);
      if ($logCode !== 0) { [$logCode, $logs] = sudo_cmd('journalctl -u ' . escapeshellarg($unit) . ' -n 80 --no-pager', 20); }
      $isWeb = in_array($unit, ['nginx', 'apache2'], true);
      $isPhp = (bool) preg_match('/^php([0-9.]+)-fpm$/', $unit, $phpMatch);
    ?>
      <div id="<?= e($panelId) ?>" data-tab-panel class="hidden">
        <div class="service-instance-head">
          <div class="flex items-center gap-3"><span class="svc-icon"><i data-lucide="<?= e($instance['icon']) ?>"></i></span><div><h2><?= e($instance['label']) ?></h2><span class="mono"><?= e($unit) ?></span></div></div>
          <div class="flex items-center gap-2"><span class="badge <?= e($cls) ?>"><span class="bdot"></span><?= e($label) ?></span><span class="badge <?= $enabled ? 'badge-blue' : 'badge-slate' ?>"><?= $enabled ? 'Boot enabled' : 'Boot disabled' ?></span></div>
        </div>
        <div class="tabs" data-instance-tabs>
          <button class="tab active" type="button" data-tab-target="<?= e($panelId) ?>-status" data-tab-panel-group="#<?= e($panelId) ?>-panels">Status &amp; control</button>
          <?php if ($isWeb): ?><button class="tab" type="button" data-tab-target="<?= e($panelId) ?>-sites" data-tab-panel-group="#<?= e($panelId) ?>-panels">Virtual hosts</button><?php endif; ?>
          <?php if ($isPhp): ?><button class="tab" type="button" data-tab-target="<?= e($panelId) ?>-php" data-tab-panel-group="#<?= e($panelId) ?>-panels">PHP settings</button><?php endif; ?>
          <button class="tab" type="button" data-tab-target="<?= e($panelId) ?>-logs" data-tab-panel-group="#<?= e($panelId) ?>-panels">Logs</button>
        </div>
        <div id="<?= e($panelId) ?>-panels">
          <div id="<?= e($panelId) ?>-status" data-tab-panel class="card-pad">
            <div class="grid grid-3" style="margin-bottom:18px">
              <div class="stat-card"><div class="stat-label">Current status</div><div class="stat-val" style="font-size:22px"><?= e($label) ?></div></div>
              <div class="stat-card"><div class="stat-label">Start at boot</div><div class="stat-val" style="font-size:22px"><?= $enabled ? 'Enabled' : 'Disabled' ?></div></div>
              <div class="stat-card"><div class="stat-label">Systemd instance</div><div class="stat-val mono" style="font-size:17px"><?= e($unit) ?></div></div>
            </div>
            <div class="flex gap-2" style="flex-wrap:wrap">
              <button class="btn btn-secondary" data-service-action="start" data-service-name="<?= e($unit) ?>"><i data-lucide="play"></i>Start</button>
              <button class="btn btn-primary" data-service-action="restart" data-service-name="<?= e($unit) ?>"><i data-lucide="rotate-cw"></i>Restart</button>
              <button class="btn btn-danger" data-service-action="stop" data-service-name="<?= e($unit) ?>"><i data-lucide="square"></i>Stop</button>
              <?php if ($enabled !== null): ?><button class="btn btn-secondary" data-service-action="<?= $enabled ? 'disable' : 'enable' ?>" data-service-name="<?= e($unit) ?>"><i data-lucide="power"></i><?= $enabled ? 'Disable at boot' : 'Enable at boot' ?></button><?php endif; ?>
            </div>
          </div>
          <?php if ($isWeb): ?>
            <div id="<?= e($panelId) ?>-sites" data-tab-panel class="hidden table-wrap"><table class="data-table"><thead><tr><th>Domain</th><th>Document root</th><th>PHP</th><th>SSL</th></tr></thead><tbody>
              <?php if (!$sites): ?><tr><td colspan="4" class="text-tertiary" style="text-align:center;padding:24px">No panel-managed websites.</td></tr><?php endif; ?>
              <?php foreach ($sites as $site): ?><tr><td><a href="<?= e(url('websites')) ?>"><?= e($site['domain'] ?? '') ?></a></td><td class="mono"><?= e($site['docroot'] ?? '') ?></td><td class="mono"><?= e($site['php'] ?? '') ?></td><td><span class="badge <?= !empty($site['ssl']) ? 'badge-emerald' : 'badge-slate' ?>"><?= !empty($site['ssl']) ? 'Enabled' : 'HTTP' ?></span></td></tr><?php endforeach; ?>
            </tbody></table></div>
          <?php endif; ?>
          <?php if ($isPhp): ?>
            <div id="<?= e($panelId) ?>-php" data-tab-panel class="hidden card-pad"><p class="text-secondary" style="font-size:13px;margin-top:0">Manage php.ini values and loaded extensions for PHP <?= e($phpMatch[1]) ?>.</p><a class="btn btn-primary" href="<?= e(url('php', ['version' => $phpMatch[1]])) ?>"><i data-lucide="settings"></i>Open PHP <?= e($phpMatch[1]) ?> manager</a></div>
          <?php endif; ?>
          <div id="<?= e($panelId) ?>-logs" data-tab-panel class="hidden"><div class="term-window" style="border:0;border-radius:0"><div class="term-titlebar"><i data-lucide="scroll-text"></i><span><?= e($unit) ?> · last 80 lines</span></div><pre class="term-body" style="height:460px;margin:0;white-space:pre-wrap"><?= e($logs !== '' ? $logs : '(no journal output)') ?></pre></div></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  document.getElementById('servicesRefresh')?.addEventListener('click', () => location.reload());
  document.querySelectorAll('[data-open-service]').forEach((card) => card.addEventListener('click', () => document.querySelector(`[data-tab-target="${card.dataset.openService}"]`)?.click()));
  document.querySelectorAll('[data-service-action]').forEach((btn) => btn.addEventListener('click', async () => {
    btn.disabled = true;
    const res = await apiPost('services', { name: btn.dataset.serviceName, action: btn.dataset.serviceAction });
    btn.disabled = false;
    if (res.ok) { toast(`${btn.dataset.serviceName}: ${btn.dataset.serviceAction} complete`, 'success'); setTimeout(() => location.reload(), 350); }
    else toast(res.error || 'Service action failed', 'error');
  }));
});
</script>
