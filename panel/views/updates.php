<?php
require_once APP_ROOT . '/lib/mod_updates.php';
$available = upd_available();
$pkgs = $available ? upd_list() : [];
$updatesTab = ($updatesTab ?? '') === 'panel' ? 'panel' : 'system';
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Updates</h1>
    <p class="page-subtitle">Operating-system packages and the Nebula Panel itself</p>
  </div>
</div>

<div class="tabs page-tabs" id="updatesTabs">
  <button class="tab<?= $updatesTab === 'system' ? ' active' : '' ?>" type="button" data-tab-target="updSystem" data-tab-hash="system"><i data-lucide="package"></i>System packages <?php if ($available): ?><span class="badge <?= $pkgs ? 'badge-orange' : 'badge-slate' ?>"><?= count($pkgs) ?></span><?php endif; ?></button>
  <button class="tab<?= $updatesTab === 'panel' ? ' active' : '' ?>" type="button" data-tab-target="updPanel" data-tab-hash="panel"><i data-lucide="zap"></i>Nebula Panel</button>
</div>

<div data-tab-panels>
<section id="updSystem" data-tab-panel<?= $updatesTab === 'system' ? '' : ' class="hidden"' ?>>
<?php if (!$available): ?>
  <?= requirement_missing('package', 'apt is not available', 'The <span class="mono">apt-get</span> command was not found on this system.') ?>
<?php else: ?>
  <div class="card">
    <div class="card-header">
      <div><h3>Available package updates</h3><span class="muted"><?= count($pkgs) ? count($pkgs) . ' update' . (count($pkgs) === 1 ? '' : 's') . ' available' : 'System is up to date' ?></span></div>
      <div class="flex gap-2">
        <button class="btn btn-secondary btn-sm" type="button" id="updRefresh" title="Run apt-get update and reload this list"><i data-lucide="refresh-cw"></i>Check for updates</button>
        <button class="btn btn-primary btn-sm" type="button" id="updUpgrade"<?= $pkgs ? '' : ' disabled title="Nothing to upgrade"' ?>><i data-lucide="arrow-up-circle"></i>Upgrade all</button>
      </div>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Package</th><th>Installed</th><th>Available</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          <?php foreach ($pkgs as $p): ?>
            <tr>
              <td style="font-weight:600"><?= e($p['package']) ?></td>
              <td class="mono text-tertiary"><?= e($p['current']) ?></td>
              <td class="mono text-blue"><?= e($p['candidate']) ?></td>
              <td class="actions-col"><button class="btn btn-secondary btn-sm" type="button" data-upd-package="<?= e($p['package']) ?>"><i data-lucide="download"></i>Install</button></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$pkgs): ?>
            <tr class="empty-row"><td colspan="4">No updates available. Use “Check for updates” to refresh the package index.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card hidden" id="updOutputCard" style="margin-top:16px">
    <div class="card-header"><h3>Command output</h3></div>
    <pre id="updOutput" class="mono log-pre"></pre>
  </div>
<?php endif; ?>
</section>
<section id="updPanel" data-tab-panel<?= $updatesTab === 'panel' ? '' : ' class="hidden"' ?>>
<?php require APP_ROOT . '/views/selfupdate.php'; ?>
</section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { streamPost, toast } = window.Nebula;
  const out = document.getElementById('updOutput');

  async function run(btn, action, opts, extra) {
    if (opts && opts.confirm && !await window.Nebula.confirm({ title: opts.confirm, warning: true, icon: 'download-cloud', confirmLabel: opts.confirmLabel || 'Continue' })) return;
    btn.disabled = true;
    document.getElementById('updOutputCard')?.classList.remove('hidden');
    if (out) out.textContent = '';
    toast('Running…', 'info');
    try {
      const res = await streamPost('updates', Object.assign({ action }, extra || {}), (event) => {
        if (out && event.type === 'output') {
          out.textContent += event.text || '';
          out.scrollTop = out.scrollHeight;
        }
      });
      if (out && !out.textContent.trim()) out.textContent = res.output || res.error || '';
      if (res.ok) {
        toast(opts && opts.done ? opts.done : 'Done', 'success');
        if (opts && opts.reload) setTimeout(() => { location.hash = 'system'; location.reload(); }, 900);
      } else {
        toast(res.error || 'Failed', 'error');
      }
    } finally {
      btn.disabled = false;
    }
  }

  document.getElementById('updRefresh')?.addEventListener('click', (e) =>
    run(e.currentTarget, 'refresh', { done: 'Package index refreshed', reload: true }));
  document.getElementById('updUpgrade')?.addEventListener('click', (e) =>
    run(e.currentTarget, 'upgrade', { confirm: 'Upgrade all packages now?', confirmLabel: 'Upgrade all', done: 'Upgrade complete', reload: true }));
  document.querySelectorAll('[data-upd-package]').forEach((btn) => btn.addEventListener('click', () =>
    run(btn, 'install', { confirm: 'Install the update for ' + btn.dataset.updPackage + '?', confirmLabel: 'Install update', done: btn.dataset.updPackage + ' updated', reload: true }, { package: btn.dataset.updPackage })));
});
</script>
