<?php
require_once APP_ROOT . '/lib/mod_apps.php';
$name = (string) ($_GET['name'] ?? '');
$services = manageable_services();
$svc = null;
foreach ($services as $s) {
    if ($s['unit'] === $name) { $svc = $s; break; }
}
?>
<?php if ($svc === null): ?>
  <div class="page-header"><div><nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= e(url('services')) ?>">Services</a><i data-lucide="chevron-right" aria-hidden="true"></i><span><?= e($name) ?></span></nav><h1 class="page-title"><?= e($name !== '' ? service_display_name($name) : 'Service') ?></h1></div></div>
  <?= requirement_missing('server-off', 'This service is not installed', 'The panel only manages services that are installed on this server.', [['label' => 'All services', 'href' => url('services'), 'icon' => 'arrow-left'], ['label' => 'Install Apps', 'href' => url('apps'), 'icon' => 'package-plus']]) ?>
<?php else: ?>
  <?php
    $status = service_status($name);
    $enabled = service_enabled($name);
    $badge = [
        'active'   => ['badge-emerald', 'Running'],
        'inactive' => ['badge-slate',   'Stopped'],
        'failed'   => ['badge-red',     'Failed'],
    ][$status] ?? ['badge-slate', ucfirst($status)];
    [$jc, $jout] = run_cmd('journalctl -u ' . escapeshellarg($name) . ' -n 80 --no-pager 2>&1');
    if ($jc !== 0) {
        [$jc, $jout] = sudo_cmd('journalctl -u ' . escapeshellarg($name) . ' -n 80 --no-pager');
    }
  ?>
  <div class="page-header">
    <div>
      <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= e(url('services')) ?>">Services</a><i data-lucide="chevron-right" aria-hidden="true"></i><span aria-current="page"><?= e($svc['label']) ?></span></nav>
      <h1 class="page-title"><?= e($svc['label']) ?></h1>
      <p class="page-subtitle">systemd unit <span class="mono"><?= e($name) ?></span></p>
    </div>
    <div class="page-actions" data-svc="<?= e($name) ?>">
      <span class="badge <?= e($badge[0]) ?>" data-svc-badge style="align-self:center"><span class="bdot"></span><?= e($badge[1]) ?></span>
      <span class="badge <?= $enabled === true ? 'badge-blue' : 'badge-slate' ?>" data-svc-enabled><?= $enabled === true ? 'Boot enabled' : ($enabled === false ? 'Boot disabled' : 'Boot N/A') ?></span>
      <?php if (role_can('services.control')): ?>
        <?php $running = $status === 'active'; ?>
        <button class="btn btn-secondary" type="button" data-action="start"<?= $running ? ' disabled' : '' ?>><i data-lucide="play"></i>Start</button>
        <button class="btn btn-secondary" type="button" data-action="restart"<?= $running ? '' : ' disabled' ?>><i data-lucide="rotate-cw"></i>Restart</button>
        <button class="btn btn-danger" type="button" data-action="stop"<?= $running ? '' : ' disabled' ?>><i data-lucide="square"></i>Stop</button>
      <?php endif; ?>
      <?php if ($enabled !== null && role_can('services.control')): ?><button class="btn btn-secondary" type="button" data-action="<?= $enabled ? 'disable' : 'enable' ?>" data-enable-toggle><i data-lucide="power"></i><?= $enabled ? 'Disable at boot' : 'Enable at boot' ?></button><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Recent logs</h3><span class="muted">journalctl · last 80 lines</span></div>
    <pre class="mono log-pre"><?= e($jout !== '' ? $jout : '(no journal output — may require a sudoers rule for journalctl)') ?></pre>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const { apiPost, toast } = window.Nebula;
    const wrap = document.querySelector('[data-svc]');
    const name = wrap.dataset.svc;
    const map = { active: ['badge-emerald', 'Running'], inactive: ['badge-slate', 'Stopped'], failed: ['badge-red', 'Failed'] };
    wrap.querySelectorAll('[data-action]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        const res = await apiPost('services', { name, action: btn.dataset.action });
        if (!res.ok) btn.disabled = false;
        if (res.ok) {
          toast(name + ': ' + btn.dataset.action + ' complete', 'success'); if (btn.dataset.enableToggle !== undefined) btn.disabled = false;
          window.Nebula.updateSvcBadge(wrap, res.status);
          const boot = wrap.querySelector('[data-svc-enabled]');
          if (boot) { boot.className = 'badge ' + (res.enabled === true ? 'badge-blue' : 'badge-slate'); boot.textContent = res.enabled === true ? 'Boot enabled' : (res.enabled === false ? 'Boot disabled' : 'Boot N/A'); }
          const toggle = wrap.querySelector('[data-enable-toggle]');
          if (toggle && res.enabled !== null) { toggle.dataset.action = res.enabled ? 'disable' : 'enable'; toggle.lastChild.textContent = res.enabled ? 'Disable at boot' : 'Enable at boot'; }
        } else toast(res.error || 'Action failed', 'error');
      });
    });
  });
  </script>
<?php endif; ?>
