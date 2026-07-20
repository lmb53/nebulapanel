<?php
/** @var array $config */
require_once APP_ROOT . '/lib/files.php';
$rel = $_GET['path'] ?? '';
$abs = fm_resolve($rel);
if ($abs === null || !is_dir($abs)) {
    $abs = fm_resolve('');
}
$root_ok = fm_root() !== '';
$rel = $abs ? fm_rel($abs) : '';
$listing = $abs ? fm_list($abs) : ['dirs' => [], 'files' => []];
$breadcrumbs = fm_breadcrumbs($rel);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">File Manager</h1>
    <p class="page-subtitle">Confined to <span class="mono"><?= e($config['fm_root']) ?></span></p>
  </div>
  <?php if ($root_ok): ?>
  <div class="page-actions">
    <button class="btn btn-secondary" id="fmUploadBtn"><i data-lucide="upload"></i>Upload</button>
    <button class="btn btn-secondary" id="fmNewDir"><i data-lucide="folder-plus"></i>New Folder</button>
    <button class="btn btn-secondary" id="fmNewFile"><i data-lucide="file-plus"></i>New File</button>
    <input type="file" id="fmUpload" style="display:none">
  </div>
  <?php endif; ?>
</div>

<?php if (!$root_ok): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="folder-x"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">Root directory not found</div>
    <div style="font-size:13px;margin-top:4px"><span class="mono"><?= e($config['fm_root']) ?></span> does not exist. Set <span class="mono">NEBULA_FM_ROOT</span> or edit config.php.</div>
  </div></div>
