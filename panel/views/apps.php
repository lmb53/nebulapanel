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
    <p class="page-subtitle">Install server software and PHP versions. Manage installed services from <a class="text-blue" href="<?= e(url('services')) ?>">Services</a>.</p>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>Applications</h3><span class="muted">apt packages</span></div>
  <div class="card-pad">
    <div class="grid grid-3" style="gap:14px">
      <?php foreach ($catalog as $key => $c): $installed = app_installed($key); ?>
        <div class="service-row" style="align-items:flex-start">
          <?php // Official brand mark where we ship one; the lucide glyph is the fallback. ?>
          <div class="svc-icon svc-icon-logo">
            <?php if (!empty($c['logo'])): ?>
              <img src="<?= e(asset($c['logo'])) ?>" alt="" loading="lazy" data-logo-fallback="<?= e($c['icon']) ?>">
            <?php else: ?>
              <i data-lucide="<?= e($c['icon']) ?>" aria-hidden="true"></i>
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:0">
            <div class="svc-name"><?= e($c['label']) ?></div>
            <div class="text-tertiary" style="font-size:12px;margin-bottom:10px"><?= e($c['desc']) ?></div>
            <div class="flex items-center gap-2">
            <?php if ($installed): ?>
              <span class="badge badge-emerald"><span class="bdot"></span>Installed</span>
              <button class="btn btn-secondary btn-sm text-red" type="button" data-app-uninstall="<?= e($key) ?>" data-app-name="<?= e($c['label']) ?>">Remove</button>
            <?php else: ?>
              <button class="btn btn-primary btn-sm" type="button" data-app-install="<?= e($key) ?>"<?= $helper ? '' : ' disabled title="The privileged helper is not installed"' ?>><i data-lucide="download"></i>Install</button>
            <?php endif; ?>
            </div>
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
          <span class="badge badge-blue">PHP <?= e($v) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div>
      <label class="field-label" for="phpVer">Install another version <span class="text-tertiary" style="font-weight:400">(from the ondrej/php PPA)</span></label>
      <?php if (!$phpAvailable): ?>
        <div class="text-tertiary" style="font-size:13px">Every supported PHP version is already installed.</div>
      <?php else: ?>
      <div class="flex gap-2" style="flex-wrap:wrap;align-items:center">
        <select class="select" id="phpVer" style="width:auto;min-width:160px">
          <?php foreach (array_reverse($phpAvailable) as $v): ?><option value="<?= e($v) ?>"<?= $v === php_latest_version() ? ' selected' : '' ?>>PHP <?= e($v) ?><?= $v === php_latest_version() ? ' (latest)' : '' ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="button" id="phpInstall" <?= !$helper ? 'disabled' : '' ?>><i data-lucide="download"></i>Install PHP</button>
      </div>
      <?php endif; ?>
      <?php if (!$helper): ?>
        <div class="notice notice-warning" style="margin-top:12px"><i data-lucide="triangle-alert"></i><div><strong>Privileged helper not installed</strong><div>Re-run <span class="mono">install.sh</span> to enable package and PHP installs.</div></div></div>
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
  // A missing brand file must not leave an empty tile — fall back to the glyph.
  document.querySelectorAll('img[data-logo-fallback]').forEach((img) => {
    img.addEventListener('error', () => {
      const host = img.parentElement;
      host.classList.remove('svc-icon-logo');
      host.innerHTML = `<i data-lucide="${img.dataset.logoFallback}" style="color:var(--blue-400)"></i>`;
      if (window.lucide) lucide.createIcons();
    });
  });
  const logCard = document.getElementById('appLogCard');
  const logEl = document.getElementById('appLog');
  function resetLog() { logCard.classList.remove('hidden'); logEl.textContent = ''; }
  // This page is server-rendered: installed badges, the PHP version list and the
  // sidebar's service entries are all decided at render time. After a package
  // changes, only a reload reflects it — carry the output across so the log
  // still "remains here" as promised.
  const LOG_KEY = 'nebula.appLog';
  const carried = sessionStorage.getItem(LOG_KEY);
  if (carried) { sessionStorage.removeItem(LOG_KEY); appendLog(carried); }
  function reloadWithLog() {
    try { sessionStorage.setItem(LOG_KEY, logEl.textContent); } catch (e) { /* private mode */ }
    setTimeout(() => location.reload(), 900);
  }
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
    if (res.ok) { toast('Done — refreshing status', 'success'); btn.innerHTML = '<i data-lucide="check"></i>Done'; if (window.lucide) lucide.createIcons(); reloadWithLog(); }
    else { toast(res.error || 'Failed', 'error'); if (res.error && !logEl.textContent.includes(String(res.error).trim())) appendLog('\n' + res.error + '\n'); btn.disabled = false; btn.innerHTML = orig; }
  }

  document.querySelectorAll('[data-app-install]').forEach((b) =>
    b.addEventListener('click', () => run(b, { action: 'install', key: b.dataset.appInstall }, 'Installing')));
  document.querySelectorAll('[data-app-uninstall]').forEach((b) =>
    b.addEventListener('click', async () => { if (await window.Nebula.confirm({ title: 'Remove ' + (b.dataset.appName || 'this package') + '?', message: 'The package is uninstalled with apt. Its configuration files may remain.', danger: true, confirmLabel: 'Remove' })) run(b, { action: 'uninstall', key: b.dataset.appUninstall }, 'Removing'); }));
  document.getElementById('phpInstall')?.addEventListener('click', function () {
    run(this, { action: 'php-install', version: document.getElementById('phpVer').value }, 'Installing');
  });
  document.getElementById('appReload')?.addEventListener('click', () => location.reload());
});
</script>
