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
<?php
// --- Presentation helpers (local closures; do not affect the data block above) ---
$fmIcon = static function (string $ext): array {
    switch (strtolower($ext)) {
        case 'php': case 'phtml':
        case 'js': case 'mjs': case 'ts': case 'jsx': case 'tsx':
        case 'json': case 'yml': case 'yaml':
        case 'html': case 'htm': case 'css': case 'scss': case 'xml':
            return ['file-code-2', 'var(--blue-400)'];
        case 'jpg': case 'jpeg': case 'png': case 'gif': case 'webp': case 'svg': case 'bmp': case 'ico':
            return ['image', 'var(--orange-400)'];
        case 'mp4': case 'mov': case 'avi': case 'mkv': case 'webm':
            return ['file-video', 'var(--purple-400)'];
        case 'mp3': case 'wav': case 'flac': case 'ogg':
            return ['file-audio', 'var(--purple-400)'];
        case 'zip': case 'tar': case 'gz': case 'tgz': case 'rar': case '7z': case 'bz2':
            return ['file-archive', 'var(--emerald-400)'];
        case 'sql': case 'db': case 'sqlite':
            return ['database', 'var(--blue-400)'];
        case 'env':
            return ['key-round', 'var(--red-400)'];
        case 'pdf':
            return ['file-text', 'var(--red-400)'];
        case 'md': case 'markdown': case 'txt': case 'log':
            return ['file-text', 'var(--text-tertiary)'];
        case 'lock': case 'htpasswd':
            return ['lock', 'var(--text-tertiary)'];
        default:
            return ['file', 'var(--text-tertiary)'];
    }
};
$fmType = static function (string $ext): string {
    $e = strtolower($ext);
    $map = [
        'php' => 'PHP File', 'phtml' => 'PHP File',
        'js' => 'JavaScript', 'mjs' => 'JavaScript', 'ts' => 'TypeScript',
        'jsx' => 'React Source', 'tsx' => 'React Source',
        'json' => 'JSON File', 'yml' => 'YAML File', 'yaml' => 'YAML File',
        'html' => 'HTML File', 'htm' => 'HTML File', 'css' => 'CSS File', 'scss' => 'SCSS File', 'xml' => 'XML File',
        'jpg' => 'JPEG Image', 'jpeg' => 'JPEG Image', 'png' => 'PNG Image', 'gif' => 'GIF Image',
        'webp' => 'WebP Image', 'svg' => 'SVG Image', 'bmp' => 'Bitmap Image', 'ico' => 'Icon',
        'mp4' => 'MP4 Video', 'mov' => 'QuickTime Video', 'avi' => 'AVI Video', 'mkv' => 'Matroska Video', 'webm' => 'WebM Video',
        'mp3' => 'MP3 Audio', 'wav' => 'WAV Audio', 'flac' => 'FLAC Audio', 'ogg' => 'OGG Audio',
        'zip' => 'Zip Archive', 'tar' => 'Tar Archive', 'gz' => 'Gzip Archive', 'tgz' => 'Gzip Archive',
        'rar' => 'RAR Archive', '7z' => '7z Archive', 'bz2' => 'Bzip2 Archive',
        'sql' => 'SQL Backup', 'db' => 'Database', 'sqlite' => 'SQLite DB',
        'env' => 'Env File', 'pdf' => 'PDF Document',
        'md' => 'Markdown', 'markdown' => 'Markdown', 'txt' => 'Text File', 'log' => 'Log File',
    ];
    if (isset($map[$e])) {
        return $map[$e];
    }
    return $e !== '' ? strtoupper($e) . ' File' : 'File';
};
// Inline style for the rounded icon badge in the Name column.
$iconWrap = 'width:28px;height:28px;border-radius:7px;background:var(--bg-surface-2);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0';
?>
<div class="page-header">
  <div>
    <h1 class="page-title">File Manager</h1>
    <p class="page-subtitle">Confined to <span class="mono"><?= e($config['fm_root']) ?></span></p>
  </div>
  <?php if ($root_ok): ?>
  <div class="page-actions">
    <button class="btn btn-primary" id="fmUploadBtn"><i data-lucide="upload"></i>Upload</button>
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
  <?php $itemCount = count($listing['dirs']) + count($listing['files']); ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header" style="gap:8px">
      <div class="breadcrumb" style="margin:0;flex-wrap:wrap">
        <i data-lucide="home" style="color:var(--text-tertiary)"></i>
        <?php foreach ($breadcrumbs as $i => $c): ?>
          <?php if ($i > 0): ?><i data-lucide="chevron-right"></i><?php endif; ?>
          <a href="<?= e(url('files', ['path' => $c['rel']])) ?>" style="color:<?= $i === count($breadcrumbs) - 1 ? 'var(--text-primary)' : 'var(--text-tertiary)' ?>;<?= $i === count($breadcrumbs) - 1 ? 'font-weight:600' : '' ?>"><?= e($c['name']) ?></a>
        <?php endforeach; ?>
      </div>
      <span class="muted"><?= (int) $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Size</th><th>Type</th><th>Modified</th><th>Permissions</th><th>Owner</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
          <?php if ($rel !== ''): ?>
            <?php $parent = trim(dirname($rel), '.'); $parent = $parent === '/' ? '' : $parent; ?>
            <tr>
              <td colspan="7"><a href="<?= e(url('files', ['path' => $parent])) ?>" class="flex items-center gap-2" style="color:var(--text-secondary);font-weight:600"><span style="<?= $iconWrap ?>"><i data-lucide="corner-left-up" style="width:15px;height:15px;color:var(--text-tertiary)"></i></span>..</a></td>
            </tr>
          <?php endif; ?>

          <?php if (!$listing['dirs'] && !$listing['files']): ?>
            <tr><td colspan="7" class="text-tertiary" style="padding:24px;text-align:center">Empty directory</td></tr>
          <?php endif; ?>

          <?php foreach ($listing['dirs'] as $d): ?>
            <tr data-ctx="true">
              <td>
                <a href="<?= e(url('files', ['path' => $d['rel']])) ?>" class="flex items-center gap-2" style="font-weight:600">
                  <span style="<?= $iconWrap ?>"><i data-lucide="folder" style="width:15px;height:15px;color:var(--purple-400)"></i></span>
                  <?= e($d['name']) ?>
                </a>
              </td>
              <td class="mono text-tertiary">—</td>
              <td class="text-tertiary">Folder</td>
              <td class="mono text-tertiary"><?= e(date('M j, H:i', $d['mtime'])) ?></td>
              <td><button class="btn btn-ghost btn-sm mono" data-fm-chmod="<?= e($d['rel']) ?>" data-perms="<?= e($d['perms']) ?>" title="Change permissions"><?= e($d['perms']) ?></button></td>
              <td class="text-tertiary"><?= e($d['owner']) ?></td>
              <td style="text-align:right;white-space:nowrap">
                <button class="icon-btn" data-fm-rename="<?= e($d['rel']) ?>" data-name="<?= e($d['name']) ?>" title="Rename"><i data-lucide="pencil-line"></i></button>
                <button class="icon-btn" data-fm-delete="<?= e($d['rel']) ?>" title="Delete" style="color:var(--red-400)"><i data-lucide="trash-2"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php foreach ($listing['files'] as $f): ?>
            <?php [$ic, $icColor] = $fmIcon($f['ext']); ?>
            <tr data-ctx="true">
              <td>
                <a href="<?= e(url('file-view', ['path' => $f['rel']])) ?>" class="flex items-center gap-2" style="font-weight:600">
                  <span style="<?= $iconWrap ?>"><i data-lucide="<?= e($ic) ?>" style="width:15px;height:15px;color:<?= e($icColor) ?>"></i></span>
                  <?= e($f['name']) ?>
                </a>
              </td>
              <td class="mono text-tertiary"><?= e(human_bytes($f['size'])) ?></td>
              <td class="text-tertiary"><?= e($fmType($f['ext'])) ?></td>
              <td class="mono text-tertiary"><?= e(date('M j, H:i', $f['mtime'])) ?></td>
              <td><button class="btn btn-ghost btn-sm mono" data-fm-chmod="<?= e($f['rel']) ?>" data-perms="<?= e($f['perms']) ?>" title="Change permissions"><?= e($f['perms']) ?></button></td>
              <td class="text-tertiary"><?= e($f['owner']) ?></td>
              <td style="text-align:right;white-space:nowrap">
                <a class="icon-btn" href="<?= e(url('file-edit', ['path' => $f['rel']])) ?>" title="Edit"><i data-lucide="pencil-line"></i></a>
                <a class="icon-btn" href="<?= e(url('file-download', ['path' => $f['rel']])) ?>" title="Download"><i data-lucide="download"></i></a>
                <button class="icon-btn" data-fm-rename="<?= e($f['rel']) ?>" data-name="<?= e($f['name']) ?>" title="Rename"><i data-lucide="pencil"></i></button>
                <button class="icon-btn" data-fm-delete="<?= e($f['rel']) ?>" title="Delete" style="color:var(--red-400)"><i data-lucide="trash-2"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Right-click context menu (wired below; reuses the row's own action buttons) -->
  <div class="ctx-menu hidden" id="ctxMenu">
    <div class="ctx-item" data-ctx-act="open"><i data-lucide="external-link"></i>Open</div>
    <div class="ctx-item" data-ctx-act="edit"><i data-lucide="pencil-line"></i>Edit</div>
    <div class="ctx-item" data-ctx-act="download"><i data-lucide="download"></i>Download</div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" data-ctx-act="rename"><i data-lucide="pencil"></i>Rename</div>
    <div class="ctx-item" data-ctx-act="chmod"><i data-lucide="lock"></i>Permissions</div>
    <div class="ctx-sep"></div>
    <div class="ctx-item danger" data-ctx-act="delete"><i data-lucide="trash-2"></i>Delete</div>
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

  // ---- Right-click context menu -------------------------------------------
  // Reuses each row's existing links/buttons so the exact same API calls fire.
  const ctx = document.getElementById('ctxMenu');
  if (ctx) {
    let ctxRow = null;
    const hideCtx = () => ctx.classList.add('hidden');

    document.querySelectorAll('tr[data-ctx]').forEach((tr) => {
      tr.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        ctxRow = tr;
        // Toggle which items make sense for this row (folders have no edit/download).
        const has = (sel) => !!tr.querySelector(sel);
        ctx.querySelector('[data-ctx-act="edit"]').style.display = has('a[href*="file-edit"]') ? '' : 'none';
        ctx.querySelector('[data-ctx-act="download"]').style.display = has('a[href*="file-download"]') ? '' : 'none';
        ctx.classList.remove('hidden');
        const mw = ctx.offsetWidth || 200, mh = ctx.offsetHeight || 260;
        let x = e.clientX, y = e.clientY;
        if (x + mw > window.innerWidth) x = window.innerWidth - mw - 8;
        if (y + mh > window.innerHeight) y = window.innerHeight - mh - 8;
        ctx.style.left = x + 'px';
        ctx.style.top = y + 'px';
      });
    });

    ctx.querySelectorAll('[data-ctx-act]').forEach((item) => {
      item.addEventListener('click', () => {
        if (!ctxRow) return hideCtx();
        const act = item.dataset.ctxAct;
        const map = {
          open: 'a[href*="file-view"], a[href*="files"]',
          edit: 'a[href*="file-edit"]',
          download: 'a[href*="file-download"]',
          rename: '[data-fm-rename]',
          chmod: '[data-fm-chmod]',
          delete: '[data-fm-delete]',
        };
        const target = ctxRow.querySelector(map[act]);
        hideCtx();
        if (target) target.click();
      });
    });

    document.addEventListener('click', hideCtx);
    document.addEventListener('scroll', hideCtx, true);
    window.addEventListener('resize', hideCtx);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideCtx(); });
  }
});
</script>
