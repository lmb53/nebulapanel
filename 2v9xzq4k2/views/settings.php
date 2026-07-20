<?php
/** @var array $config */
require_once APP_ROOT . '/lib/mod_settings.php';
$panelName = $config['panel_name'];
$timeout = (int) ($config['session_timeout'] ?? 1800);
$auditLog = audit_tail(100);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Settings</h1>
    <p class="page-subtitle">Panel preferences, admin credentials, and the audit log</p>
  </div>
</div>

<div class="grid" style="grid-template-columns:1fr 1fr;margin-bottom:16px">
  <div class="card">
    <div class="card-header"><h3>General</h3></div>
    <div class="card-pad">
      <label class="field-label">Panel name</label>
      <input class="input" id="setPanelName" value="<?= e($panelName) ?>" style="margin-bottom:14px">
      <label class="field-label">Session timeout (seconds)</label>
      <input class="input mono" id="setTimeout" type="number" min="60" max="86400" value="<?= e($timeout) ?>" style="margin-bottom:18px">
      <button class="btn btn-primary" id="setSaveGeneral"><i data-lucide="save"></i>Save changes</button>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Change admin password</h3></div>
    <div class="card-pad">
      <label class="field-label">Current password</label>
      <input class="input" id="setCurPw" type="password" autocomplete="current-password" style="margin-bottom:14px">
      <label class="field-label">New password <span style="color:var(--text-tertiary);font-weight:400">(min 8)</span></label>
      <input class="input" id="setNewPw" type="password" autocomplete="new-password" style="margin-bottom:14px">
      <label class="field-label">Confirm new password</label>
      <input class="input" id="setNewPw2" type="password" autocomplete="new-password" style="margin-bottom:18px">
      <button class="btn btn-primary" id="setSavePw"><i data-lucide="key-round"></i>Update password</button>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Audit log</h3><span class="muted">Last 100 entries</span></div>
  <pre class="mono" style="margin:0;padding:16px;overflow:auto;font-size:12px;line-height:1.55;max-height:50vh;white-space:pre-wrap"><?= e($auditLog !== '' ? $auditLog : 'No audit entries yet.') ?></pre>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;

  document.getElementById('setSaveGeneral')?.addEventListener('click', async () => {
    const res = await apiPost('settings', {
      action: 'general',
      panel_name: document.getElementById('setPanelName').value,
      session_timeout: document.getElementById('setTimeout').value,
    });
    if (res.ok) { toast('Settings saved', 'success'); setTimeout(() => location.reload(), 600); }
    else toast(res.error || 'Failed', 'error');
  });

  document.getElementById('setSavePw')?.addEventListener('click', async () => {
    const cur = document.getElementById('setCurPw').value;
    const nw = document.getElementById('setNewPw').value;
    const nw2 = document.getElementById('setNewPw2').value;
    if (nw !== nw2) { toast('New passwords do not match', 'error'); return; }
    const res = await apiPost('settings', { action: 'password', current: cur, new: nw });
    if (res.ok) {
      toast('Password updated', 'success');
      ['setCurPw', 'setNewPw', 'setNewPw2'].forEach((id) => (document.getElementById(id).value = ''));
    } else toast(res.error || 'Failed', 'error');
  });
});
</script>
