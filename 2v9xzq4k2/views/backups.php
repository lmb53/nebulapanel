<?php
require_once APP_ROOT . '/lib/mod_backups.php';
$backups = backup_list();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Backups</h1>
    <p class="page-subtitle">Create and download .tar.gz archives (stored in <span class="mono">data/backups</span>)</p>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>Create backup</h3></div>
  <div class="card-pad">
    <div class="grid" style="grid-template-columns:1fr 220px auto;gap:12px;align-items:end">
      <div>
        <label class="field-label">Source path</label>
        <input class="input mono" id="bkSource" placeholder="/var/www/mysite">
      </div>
      <div>
        <label class="field-label">Label</label>
        <input class="input" id="bkLabel" placeholder="optional label">
      </div>
      <button class="btn btn-primary" id="bkCreate"><i data-lucide="archive"></i>Create backup</button>
    </div>
    <div style="font-size:12px;color:var(--text-tertiary);margin-top:10px">
      Root-owned sources may need a passwordless sudo rule for <span class="mono">tar</span> (see README).
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Archives</h3></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>File</th><th>Size</th><th>Created</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody id="bkBody">
        <?php foreach ($backups as $b): ?>
          <tr data-file="<?= e($b['file']) ?>">
            <td class="mono" style="font-size:12.5px"><?= e($b['file']) ?></td>
            <td class="mono text-tertiary"><?= e(human_bytes($b['size'])) ?></td>
            <td class="text-tertiary"><?= e(date('Y-m-d H:i', $b['mtime'])) ?></td>
            <td style="text-align:right;white-space:nowrap">
              <a class="btn btn-secondary btn-sm" href="<?= e(url('backup-download', ['file' => $b['file']])) ?>" title="Download"><i data-lucide="download"></i></a>
              <button class="btn btn-danger btn-sm" data-bk-del="<?= e($b['file']) ?>" title="Delete"><i data-lucide="trash-2"></i></button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$backups): ?>
          <tr><td colspan="4" class="text-tertiary" style="text-align:center;padding:24px">No backups yet</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  document.getElementById('bkCreate')?.addEventListener('click', async () => {
    const source = document.getElementById('bkSource').value;
    const label = document.getElementById('bkLabel').value;
    const res = await apiPost('backups', { action: 'create', source, label });
    if (res.ok) { toast('Backup created', 'success'); setTimeout(() => location.reload(), 500); }
    else toast(res.error || 'Failed', 'error');
  });
  document.querySelectorAll('[data-bk-del]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Delete this backup?')) return;
      const res = await apiPost('backups', { action: 'delete', file: btn.dataset.bkDel });
      if (res.ok) { toast('Deleted', 'success'); btn.closest('tr').remove(); }
      else toast(res.error || 'Failed', 'error');
    });
  });
});
</script>
