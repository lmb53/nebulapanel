<?php
/** Standalone first-run provisioning wizard. Rendered without the app shell. */
require_once APP_ROOT . '/lib/mod_apps.php';
require_once APP_ROOT . '/lib/mod_pma.php';

$catalog = app_catalog();
$phpAvailable = php_installable_versions();
$phpInstalled = php_installed_versions();
$phpLatest = $phpAvailable ? end($phpAvailable) : null;
$recommended = ['mariadb', 'certbot', 'fail2ban'];

// Build the component list the wizard renders.
$items = [];
// Web server (nginx is already installed by install.sh) + optional Apache.
$items[] = ['key' => 'nginx-base', 'label' => 'Nginx', 'desc' => 'Web server (installed with the panel)', 'icon' => 'server-cog', 'installed' => true, 'rec' => true, 'lock' => true];
if ($phpLatest) {
    $items[] = ['key' => 'php:' . $phpLatest, 'label' => 'PHP ' . $phpLatest, 'desc' => 'PHP-FPM ' . $phpLatest . ' + common extensions', 'icon' => 'code-2', 'installed' => false, 'rec' => true];
} elseif ($phpInstalled) {
    $items[] = ['key' => 'php-have', 'label' => 'PHP ' . implode(', ', $phpInstalled), 'desc' => 'PHP-FPM already installed', 'icon' => 'code-2', 'installed' => true, 'rec' => true, 'lock' => true];
}
foreach (['mariadb', 'apache2', 'redis', 'memcached', 'docker', 'fail2ban', 'certbot', 'git'] as $k) {
    if (!isset($catalog[$k])) {
        continue;
    }
    $c = $catalog[$k];
    $items[] = ['key' => $k, 'label' => $c['label'], 'desc' => $c['desc'], 'icon' => $c['icon'], 'installed' => app_installed($k), 'rec' => in_array($k, $recommended, true)];
}
$items[] = ['key' => 'phpmyadmin', 'label' => 'phpMyAdmin', 'desc' => 'Web-based MySQL/MariaDB admin', 'icon' => 'table-properties', 'installed' => pma_installed(), 'rec' => true];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Set up your server · <?= e($config['panel_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest"></script>
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:40px 20px">
  <div style="width:760px;max-width:100%">

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px">
      <div class="logo-mark" style="width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,var(--blue-500),var(--purple-500));display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow-glow-blue)">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" style="width:20px;height:20px"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
      </div>
      <div>
        <h1 style="margin:0;font-size:22px;font-weight:700">Set up your server</h1>
        <p style="margin:2px 0 0;font-size:13px;color:var(--text-tertiary)">Pick the services to install now. You can add more later from <strong>Install Apps</strong>.</p>
      </div>
    </div>

    <div class="card" style="margin:22px 0">
      <div class="card-header"><h3>Recommended stack</h3><span class="muted">Selected items install in order</span></div>
      <div class="card-pad" style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($items as $it): $installed = !empty($it['installed']); $lock = !empty($it['lock']) || $installed; ?>
          <label class="service-row" style="cursor:<?= $lock ? 'default' : 'pointer' ?>">
            <div class="svc-icon"><i data-lucide="<?= e($it['icon']) ?>" style="color:var(--blue-400)"></i></div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:600;font-size:13px"><?= e($it['label']) ?></div>
              <div style="font-size:11.5px;color:var(--text-tertiary)"><?= e($it['desc']) ?></div>
            </div>
            <span class="wiz-status" data-status style="font-size:12px;color:var(--text-tertiary);margin-right:6px"></span>
            <?php if ($installed): ?>
              <span class="badge badge-emerald"><span class="bdot"></span>Installed</span>
            <?php else: ?>
              <input type="checkbox" class="row-check" data-key="<?= e($it['key']) ?>" <?= !empty($it['rec']) ? 'checked' : '' ?>>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="flex items-center" style="justify-content:space-between;gap:12px">
      <a href="<?= e(url('dashboard')) ?>" class="btn btn-ghost" id="wizSkip">Skip for now</a>
      <div class="flex gap-2">
        <button class="btn btn-secondary" id="wizFinish" style="display:none"><i data-lucide="check"></i>Finish &amp; go to dashboard</button>
        <button class="btn btn-primary" id="wizInstall"><i data-lucide="download-cloud"></i>Install selected</button>
      </div>
    </div>

    <div class="card hidden" id="wizLogCard" style="margin-top:16px">
      <div class="card-header"><h3>Progress</h3></div>
      <pre class="mono" id="wizLog" style="margin:0;padding:16px;font-size:12px;line-height:1.6;white-space:pre-wrap;max-height:36vh;overflow:auto"></pre>
    </div>

    <p style="text-align:center;font-size:11.5px;color:var(--text-tertiary);margin-top:18px">
      Installs run as root via the panel's privileged helper &amp; apt. This can take a few minutes.
    </p>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="<?= e(asset('app.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, streamPost, toast } = window.Nebula;
  const installBtn = document.getElementById('wizInstall');
  const finishBtn = document.getElementById('wizFinish');
  const logCard = document.getElementById('wizLogCard');
  const logEl = document.getElementById('wizLog');
  const DASH = <?= json_encode(url('dashboard')) ?>;

  function log(line) { logCard.classList.remove('hidden'); logEl.textContent += line + '\n'; logEl.scrollTop = logEl.scrollHeight; }
  function statusFor(cb, text, color) {
    const row = cb.closest('.service-row');
    const s = row && row.querySelector('[data-status]');
    if (s) { s.textContent = text; s.style.color = color || 'var(--text-tertiary)'; }
  }

  installBtn.addEventListener('click', async () => {
    const checks = Array.from(document.querySelectorAll('.row-check[data-key]:checked'));
    if (!checks.length) { toast('Nothing selected', 'info'); return; }
    installBtn.disabled = true;
    installBtn.innerHTML = '<i data-lucide="loader-circle"></i>Installing…';
    if (window.lucide) lucide.createIcons();
    let failures = 0;
    for (const cb of checks) {
      const key = cb.dataset.key;
      const label = cb.closest('.service-row').querySelector('div > div').textContent;
      statusFor(cb, 'installing…', 'var(--blue-400)');
      log('Installing ' + label + ' …');
      let res;
      try { res = await streamPost('provision', { action: 'install', key }, (event) => {
        if (event.type === 'output' && event.text) {
          logEl.textContent += event.text;
          logEl.scrollTop = logEl.scrollHeight;
        }
      }); }
      catch (e) { res = { ok: false, error: 'request failed' }; }
      if (res.ok) {
        statusFor(cb, 'done', 'var(--emerald-400)');
        cb.disabled = true; cb.checked = true;
        log('  ✓ ' + label + ' installed');
      } else {
        failures++;
        statusFor(cb, 'failed', 'var(--red-400)');
        log('  ✗ ' + label + ': ' + (res.error || 'failed'));
      }
    }
    installBtn.style.display = 'none';
    finishBtn.style.display = '';
    if (window.lucide) lucide.createIcons();
    toast(failures ? (failures + ' item(s) failed — see progress') : 'Stack installed', failures ? 'warning' : 'success');
  });

  finishBtn.addEventListener('click', async () => {
    try { await apiPost('provision', { action: 'finish' }); } catch (e) {}
    window.location.href = DASH;
  });
  document.getElementById('wizSkip').addEventListener('click', async (e) => {
    e.preventDefault();
    try { await apiPost('provision', { action: 'finish' }); } catch (e) {}
    window.location.href = DASH;
  });
});
</script>
</body>
</html>
