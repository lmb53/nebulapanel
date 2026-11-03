<?php
/** @var array $config */
require_once APP_ROOT . '/lib/mod_settings.php';
require_once APP_ROOT . '/lib/mod_alerts.php';
$alerts = alerts_config();
$alertState = alerts_state();
$lastDelivery = $alertState['deliveries'][0] ?? null;
$hasSendmail = is_executable('/usr/sbin/sendmail');
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
        <input class="input" id="setPanelName" required maxlength="60" value="<?= e($panelName) ?>">
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

<div class="card" style="margin-top:16px" id="alerts">
  <div class="card-header"><div><h3>Alerts</h3><span class="muted">Email or chat notifications when something needs attention, checked every five minutes</span></div><span class="badge <?= $alerts['enabled'] ? 'badge-emerald' : 'badge-slate' ?>"><span class="bdot"></span><?= $alerts['enabled'] ? 'On' : 'Off' ?></span></div>
  <form class="card-pad form-stack" id="alertsForm" novalidate>
    <label class="check-line"><input type="checkbox" id="alEnabled"<?= $alerts['enabled'] ? ' checked' : '' ?>><span>Send alerts</span></label>
    <div class="grid grid-2" style="gap:16px">
      <div>
        <label class="field-label" for="alEmails">Email recipients</label>
        <input class="input" id="alEmails" type="text" autocomplete="off" placeholder="ops@example.com, you@example.com" value="<?= e(implode(', ', $alerts['emails'])) ?>" aria-describedby="alEmailsHelp">
        <div class="field-help" id="alEmailsHelp">Comma-separated, up to <?= ALERT_MAX_RECIPIENTS ?>. Sent through this server’s mail system<?= $hasSendmail ? '' : ' — <strong>no local mail server is installed</strong>, so use a webhook or set up Email first' ?>.</div>
      </div>
      <fieldset class="check-group">
        <legend class="field-label">Alert me about</legend>
        <?php foreach (alert_categories() as $key => $category): ?>
          <label class="check-line"><input type="checkbox" data-al-event="<?= e($key) ?>"<?= !empty($alerts['events'][$key]) ? ' checked' : '' ?>><span><?= e($category['label']) ?></span></label>
        <?php endforeach; ?>
      </fieldset>
    </div>
    <div>
      <div class="field-label">Webhooks</div>
      <div id="alHooks" class="form-stack" style="gap:8px"></div>
      <button class="btn btn-secondary btn-sm" type="button" id="alAddHook" style="margin-top:8px"><i data-lucide="plus"></i>Add webhook</button>
      <div class="field-help" style="margin-top:6px">Slack and Discord: paste the channel’s incoming-webhook URL. ntfy: the topic URL, e.g. <span class="mono">https://ntfy.sh/my-server-alerts</span>. Generic JSON posts <span class="mono">{level, subject, text, events}</span>.</div>
    </div>
    <div class="form-grid" style="--cols:minmax(0,240px) 1fr;align-items:end">
      <div>
        <label class="field-label" for="alRemind">Remind every (hours)</label>
        <input class="input mono" id="alRemind" type="number" min="0" max="168" value="<?= (int) $alerts['remind_hours'] ?>" aria-describedby="alRemindHelp">
        <div class="field-help" id="alRemindHelp">While a problem persists. 0 = only once.</div>
      </div>
      <label class="check-line"><input type="checkbox" id="alResolved"<?= $alerts['notify_resolved'] ? ' checked' : '' ?>><span>Also notify when a problem is resolved</span></label>
    </div>
    <?php if ($lastDelivery): $failedDeliveries = array_filter((array) $lastDelivery['results'], fn($r) => empty($r['ok'])); ?>
      <div class="notice <?= $failedDeliveries ? 'notice-danger' : 'notice-info' ?>"><i data-lucide="<?= $failedDeliveries ? 'circle-x' : 'send' ?>"></i><div>Last alert sent <?= e(fmt_datetime((int) $lastDelivery['at'])) ?><?php foreach ($failedDeliveries as $r): ?><div><?= e($r['channel'] . ': ' . $r['error']) ?></div><?php endforeach; ?></div></div>
    <?php endif; ?>
    <div class="flex gap-2" style="flex-wrap:wrap">
      <button class="btn btn-primary" type="submit" id="alSave"><i data-lucide="save"></i>Save alerts</button>
      <button class="btn btn-secondary" type="button" id="alTest"><i data-lucide="send"></i>Send test alert</button>
    </div>
  </form>
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
  // ---- Alerts ----
  const hookTypes = <?= json_encode(alert_webhook_types()) ?>;
  const hooks = document.getElementById('alHooks');
  const addHook = (hook = { type: 'slack', url: '' }) => {
    const row = document.createElement('div'); row.className = 'form-grid'; row.style.setProperty('--cols', '150px minmax(0,1fr) auto'); row.dataset.alHook = '';
    const type = document.createElement('select'); type.className = 'select'; type.setAttribute('aria-label', 'Webhook type');
    Object.entries(hookTypes).forEach(([value, label]) => { const o = document.createElement('option'); o.value = value; o.textContent = label; type.appendChild(o); });
    type.value = hook.type;
    const url = document.createElement('input'); url.className = 'input mono'; url.type = 'url'; url.placeholder = 'https://…'; url.value = hook.url; url.setAttribute('aria-label', 'Webhook URL'); url.autocomplete = 'off';
    const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'icon-btn danger'; remove.title = 'Remove webhook'; remove.setAttribute('aria-label', 'Remove webhook');
    remove.innerHTML = '<i data-lucide="x"></i>'; remove.addEventListener('click', () => row.remove());
    row.append(type, url, remove); hooks.appendChild(row);
    if (window.lucide) lucide.createIcons();
    return url;
  };
  <?= json_encode($alerts['webhooks']) ?>.forEach((hook) => addHook(hook));
  document.getElementById('alAddHook').addEventListener('click', () => addHook().focus());
  const alertBody = () => ({
    action: 'alerts', enabled: document.getElementById('alEnabled').checked,
    emails: document.getElementById('alEmails').value,
    webhooks: Array.from(hooks.querySelectorAll('[data-al-hook]')).map((row) => ({ type: row.querySelector('select').value, url: row.querySelector('input').value.trim() })).filter((h) => h.url),
    events: Object.fromEntries(Array.from(document.querySelectorAll('[data-al-event]')).map((cb) => [cb.dataset.alEvent, cb.checked])),
    remind_hours: Number(document.getElementById('alRemind').value || 0),
    notify_resolved: document.getElementById('alResolved').checked,
  });
  document.getElementById('alertsForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = document.getElementById('alSave'); setBusy(button, true);
    const res = await apiPost('settings', alertBody());
    setBusy(button, false);
    if (res.ok) { toast('Alert settings saved', 'success'); setTimeout(() => location.reload(), 600); }
    else toast(res.error || 'Could not save alert settings', 'error');
  });
  document.getElementById('alTest').addEventListener('click', async (event) => {
    const button = event.currentTarget; setBusy(button, true);
    const saved = await apiPost('settings', alertBody());
    const res = saved.ok ? await apiPost('settings', { action: 'alerts-test' }) : saved;
    setBusy(button, false);
    toast(res.ok ? 'Test alert sent to ' + (res.results || []).map((r) => r.channel).join(', ') : (res.error || 'Test alert failed'), res.ok ? 'success' : 'error');
  });
});
</script>
