<?php
require_once APP_ROOT . '/lib/mod_pma.php';
$installed = pma_installed();
$url = pma_url();
$helper = helper_available();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">phpMyAdmin</h1>
    <p class="page-subtitle">Web-based MySQL / MariaDB administration</p>
  </div>
</div>

<?php if ($installed): ?>
  <div class="card">
    <div class="card-header"><h3>phpMyAdmin is installed</h3></div>
    <div class="card-pad">
      <a class="btn btn-primary" href="<?= e(url('databases')) ?>">
        <i data-lucide="database"></i>Choose a database
      </a>
      <div class="field-help" style="margin-top:12px">Installed at <span class="mono"><?= e($url) ?></span></div>
      <div class="field-help" style="font-size:13px;margin-top:8px">
        Use the phpMyAdmin button beside a database. Nebula creates a short-lived,
        signed handoff and opens that database without putting its password in the URL.
      </div>
      <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border-subtle)">
        <button class="btn btn-danger" type="button" id="pmaRemove"><i data-lucide="trash-2"></i>Remove phpMyAdmin</button>
      </div>
    </div>
  </div>
<?php else: ?>
  <?php if (!$helper): ?>
  <?= helper_missing_state('installing phpMyAdmin') ?>
  <?php else: ?>
  <div class="card">
    <div class="card-header"><h3>Install phpMyAdmin</h3><span class="badge badge-slate">Not installed</span></div>
    <div class="card-pad">
      <p style="color:var(--text-secondary);margin:0 0 16px;max-width:70ch">
        phpMyAdmin provides a full web interface for managing your MySQL / MariaDB
        databases, tables, and users. Installation uses the signed distribution package
        (about 15 MB) and may take a moment.
      </p>
      <button class="btn btn-primary" type="button" id="pmaInstall">
        <i data-lucide="download"></i>Install phpMyAdmin
      </button>
      <div class="card hidden" id="pmaLogCard" style="margin-top:16px">
        <div class="card-header"><h3>Install output</h3></div>
        <pre class="mono log-pre" id="pmaLog"></pre>
      </div>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, streamPost, toast } = window.Nebula;

  const installBtn = document.getElementById('pmaInstall');
  installBtn?.addEventListener('click', async () => {
    installBtn.disabled = true;
    const original = installBtn.innerHTML;
    installBtn.textContent = 'Installing…';
    const logCard = document.getElementById('pmaLogCard');
    const logEl = document.getElementById('pmaLog');
    logCard?.classList.remove('hidden');
    if (logEl) logEl.textContent = '';
    const res = await streamPost('pma', { action: 'install' }, (event) => {
      if (event.type === 'output' && event.text && logEl) {
        logEl.textContent += event.text;
        logEl.scrollTop = logEl.scrollHeight;
      }
    });
    if (res.ok) {
      toast('phpMyAdmin installed', 'success');
      setTimeout(() => location.reload(), 500);
    } else {
      toast(res.error || 'Failed', 'error');
      if (logEl && res.error && !logEl.textContent.includes(String(res.error).trim())) {
        logEl.textContent += (logEl.textContent ? '\n' : '') + res.error + '\n';
      }
      installBtn.disabled = false;
      installBtn.innerHTML = original;
      if (window.lucide) lucide.createIcons();
    }
  });

  document.getElementById('pmaRemove')?.addEventListener('click', async () => {
    if (!await window.Nebula.confirm({ title: 'Remove phpMyAdmin?', message: 'The installed files are deleted. Databases are not affected.', danger: true, confirmLabel: 'Remove' })) return;
    const res = await apiPost('pma', { action: 'remove' });
    if (res.ok) { toast('phpMyAdmin removed', 'success'); setTimeout(() => location.reload(), 500); }
    else toast(res.error || 'Failed', 'error');
  });

});
</script>
