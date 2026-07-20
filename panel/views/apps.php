<?php
require_once APP_ROOT . '/lib/mod_apps.php';
$catalog = app_catalog();
$phpInstalled = php_installed_versions();
$phpAvailable = php_installable_versions();
$helper = helper_available();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Install Apps</h1>
    <p class="page-subtitle">Install server software and PHP versions — installed services appear in the sidebar for management</p>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>Applications</h3><span class="muted">apt packages</span></div>
  <div class="card-pad">
    <div class="grid grid-3" style="gap:14px">
      <?php foreach ($catalog as $key => $c): $installed = app_installed($key); ?>
        <div class="service-row" style="align-items:flex-start">
          <div class="svc-icon"><i data-lucide="<?= e($c['icon']) ?>" style="color:var(--blue-400)"></i></div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:13px"><?= e($c['label']) ?></div>
            <div style="font-size:11.5px;color:var(--text-tertiary);margin-bottom:8px"><?= e($c['desc']) ?></div>
            <?php if ($installed): ?>
              <span class="badge badge-emerald" style="margin-right:6px"><span class="bdot"></span>Installed</span>
              <button class="btn btn-danger btn-sm" data-app-uninstall="<?= e($key) ?>">Remove</button>
            <?php else: ?>
              <button class="btn btn-primary btn-sm" data-app-install="<?= e($key) ?>"><i data-lucide="download"></i>Install</button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>PHP versions</h3><span class="muted"><?= count($phpInstalled) ?> installed</span></div>
  <div class="card-pad">
    <div style="margin-bottom:14px">
      <div class="field-label">Installed</div>
      <div class="flex gap-2" style="flex-wrap:wrap">
        <?php if (!$phpInstalled): ?><span class="text-tertiary" style="font-size:13px">None detected</span><?php endif; ?>
        <?php foreach ($phpInstalled as $v): ?>
          <span class="badge badge-blue"><span class="bdot"></span>PHP <?= e($v) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div>
      <div class="field-label">Install another version <span style="color:var(--text-tertiary);font-weight:400">(via ondrej PPA)</span></div>
      <div class="flex gap-2" style="flex-wrap:wrap;align-items:center">
        <select class="select" id="phpVer" style="width:auto">
          <?php foreach ($phpAvailable as $v): ?><option value="<?= e($v) ?>">PHP <?= e($v) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-primary" id="phpInstall" <?= (!$helper || !$phpAvailable) ? 'disabled' : '' ?>><i data-lucide="download"></i>Install PHP</button>
      </div>
      <?php if (!$helper): ?>
        <div style="font-size:12px;color:var(--orange-400);margin-top:8px">Privileged helper not installed — re-run install.sh to enable PHP version installs.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card hidden" id="appLogCard">
  <div class="card-header"><h3>Output</h3><button class="btn btn-secondary btn-sm" id="appReload"><i data-lucide="refresh-cw"></i>Reload status</button></div>
  <pre class="mono" id="appLog" style="margin:0;padding:16px;font-size:12px;line-height:1.55;white-space:pre-wrap;max-height:40vh;overflow:auto"></pre>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { streamPost, toast } = window.Nebula;
  const logCard = document.getElementById('appLogCard');
  const logEl = document.getElementById('appLog');
  function resetLog() { logCard.classList.remove('hidden'); logEl.textContent = ''; }
  function appendLog(text) {
    if (!text) return;
    logCard.classList.remove('hidden');
    logEl.textContent += text;
    logEl.scrollTop = logEl.scrollHeight;
  }

  async function run(btn, body, verb) {
    const orig = btn.innerHTML;
    resetLog();
    btn.disabled = true; btn.innerHTML = verb + '…';
    if (window.lucide) lucide.createIcons();
    let res;
    try { res = await streamPost('apps', body, (event) => {
      if (event.type === 'output') appendLog(event.text);
    }); }
    catch (e) { toast('Request failed', 'error'); btn.disabled = false; btn.innerHTML = orig; return; }
    if (res.output && !logEl.textContent.trim()) appendLog(res.output + '\n');
    if (res.ok) { toast('Done — output will remain here', 'success'); btn.innerHTML = '<i data-lucide="check"></i>Done'; if (window.lucide) lucide.createIcons(); }
    else { toast(res.error || 'Failed', 'error'); if (res.error && !logEl.textContent.includes(String(res.error).trim())) appendLog('\n' + res.error + '\n'); btn.disabled = false; btn.innerHTML = orig; }
  }

  document.querySelectorAll('[data-app-install]').forEach((b) =>
    b.addEventListener('click', () => run(b, { action: 'install', key: b.dataset.appInstall }, 'Installing')));
  document.querySelectorAll('[data-app-uninstall]').forEach((b) =>
    b.addEventListener('click', () => { if (confirm('Remove this package?')) run(b, { action: 'uninstall', key: b.dataset.appUninstall }, 'Removing'); }));
  document.getElementById('phpInstall')?.addEventListener('click', function () {
    run(this, { action: 'php-install', version: document.getElementById('phpVer').value }, 'Installing');
  });
  document.getElementById('appReload')?.addEventListener('click', () => location.reload());
});
</script>