<?php else: ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header" style="gap:8px">
      <div class="breadcrumb" style="margin:0;flex-wrap:wrap">
        <?php foreach ($breadcrumbs as $i => $c): ?>
          <?php if ($i > 0): ?><i data-lucide="chevron-right"></i><?php endif; ?>
          <a href="<?= e(url('files', ['path' => $c['rel']])) ?>" style="color:<?= $i === count($breadcrumbs) - 1 ? 'var(--text-primary)' : 'var(--text-tertiary)' ?>"><?= e($c['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Size</th><th>Type</th><th>Modified</th><th>Permissions</th><th>Owner</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
          <?php if ($rel !== ''): ?>
            <?php $parent = trim(dirname($rel), '.'); $parent = $parent === '/' ? '' : $parent; ?>
            <tr>
              <td colspan="7"><a href="<?= e(url('files', ['path' => $parent])) ?>" class="flex items-center gap-2" style="color:var(--text-secondary)"><i data-lucide="corner-left-up" style="width:15px;height:15px"></i>..</a></td>
            </tr>
          <?php endif; ?>

          <?php if (!$listing['dirs'] && !$listing['files']): ?>
            <tr><td colspan="7" class="text-tertiary" style="padding:24px;text-align:center">Empty directory</td></tr>
          <?php endif; ?>

          <?php foreach ($listing['dirs'] as $d): ?>
            <tr>
              <td><a href="<?= e(url('files', ['path' => $d['rel']])) ?>" class="flex items-center gap-2" style="font-weight:600"><i data-lucide="folder" style="width:16px;height:16px;color:var(--blue-400)"></i><?= e($d['name']) ?></a></td>
              <td class="text-tertiary">—</td>
              <td class="text-tertiary">Folder</td>
              <td class="text-tertiary"><?= e(date('Y-m-d H:i', $d['mtime'])) ?></td>
              <td><button class="btn btn-ghost btn-sm mono" data-fm-chmod="<?= e($d['rel']) ?>" data-perms="<?= e($d['perms']) ?>" title="Change permissions"><?= e($d['perms']) ?></button></td>
              <td class="text-tertiary"><?= e($d['owner']) ?></td>
              <td style="text-align:right;white-space:nowrap">
                <button class="btn btn-secondary btn-sm" data-fm-rename="<?= e($d['rel']) ?>" data-name="<?= e($d['name']) ?>" title="Rename"><i data-lucide="pencil"></i></button>
                <button class="btn btn-danger btn-sm" data-fm-delete="<?= e($d['rel']) ?>" title="Delete"><i data-lucide="trash-2"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php foreach ($listing['files'] as $f): ?>
            <tr>
              <td><a href="<?= e(url('file-view', ['path' => $f['rel']])) ?>" class="flex items-center gap-2"><i data-lucide="file" style="width:16px;height:16px;color:var(--text-tertiary)"></i><?= e($f['name']) ?></a></td>
              <td class="mono text-tertiary"><?= e(human_bytes($f['size'])) ?></td>
              <td class="text-tertiary"><?= e($f['ext'] !== '' ? strtoupper($f['ext']) : 'File') ?></td>
              <td class="text-tertiary"><?= e(date('Y-m-d H:i', $f['mtime'])) ?></td>
              <td><button class="btn btn-ghost btn-sm mono" data-fm-chmod="<?= e($f['rel']) ?>" data-perms="<?= e($f['perms']) ?>" title="Change permissions"><?= e($f['perms']) ?></button></td>
              <td class="text-tertiary"><?= e($f['owner']) ?></td>
              <td style="text-align:right;white-space:nowrap">
                <a class="btn btn-secondary btn-sm" href="<?= e(url('file-edit', ['path' => $f['rel']])) ?>" title="Edit"><i data-lucide="pencil-line"></i></a>
                <a class="btn btn-secondary btn-sm" href="<?= e(url('file-download', ['path' => $f['rel']])) ?>" title="Download"><i data-lucide="download"></i></a>
                <button class="btn btn-secondary btn-sm" data-fm-rename="<?= e($f['rel']) ?>" data-name="<?= e($f['name']) ?>" title="Rename"><i data-lucide="pencil"></i></button>
                <button class="btn btn-danger btn-sm" data-fm-delete="<?= e($f['rel']) ?>" title="Delete"><i data-lucide="trash-2"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<script>
const CURDIR = <?= json_encode($rel) ?>;
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  const reload = () => setTimeout(() => location.reload(), 400);

  document.getElementById('fmNewDir')?.addEventListener('click', async () => {
    const name = prompt('New folder name:');
    if (!name) return;
    const res = await apiPost('file-mkdir', { dir: CURDIR, name });
    if (res.ok) { toast('Folder created', 'success'); reload(); }
    else toast(res.error || 'Failed', 'error');
  });

  document.getElementById('fmNewFile')?.addEventListener('click', async () => {
    const name = prompt('New file name:');
    if (!name) return;
    const res = await apiPost('file-mkfile', { dir: CURDIR, name });
    if (res.ok) { toast('File created', 'success'); reload(); }
    else toast(res.error || 'Failed', 'error');
  });

  document.querySelectorAll('[data-fm-rename]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const path = btn.dataset.fmRename;
      const name = prompt('Rename to:', btn.dataset.name || '');
      if (!name || name === btn.dataset.name) return;
      const res = await apiPost('file-rename', { path, name });
      if (res.ok) { toast('Renamed', 'success'); reload(); }
      else toast(res.error || 'Failed', 'error');
    });
  });

  document.querySelectorAll('[data-fm-chmod]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const path = btn.dataset.fmChmod;
      const mode = prompt('New permissions (octal, e.g. 0644):', btn.dataset.perms || '');
      if (!mode) return;
      const res = await apiPost('file-chmod', { path, mode });
      if (res.ok) { toast('Permissions changed', 'success'); reload(); }
      else toast(res.error || 'Failed', 'error');
    });
  });

  document.querySelectorAll('[data-fm-delete]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const path = btn.dataset.fmDelete;
      if (!confirm(`Delete "${path}"? This cannot be undone.`)) return;
      const res = await apiPost('file-delete', { path });
      if (res.ok) { toast('Deleted', 'success'); btn.closest('tr')?.remove(); }
      else toast(res.error || 'Delete failed', 'error');
    });
  });

  const fileInput = document.getElementById('fmUpload');
  document.getElementById('fmUploadBtn')?.addEventListener('click', () => fileInput?.click());
  fileInput?.addEventListener('change', async () => {
    const file = fileInput.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('dir', CURDIR);
    fd.append('file', file);
    try {
      const r = await fetch(window.Nebula.api('file-upload'), {
        method: 'POST',
        headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
        body: fd,
      });
      const res = await r.json();
      if (res.ok) { toast('Uploaded', 'success'); reload(); }
      else toast(res.error || 'Upload failed', 'error');
    } catch (e) { toast('Upload failed', 'error'); }
    fileInput.value = '';
  });
});
</script>
