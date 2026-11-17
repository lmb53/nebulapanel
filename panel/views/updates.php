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
        <div class="dropdown" style="position:relative">
          <button class="btn btn-secondary btn-sm btn-icon" type="button" id="updMore" aria-haspopup="true" aria-expanded="false" aria-label="More upgrade options"><i data-lucide="chevron-down"></i></button>
          <div class="dropdown-menu menu-list hidden" id="updMoreMenu" role="menu" style="top:calc(100% + 4px);width:320px">
            <button class="menu-item" type="button" role="menuitem" id="updFull"><i data-lucide="layers"></i><span>Full upgrade<span class="text-tertiary" style="display:block;font-size:12px">Also installs new dependencies and removes obsolete packages (kernels, point releases)</span></span></button>
            <button class="menu-item" type="button" role="menuitem" id="updAutoremove"><i data-lucide="eraser"></i><span>Remove unused packages<span class="text-tertiary" style="display:block;font-size:12px">apt-get autoremove — old kernels and orphaned libraries</span></span></button>
          </div>
        </div>
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

  <div class="card" style="margin-top:16px" id="updAutoCard">
    <div class="card-header"><div><h3>Automatic security updates</h3><span class="muted">unattended-upgrades installs security fixes every day</span></div><span class="badge badge-slate" id="updAutoBadge"><span class="bdot"></span>Checking…</span></div>
    <form class="card-pad form-stack" id="updAutoForm" novalidate>
      <label class="check-line"><input type="checkbox" id="updAutoOn" disabled><span>Install security updates automatically</span></label>
      <div class="flex gap-2" style="align-items:center;flex-wrap:wrap">
        <label class="check-line"><input type="checkbox" id="updAutoReboot" disabled><span>Reboot automatically when an update requires it, at</span></label>
        <input class="input mono" id="updAutoTime" type="time" value="04:00" style="width:auto" aria-label="Reboot time" disabled>
      </div>
      <div class="field-help" id="updAutoHelp"></div>
      <div><button class="btn btn-primary btn-sm" type="submit" id="updAutoSave" disabled><i data-lucide="save"></i>Save</button></div>
    </form>
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

<script nonce="<?= e(csp_nonce()) ?>">
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
  const more = document.getElementById('updMore'), moreMenu = document.getElementById('updMoreMenu');
  more?.addEventListener('click', (e) => { e.stopPropagation(); const open = moreMenu.classList.toggle('hidden') === false; more.setAttribute('aria-expanded', String(open)); if (open) moreMenu.querySelector('button')?.focus(); });
  document.addEventListener('click', (e) => { if (moreMenu && !moreMenu.contains(e.target)) { moreMenu.classList.add('hidden'); more?.setAttribute('aria-expanded', 'false'); } });
  moreMenu?.addEventListener('keydown', (e) => { if (e.key === 'Escape') { moreMenu.classList.add('hidden'); more.focus(); } });
  document.getElementById('updFull')?.addEventListener('click', async (e) => {
    moreMenu.classList.add('hidden');
    const preview = await window.Nebula.apiPost('updates', { action: 'preview' });
    if (!preview.ok) { toast(preview.error || 'Could not preview the upgrade', 'error'); return; }
    const parts = [];
    if (preview.new.length) parts.push('New packages: ' + preview.new.join(', ') + '.');
    if (preview.remove.length) parts.push('REMOVED: ' + preview.remove.join(', ') + '.');
    if (!parts.length) parts.push('Nothing beyond a normal upgrade would change.');
    if (!await window.Nebula.confirm({ title: 'Run a full upgrade?', message: parts.join(' ') + ' Locally changed configuration files are kept.', danger: preview.remove.length > 0, warning: true, icon: 'layers', confirmLabel: 'Full upgrade' })) return;
    run(more, 'full-upgrade', { done: 'Full upgrade complete', reload: true });
  });
  document.getElementById('updAutoremove')?.addEventListener('click', () => {
    moreMenu.classList.add('hidden');
    run(more, 'autoremove', { confirm: 'Remove unused packages?', confirmLabel: 'Remove', done: 'Unused packages removed', reload: true });
  });
  // Automatic security updates
  const autoOn = document.getElementById('updAutoOn'), autoReboot = document.getElementById('updAutoReboot'), autoTime = document.getElementById('updAutoTime');
  const autoBadge = document.getElementById('updAutoBadge'), autoHelp = document.getElementById('updAutoHelp'), autoSave = document.getElementById('updAutoSave');
  const syncAuto = () => { autoReboot.disabled = !autoOn.checked; autoTime.disabled = !autoOn.checked || !autoReboot.checked; };
  if (autoOn) {
    window.Nebula.apiPost('updates', { action: 'auto-status' }).then((st) => {
      if (!st.ok) { autoBadge.lastChild.textContent = 'Unavailable'; autoHelp.textContent = st.error || ''; return; }
      autoOn.checked = st.enabled; autoReboot.checked = st.auto_reboot; if (st.reboot_time) autoTime.value = st.reboot_time;
      autoBadge.className = 'badge ' + (st.enabled ? 'badge-emerald' : 'badge-slate'); autoBadge.lastChild.textContent = st.enabled ? 'On' : 'Off';
      autoHelp.textContent = (st.installed ? '' : 'unattended-upgrades will be installed when you turn this on. ') + (st.last_run ? 'Last automatic run: ' + window.Nebula.fmtDate(st.last_run) + '.' : '');
      autoOn.disabled = false; autoSave.disabled = false; syncAuto();
    });
    autoOn.addEventListener('change', syncAuto); autoReboot.addEventListener('change', syncAuto);
    document.getElementById('updAutoForm').addEventListener('submit', async (event) => {
      event.preventDefault();
      if (autoReboot.checked && !/^\d{2}:\d{2}$/.test(autoTime.value)) { window.Nebula.setFieldError(autoTime, 'Choose a reboot time.'); return; }
      window.Nebula.setBusy(autoSave, true);
      const res = await window.Nebula.runTask({ title: autoOn.checked ? 'Turning on automatic updates' : 'Turning off automatic updates', endpoint: 'updates',
        body: { action: 'auto-set', enabled: autoOn.checked, reboot: autoReboot.checked, reboot_time: autoTime.value }, successMessage: 'Saved' });
      window.Nebula.setBusy(autoSave, false);
      if (res.ok) { toast('Automatic updates ' + (autoOn.checked ? 'on' : 'off'), 'success'); autoBadge.className = 'badge ' + (autoOn.checked ? 'badge-emerald' : 'badge-slate'); autoBadge.lastChild.textContent = autoOn.checked ? 'On' : 'Off'; }
    });
  }
  document.querySelectorAll('[data-upd-package]').forEach((btn) => btn.addEventListener('click', () =>
    run(btn, 'install', { confirm: 'Install the update for ' + btn.dataset.updPackage + '?', confirmLabel: 'Install update', done: btn.dataset.updPackage + ' updated', reload: true }, { package: btn.dataset.updPackage })));
});
</script>
