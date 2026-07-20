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
        <thead><tr><th>Name</th><th>Size</th><th>Permissions</th><th>Modified</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
          <?php if ($rel !== ''): ?>
            <?php $parent = trim(dirname($rel), '.'); $parent = $parent === '/' ? '' : $parent; ?>
            <tr>
              <td colspan="5"><a href="<?= e(url('files', ['path' => $parent])) ?>" class="flex items-center gap-2" style="color:var(--text-secondary)"><i data-lucide="corner-left-up" style="width:15px;height:15px"></i>..</a></td>
            </tr>
          <?php endif; ?>

          <?php if (!$listing['dirs'] && !$listing['files']): ?>
            <tr><td colspan="5" class="text-tertiary" style="padding:24px;text-align:center">Empty directory</td></tr>
          <?php endif; ?>

          <?php foreach ($listing['dirs'] as $d): ?>
            <tr>
              <td><a href="<?= e(url('files', ['path' => $d['rel']])) ?>" class="flex items-center gap-2" style="font-weight:600"><i data-lucide="folder" style="width:16px;height:16px;color:var(--blue-400)"></i><?= e($d['name']) ?></a></td>
              <td class="text-tertiary">—</td>
              <td class="mono text-tertiary"><?= e($d['perms']) ?></td>
              <td class="text-tertiary"><?= e(date('Y-m-d H:i', $d['mtime'])) ?></td>
              <td style="text-align:right">
                <button class="btn btn-danger btn-sm" data-fm-delete="<?= e($d['rel']) ?>" title="Delete"><i data-lucide="trash-2"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php foreach ($listing['files'] as $f): ?>
            <tr>
              <td><a href="<?= e(url('file-view', ['path' => $f['rel']])) ?>" class="flex items-center gap-2"><i data-lucide="file" style="width:16px;height:16px;color:var(--text-tertiary)"></i><?= e($f['name']) ?></a></td>
              <td class="mono text-tertiary"><?= e(human_bytes($f['size'])) ?></td>
              <td class="mono text-tertiary"><?= e($f['perms']) ?></td>
              <td class="text-tertiary"><?= e(date('Y-m-d H:i', $f['mtime'])) ?></td>
              <td style="text-align:right;white-space:nowrap">
                <a class="btn btn-secondary btn-sm" href="<?= e(url('file-download', ['path' => $f['rel']])) ?>" title="Download"><i data-lucide="download"></i></a>
                <button class="btn btn-danger btn-sm" data-fm-delete="<?= e($f['rel']) ?>" title="Delete"><i data-lucide="trash-2"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<script>window.NEBULA_PAGE = 'files';</script>
