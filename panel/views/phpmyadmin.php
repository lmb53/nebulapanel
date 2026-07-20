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
      <button class="btn btn-primary" id="pmaLaunch" data-url="<?= e($url) ?>">
        <i data-lucide="external-link"></i>Launch phpMyAdmin
      </button>
      <div class="mono" style="margin-top:12px;color:var(--blue-400)"><?= e($url) ?></div>
      <div class="muted" style="font-size:13px;margin-top:12px">
        Log in with a MySQL/MariaDB username &amp; password (create one on the Databases page).
      </div>
      <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
        <button class="btn btn-danger" id="pmaRemove"><i data-lucide="trash-2"></i>Remove phpMyAdmin</button>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-header"><h3>phpMyAdmin is not installed</h3></div>
    <div class="card-pad">
      <p style="color:var(--text-secondary);margin:0 0 16px">
        phpMyAdmin provides a full web interface for managing your MySQL / MariaDB
        databases, tables, and users. Installing downloads the latest release
        (~15MB) and may take a moment.
      </p>
      <button class="btn btn-primary" id="pmaInstall"<?= $helper ? '' : ' disabled' ?>>
        <i data-lucide="download"></i>Install phpMyAdmin
      </button>
      <?php if (!$helper): ?>
        <div class="empty-state" style="margin-top:16px">
          <div class="es-icon"><i data-lucide="shield-alert"></i></div>
          <div style="font-weight:600;color:var(--text-secondary)">Privileged helper required</div>
          <div style="font-size:13px;margin-top:4px">
            The <span class="mono">nebula-helper</span> is not installed. Re-run
            <span class="mono">install.sh</span> to enable phpMyAdmin installation.
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;

  const installBtn = document.getElementById('pmaInstall');
  installBtn?.addEventListener('click', async () => {
    installBtn.disabled = true;
    const original = installBtn.innerHTML;
    installBtn.textContent = 'Installing…';
    const res = await apiPost('pma', { action: 'install' });
    if (res.ok) {
      toast('phpMyAdmin installed', 'success');
      setTimeout(() => location.reload(), 500);
    } else {
      toast(res.error || 'Failed', 'error');
      installBtn.disabled = false;
      installBtn.innerHTML = original;
      if (window.lucide) lucide.createIcons();
    }
  });

  document.getElementById('pmaRemove')?.addEventListener('click', async () => {
    if (!confirm('Remove phpMyAdmin? The installed files will be deleted.')) return;
    const res = await apiPost('pma', { action: 'remove' });
    if (res.ok) { toast('phpMyAdmin removed', 'success'); setTimeout(() => location.reload(), 500); }
    else toast(res.error || 'Failed', 'error');
  });

  const launchBtn = document.getElementById('pmaLaunch');
  launchBtn?.addEventListener('click', () => {
    window.open(launchBtn.dataset.url, '_blank');
  });
});
</script>
