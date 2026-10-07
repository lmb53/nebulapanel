<?php
require_once APP_ROOT . '/lib/mod_backups.php';
require_once APP_ROOT . '/lib/mod_sites.php';
$backups = backup_list();
$sites = sites_list();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Backups</h1>
    <p class="page-subtitle">Create, verify and download website archives (.tar.gz)</p>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>Create backup</h3></div>
  <div class="card-pad">
    <?php $backupSites = array_filter($sites, fn($site) => preg_match('/^[a-f0-9]{32}$/', (string) ($site['id'] ?? ''))); ?>
    <?php if (!$backupSites): ?>
    <?= requirement_missing('globe', 'No websites to back up yet', 'Backups archive a managed website’s files. Create a website first.', [['label' => 'Add website', 'href' => url('websites') . '#add', 'icon' => 'plus', 'primary' => true]], false) ?>
    <?php else: ?>
    <form class="form-grid" id="bkForm" style="--cols:minmax(0,1fr) minmax(0,240px) auto" novalidate>
      <div>
        <label class="field-label" for="bkSite">Website<span class="req" aria-hidden="true">*</span></label>
        <select class="select" id="bkSite" required data-required-message="Choose the website to back up.">
          <option value="">Choose a website…</option>
          <?php foreach ($sites as $site): if (!preg_match('/^[a-f0-9]{32}$/', (string)($site['id']??''))) continue; ?>
            <option value="<?= e($site['id']) ?>"><?= e($site['domain'] ?? $site['id']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="field-label" for="bkLabel">Label <span class="text-tertiary" style="font-weight:400">(optional)</span></label>
        <input class="input" id="bkLabel" maxlength="40" placeholder="before-upgrade">
      </div>
      <button class="btn btn-primary" type="submit" id="bkCreate"><i data-lucide="archive"></i>Create backup</button>
    </form>
    <div class="field-help" style="margin-top:10px">Archives include a checksum manifest and are stored privately in the panel’s data folder.</div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Archives</h3></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>File</th><th class="num">Size</th><th>Created</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody id="bkBody">
        <?php foreach ($backups as $b): ?>
          <tr data-file="<?= e($b['file']) ?>">
            <td class="mono" style="font-size:13px"><?= e($b['file']) ?></td>
            <td class="mono text-tertiary num"><?= e(human_bytes($b['size'])) ?></td>
            <td class="text-tertiary"><?= e(fmt_datetime($b['mtime'])) ?></td>
            <td class="actions-col">
              <a class="btn btn-secondary btn-sm btn-icon" href="<?= e(url('backup-download', ['file' => $b['file']])) ?>" title="Download" aria-label="Download <?= e($b['file']) ?>"><i data-lucide="download"></i></a>
              <button class="btn btn-secondary btn-sm btn-icon" type="button" data-bk-verify="<?= e($b['file']) ?>" title="Verify integrity" aria-label="Verify <?= e($b['file']) ?>"><i data-lucide="shield-check"></i></button>
              <button class="btn btn-danger btn-sm btn-icon" type="button" data-bk-del="<?= e($b['file']) ?>" title="Delete" aria-label="Delete <?= e($b['file']) ?>"><i data-lucide="trash-2"></i></button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$backups): ?>
          <tr class="empty-row"><td colspan="4">No backups yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  document.getElementById('bkForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!window.Nebula.validateForm(event.currentTarget)) return;
    const button = document.getElementById('bkCreate');
    window.Nebula.setBusy(button, true);
    const res = await apiPost('backups', { action: 'create', site_id: document.getElementById('bkSite').value, label: document.getElementById('bkLabel').value.trim() });
    window.Nebula.setBusy(button, false);
    if (res.ok) { toast('Backup created', 'success'); setTimeout(() => location.reload(), 500); }
    else toast(res.error || 'Backup failed', 'error');
  });
  document.querySelectorAll('[data-bk-del]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!await window.Nebula.confirm({ title: 'Delete this backup?', message: 'The archive is deleted permanently.', danger: true })) return;
      const res = await apiPost('backups', { action: 'delete', file: btn.dataset.bkDel });
      if (res.ok) { toast('Deleted', 'success'); btn.closest('tr').remove(); }
      else toast(res.error || 'Failed', 'error');
    });
  });
  document.querySelectorAll('[data-bk-verify]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      const res = await apiPost('backups', { action: 'verify', file: btn.dataset.bkVerify });
      btn.disabled = false;
      if (res.ok) toast(`Archive verified: ${res.entries} entries`, 'success');
      else toast(res.error || 'Integrity check failed', 'error');
    });
  });
});
</script>
