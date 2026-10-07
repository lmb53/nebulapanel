<?php
/** @var array $config */
require_once APP_ROOT . '/lib/mod_settings.php';
$panelName = $config['panel_name'];
$timeout = (int) ($config['session_timeout'] ?? 1800);
$healthWarn = (int) ($config['health_warn_percent'] ?? 80);
$healthCritical = (int) ($config['health_critical_percent'] ?? 90);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Settings</h1>
    <p class="page-subtitle">Panel-wide preferences shared by every account</p>
  </div>
</div>

<div class="grid grid-main-side">
  <div class="card">
    <div class="card-header"><h3>General</h3></div>
    <form class="card-pad form-stack" id="settingsForm" novalidate>
      <div>
        <label class="field-label" for="setPanelName">Panel name<span class="req" aria-hidden="true">*</span></label>
        <input class="input" id="setPanelName" required maxlength="64" value="<?= e($panelName) ?>">
        <div class="field-help">Shown in the sidebar, the sign-in page and browser tabs.</div>
      </div>
      <div>
        <label class="field-label" for="setTimeout">Idle session timeout (seconds)<span class="req" aria-hidden="true">*</span></label>
        <input class="input mono" id="setTimeout" type="number" required min="60" max="86400" value="<?= e($timeout) ?>">
        <div class="field-help">Between 60 and 86,400. Idle sessions are signed out after this long.</div>
      </div>
      <div class="form-grid" style="--cols:1fr 1fr">
        <div>
          <label class="field-label" for="setHealthWarn">Resource warning at (%)</label>
          <input class="input mono" id="setHealthWarn" type="number" required min="50" max="95" value="<?= e($healthWarn) ?>">
        </div>
        <div>
          <label class="field-label" for="setHealthCritical">Resource critical at (%)</label>
          <input class="input mono" id="setHealthCritical" type="number" required min="60" max="100" value="<?= e($healthCritical) ?>">
        </div>
      </div>
      <div class="field-help" style="margin-top:-8px">Used by the dashboard health checks and the CPU, memory and disk meters in the top bar.</div>
      <div><button class="btn btn-primary" type="submit" id="setSaveGeneral"><i data-lucide="save"></i>Save changes</button></div>
    </form>
  </div>

  <div class="card">
    <div class="card-header"><h3>Related</h3></div>
    <div class="menu-list" style="padding:8px">
      <a class="menu-item" href="<?= e(url('account')) ?>"><i data-lucide="user-round-cog"></i>My account &amp; password</a>
      <a class="menu-item" href="<?= e(url('firewall')) ?>#audit"><i data-lucide="file-clock"></i>Audit log</a>
      <a class="menu-item" href="<?= e(url('users')) ?>"><i data-lucide="users"></i>Panel users &amp; roles</a>
      <a class="menu-item" href="<?= e(url('api')) ?>"><i data-lucide="braces"></i>API tokens</a>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast, validateForm, setBusy } = window.Nebula;
  const form = document.getElementById('settingsForm');
  const warn = document.getElementById('setHealthWarn');
  const crit = document.getElementById('setHealthCritical');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const extra = [];
    if (Number(warn.value) >= Number(crit.value)) extra.push([crit, 'Must be higher than the warning level.']);
    if (!validateForm(form, extra)) return;
    const button = document.getElementById('setSaveGeneral');
    setBusy(button, true);
    const res = await apiPost('settings', {
      action: 'general',
      panel_name: document.getElementById('setPanelName').value.trim(),
      session_timeout: document.getElementById('setTimeout').value,
      health_warn_percent: warn.value,
      health_critical_percent: crit.value,
    });
    setBusy(button, false);
    if (res.ok) { toast('Settings saved', 'success'); setTimeout(() => location.reload(), 600); }
    else toast(res.error || 'Could not save settings', 'error');
  });
});
</script>
