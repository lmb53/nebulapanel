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
$listing = $abs ? fm_list($abs) : ['dirs' => [], 'files' => [], 'total' => 0, 'truncated' => false];
$breadcrumbs = fm_breadcrumbs($rel);
$fmState = fm_state();
$pinnedEntries = fm_state_entries('pinned');
$recentEntries = fm_state_entries('recent');
$currentPinned = in_array($rel, $fmState['pinned'], true);
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
?>
<?php
// --- Derived view data (does not touch the data block above) ---
// Build a single ordered list of entries (folders first, then files) with the
// presentation fields resolved, so the list and grid views share one source.
$fmEntries = [];
$trashCount = count(fm_trash_list());
foreach ($listing['dirs'] as $d) {
    $fmEntries[] = [
        'name'     => $d['name'],
        'rel'      => $d['rel'],
        'is_dir'   => true,
        'size_h'   => '—',
        'type'     => 'Folder',
        'mtime'    => $d['mtime'],
        'perms'    => $d['perms'],
        'owner'    => $d['owner'],
        'group'    => $d['group'],
        'icon'     => 'folder',
        'color'    => 'var(--purple-400)',
        'href'     => url('files', ['path' => $d['rel']]),
        'edit'     => '',
        'download' => '',
    ];
}
foreach ($listing['files'] as $f) {
    [$ic, $icColor] = $fmIcon($f['ext']);
    $fmEntries[] = [
        'name'     => $f['name'],
        'rel'      => $f['rel'],
        'is_dir'   => false,
        'size_h'   => human_bytes($f['size']),
        'type'     => $fmType($f['ext']),
        'mtime'    => $f['mtime'],
        'perms'    => $f['perms'],
        'owner'    => $f['owner'],
        'group'    => $f['group'],
        'icon'     => $ic,
        'color'    => $icColor,
        'href'     => url('file-edit', ['path' => $f['rel']]),
        'edit'     => url('file-edit', ['path' => $f['rel']]),
        'download' => url('file-download', ['path' => $f['rel']]),
    ];
}
$itemCount = count($fmEntries);
$fileCount = count($listing['files']);
$dirCount  = count($listing['dirs']);
$totalSize = 0;
foreach ($listing['files'] as $f) {
    $totalSize += (int) $f['size'];
}
$curName  = end($breadcrumbs)['name'] ?? 'root';
$parentRel = '';
if ($rel !== '') {
    $parentRel = str_replace('\\', '/', dirname($rel));
    $parentRel = ($parentRel === '.' || $parentRel === '/') ? '' : $parentRel;
}
?>

<?php if (!$root_ok): ?>
  <div class="fm-app" style="padding:24px 28px">
    <div class="page-header">
      <div>
        <h1 class="page-title">File Manager</h1>
        <p class="page-subtitle">Confined to <span class="mono"><?= e($config['fm_root']) ?></span></p>
      </div>
    </div>
    <div class="card"><div class="empty-state">
      <div class="es-icon"><i data-lucide="folder-x"></i></div>
      <div class="es-title">Root directory not found</div>
      <div class="es-body"><span class="mono"><?= e($config['fm_root']) ?></span> does not exist. Set <span class="mono">NEBULA_FM_ROOT</span> or edit config.php.</div>
    </div></div>
  </div>
<?php else: ?>
  <div class="fm-app">

    <!-- Toolbar -->
    <div class="fm-toolbar">
      <div class="fm-crumbrow">
        <div class="fm-heading">
          <h1 class="fm-title">File Manager</h1>
          <nav class="breadcrumb fm-breadcrumb" aria-label="Current folder">
            <i data-lucide="house" aria-hidden="true"></i>
            <?php foreach ($breadcrumbs as $i => $c): ?>
              <?php $last = $i === count($breadcrumbs) - 1; ?>
              <?php if ($i > 0): ?><i data-lucide="chevron-right" aria-hidden="true"></i><?php endif; ?>
              <a href="<?= e(url('files', ['path' => $c['rel']])) ?>" class="<?= $last ? 'current' : '' ?>"<?= $last ? ' aria-current="page"' : '' ?>><?= e($c['name']) ?></a>
            <?php endforeach; ?>
          </nav>
        </div>
        <div class="page-actions" style="gap:8px">
          <a class="btn btn-secondary btn-sm" href="<?= e(url('files', ['path' => $rel])) ?>"><i data-lucide="refresh-cw"></i>Refresh</a>
          <button class="btn btn-primary btn-sm" type="button" id="fmUploadBtn"><i data-lucide="upload"></i>Upload</button>
        </div>
      </div>

      <div class="fm-actionrow" role="toolbar" aria-label="File actions">
        <button class="icon-btn" type="button" id="fmNewFile" title="New file" aria-label="New file"><i data-lucide="file-plus"></i></button>
        <button class="icon-btn" type="button" id="fmNewDir" title="New folder" aria-label="New folder"><i data-lucide="folder-plus"></i></button>
        <span class="sep" aria-hidden="true"></span>
        <button class="icon-btn" type="button" id="fmCopySelected" data-needs-selection title="Copy selected" aria-label="Copy selected"><i data-lucide="copy"></i></button>
        <button class="icon-btn" type="button" id="fmCutSelected" data-needs-selection title="Cut selected" aria-label="Cut selected"><i data-lucide="scissors"></i></button>
        <button class="icon-btn" type="button" id="fmPaste" title="Paste here" aria-label="Paste here"><i data-lucide="clipboard-paste"></i></button>
        <button class="icon-btn" type="button" id="fmCompressSelected" data-needs-selection title="Compress selected" aria-label="Compress selected"><i data-lucide="archive"></i></button>
        <button class="icon-btn<?= $currentPinned ? ' active' : '' ?>" type="button" id="fmPinCurrent" aria-pressed="<?= $currentPinned ? 'true' : 'false' ?>" title="<?= $currentPinned ? 'Unpin' : 'Pin' ?> this folder" aria-label="<?= $currentPinned ? 'Unpin' : 'Pin' ?> this folder"><i data-lucide="<?= $currentPinned ? 'pin-off' : 'pin' ?>"></i></button>
        <span class="sep" aria-hidden="true"></span>
        <button class="icon-btn danger" type="button" id="fmDeleteSelected" data-needs-selection title="Delete selected" aria-label="Delete selected"><i data-lucide="trash-2"></i></button>
        <div class="topbar-spacer"></div>
        <div class="fm-search"><i data-lucide="search" aria-hidden="true"></i><input id="fmSearch" type="search" placeholder="Filter this folder…" aria-label="Filter this folder"></div>
        <div class="fm-viewtoggle" id="fmViewToggle" role="group" aria-label="View">
          <button type="button" data-view="list" class="active" title="List view" aria-label="List view" aria-pressed="true"><i data-lucide="list"></i></button>
          <button type="button" data-view="grid" title="Grid view" aria-label="Grid view" aria-pressed="false"><i data-lucide="layout-grid"></i></button>
        </div>
        <input type="file" id="fmUpload" style="display:none" multiple aria-label="Files to upload" tabindex="-1">
      </div>
    </div>

    <!-- 3-pane split -->
    <div class="split fm-split">

      <!-- LEFT: tree -->
      <nav class="split-pane fm-tree-pane" aria-label="Folder tree">
        <div class="fm-tree-section-title">Location</div>
        <?php if ($rel !== ''): ?>
          <a class="tree-node tree-up" href="<?= e(url('files', ['path' => $parentRel])) ?>">
            <i data-lucide="corner-left-up" class="folder-ic" aria-hidden="true"></i><span>Up one folder</span>
          </a>
        <?php endif; ?>
        <?php foreach ($breadcrumbs as $i => $c): ?>
          <?php $isCur = $i === count($breadcrumbs) - 1; ?>
          <div class="tree-node<?= $isCur ? ' active' : '' ?>" style="margin-left:<?= (int) ($i * 12) ?>px" data-tree-row>
            <button class="tree-toggle<?= $isCur ? ' open' : '' ?>" type="button" data-tree-path="<?= e($c['rel']) ?>" aria-expanded="<?= $isCur ? 'true' : 'false' ?>" aria-label="Expand <?= e($c['name']) ?>"><i data-lucide="<?= $isCur ? 'chevron-down' : 'chevron-right' ?>"></i></button>
            <i data-lucide="<?= $isCur ? 'folder-open' : 'folder' ?>" class="folder-ic" aria-hidden="true" style="color:<?= $isCur ? 'var(--blue-400)' : 'var(--text-tertiary)' ?>"></i>
            <a href="<?= e(url('files', ['path' => $c['rel']])) ?>" title="<?= e($c['name']) ?>"><span><?= e($c['name']) ?></span></a>
          </div>
          <?php if ($isCur): ?><div class="tree-children" data-tree-children data-tree-parent="<?= e($c['rel']) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <?php foreach ($listing['dirs'] as $d): ?>
          <div class="tree-node" data-tree-row>
            <button class="tree-toggle" type="button" data-tree-path="<?= e($d['rel']) ?>" aria-expanded="false" aria-label="Expand <?= e($d['name']) ?>"><i data-lucide="chevron-right"></i></button>
            <i data-lucide="folder" class="folder-ic" aria-hidden="true" style="color:var(--purple-400)"></i>
            <a href="<?= e(url('files', ['path' => $d['rel']])) ?>" title="<?= e($d['name']) ?>"><span><?= e($d['name']) ?></span></a>
          </div>
        <?php endforeach; ?>
        <?php foreach ($listing['files'] as $file): ?>
          <?php [$treeIcon, $treeColor] = $fmIcon($file['ext']); ?>
          <a class="tree-node tree-file" href="<?= e(url('file-edit', ['path' => $file['rel']])) ?>" title="Edit <?= e($file['name']) ?>">
            <i data-lucide="<?= e($treeIcon) ?>" class="folder-ic" aria-hidden="true" style="color:<?= e($treeColor) ?>"></i><span><?= e($file['name']) ?></span>
          </a>
        <?php endforeach; ?>
        </div>
      </nav>
      <div class="split-divider"></div>

      <!-- CENTER: file listing (drag-and-drop target) -->
      <div class="split-pane fm-center-pane" id="fmCenter">

        <div class="tabs fm-tabs" id="fmTabs" role="tablist">
          <button class="tab active" type="button" role="tab" aria-selected="true" data-fm-tab="browse"><i data-lucide="folder-open"></i>Browse <span class="badge badge-slate"><?= (int) $itemCount ?></span></button>
          <button class="tab" type="button" role="tab" aria-selected="false" data-fm-tab="pinned"><i data-lucide="pin"></i>Pinned <span class="badge badge-slate"><?= count($pinnedEntries) ?></span></button>
          <button class="tab" type="button" role="tab" aria-selected="false" data-fm-tab="recent"><i data-lucide="history"></i>Recent <span class="badge badge-slate"><?= count($recentEntries) ?></span></button>
          <button class="tab" type="button" role="tab" aria-selected="false" data-fm-tab="trash"><i data-lucide="trash-2"></i>Trash <span class="badge badge-slate" id="fmTrashCount"><?= (int) $trashCount ?></span></button>
        </div>
        <div class="fm-tab-panel" data-fm-panel="browse" role="tabpanel">
        <?php if (!empty($listing['truncated'])): ?>
          <div class="notice notice-info" style="margin:12px 16px 0"><i data-lucide="info"></i><div>Showing the first <?= (int) FM_LIST_LIMIT ?> of <?= (int) $listing['total'] ?> entries (folders first, then files, by name). Use the Terminal for very large folders.</div></div>
        <?php endif; ?>

        <!-- List view -->
        <div class="table-wrap" id="fmListView">
          <table class="data-table fm-table">
            <thead>
              <tr>
                <th class="c-check"><input type="checkbox" id="fmSelectAll" aria-label="Select all items"></th>
                <th class="c-name">Name</th>
                <th class="c-size num">Size</th>
                <th class="c-type">Type</th>
                <th class="c-date">Modified</th>
                <th class="c-perm">Permissions</th>
                <th class="c-owner">Owner</th>
                <th class="c-actions"><span class="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$fmEntries): ?>
                <tr class="empty-row"><td colspan="8">This folder is empty. Drop files here or use Upload.</td></tr>
              <?php endif; ?>
              <?php foreach ($fmEntries as $en): ?>
                <tr data-ctx="true"
                    class="fm-row"
                    tabindex="-1"
                    data-name="<?= e($en['name']) ?>"
                    data-path="<?= e($en['rel']) ?>"
                    data-isdir="<?= $en['is_dir'] ? '1' : '0' ?>"
                    data-size="<?= e($en['size_h']) ?>"
                    data-type="<?= e($en['type']) ?>"
                    data-owner="<?= e($en['owner']) ?>"
                    data-group="<?= e($en['group']) ?>"
                    data-perms="<?= e($en['perms']) ?>"
                    data-modified="<?= e(fmt_datetime($en['mtime'])) ?>"
                    data-icon="<?= e($en['icon']) ?>"
                    data-color="<?= e($en['color']) ?>"
                    data-href="<?= e($en['href']) ?>"
                    data-edit="<?= e($en['edit']) ?>"
                    data-download="<?= e($en['download']) ?>">
                  <td class="c-check"><input type="checkbox" class="row-check" aria-label="Select <?= e($en['name']) ?>"></td>
                  <td class="c-name">
                    <div class="fm-name-cell">
                      <span class="f-ic-wrap" aria-hidden="true"><i data-lucide="<?= e($en['icon']) ?>" style="color:<?= e($en['color']) ?>"></i></span>
                      <a class="fname" href="<?= e($en['href']) ?>" title="<?= e($en['name']) ?>"><?= e($en['name']) ?></a>
                    </div>
                  </td>
                  <td class="c-size num mono text-tertiary"><?= e($en['size_h']) ?></td>
                  <td class="c-type text-tertiary"><?= e($en['type']) ?></td>
                  <td class="c-date text-tertiary"><time datetime="<?= e(iso_datetime($en['mtime'])) ?>"><?= e(fmt_datetime($en['mtime'])) ?></time></td>
                  <td class="c-perm"><button class="btn btn-ghost btn-sm mono" type="button" data-fm-chmod="<?= e($en['rel']) ?>" data-perms="<?= e($en['perms']) ?>" title="Change permissions" aria-label="Permissions <?= e($en['perms']) ?>, change"><?= e($en['perms']) ?></button></td>
                  <td class="c-owner text-tertiary" title="<?= e($en['owner'] . ':' . $en['group']) ?>"><?= e($en['owner']) ?></td>
                  <td class="c-actions">
                    <div class="fm-row-actions">
                      <?php if ($en['is_dir']): ?>
                        <a class="icon-btn" href="<?= e($en['href']) ?>" title="Open folder" aria-label="Open <?= e($en['name']) ?>"><i data-lucide="folder-open"></i></a>
                        <span class="icon-btn-spacer" aria-hidden="true"></span>
                      <?php else: ?>
                        <a class="icon-btn" href="<?= e($en['edit']) ?>" title="Edit" aria-label="Edit <?= e($en['name']) ?>"><i data-lucide="file-pen"></i></a>
                        <a class="icon-btn" href="<?= e($en['download']) ?>" title="Download" aria-label="Download <?= e($en['name']) ?>"><i data-lucide="download"></i></a>
                      <?php endif; ?>
                      <button class="icon-btn" type="button" data-fm-details title="Details" aria-label="Details for <?= e($en['name']) ?>"><i data-lucide="info"></i></button>
                      <button class="icon-btn" type="button" data-fm-rename="<?= e($en['rel']) ?>" data-name="<?= e($en['name']) ?>" title="Rename" aria-label="Rename <?= e($en['name']) ?>"><i data-lucide="text-cursor-input"></i></button>
                      <button class="icon-btn danger" type="button" data-fm-delete="<?= e($en['rel']) ?>" title="Delete" aria-label="Delete <?= e($en['name']) ?>"><i data-lucide="trash-2"></i></button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Grid view -->
        <div class="fm-grid hidden" id="fmGridView">
          <?php if (!$fmEntries): ?>
            <div class="fm-empty-hint" style="grid-column:1/-1">This folder is empty. Drop files here or use Upload.</div>
          <?php endif; ?>
          <?php foreach ($fmEntries as $en): ?>
            <div class="fm-card fm-row"
                 data-name="<?= e($en['name']) ?>"
                 data-path="<?= e($en['rel']) ?>"
                 data-isdir="<?= $en['is_dir'] ? '1' : '0' ?>"
                 data-size="<?= e($en['size_h']) ?>"
                 data-type="<?= e($en['type']) ?>"
                 data-owner="<?= e($en['owner']) ?>"
                 data-group="<?= e($en['group']) ?>"
                 data-perms="<?= e($en['perms']) ?>"
                 data-modified="<?= e(fmt_datetime($en['mtime'])) ?>"
                 data-icon="<?= e($en['icon']) ?>"
                 data-color="<?= e($en['color']) ?>"
                 data-href="<?= e($en['href']) ?>"
                 data-edit="<?= e($en['edit']) ?>"
                 data-download="<?= e($en['download']) ?>">
              <input type="checkbox" class="row-check" aria-label="Select <?= e($en['name']) ?>">
              <button class="icon-btn fm-card-details" type="button" data-fm-details title="Details" aria-label="Details for <?= e($en['name']) ?>"><i data-lucide="info"></i></button>
              <span class="f-ic-wrap" aria-hidden="true"><i data-lucide="<?= e($en['icon']) ?>" style="color:<?= e($en['color']) ?>"></i></span>
              <a class="fname" href="<?= e($en['href']) ?>" title="<?= e($en['name']) ?>"><?= e($en['name']) ?></a>
              <span class="fm-card-meta"><?= $en['is_dir'] ? 'Folder' : e($en['size_h']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        </div>

        <div class="fm-tab-panel hidden" data-fm-panel="pinned" role="tabpanel">
          <div class="fm-collection-head"><div><strong>Pinned folders</strong><span>Quick access locations</span></div></div>
          <div class="fm-collection-grid">
            <?php if (!$pinnedEntries): ?><div class="fm-empty-hint">Pin folders from Browse with the pin button to keep them here.</div><?php endif; ?>
            <?php foreach ($pinnedEntries as $pin): ?>
              <a class="fm-collection-card" href="<?= e(url('files', ['path' => $pin['rel']])) ?>"><i data-lucide="folder-heart" aria-hidden="true"></i><div><strong><?= e($pin['name']) ?></strong><span class="mono"><?= e($pin['rel'] ?: '/') ?></span></div><i data-lucide="chevron-right" aria-hidden="true"></i></a>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="fm-tab-panel hidden" data-fm-panel="recent" role="tabpanel">
          <div class="fm-collection-head"><div><strong>Recent files</strong><span>Files opened, edited, or downloaded most recently</span></div><button class="btn btn-secondary btn-sm" type="button" id="fmClearRecent"<?= $recentEntries ? '' : ' disabled' ?>><i data-lucide="list-x"></i>Clear list</button></div>
          <div class="fm-collection-list">
            <?php if (!$recentEntries): ?><div class="fm-empty-hint">No recently accessed files yet.</div><?php endif; ?>
            <?php foreach ($recentEntries as $recent): ?>
              <a class="fm-recent-row" href="<?= e(url('file-edit', ['path' => $recent['rel']])) ?>"><i data-lucide="file-clock" aria-hidden="true"></i><div><strong><?= e($recent['name']) ?></strong><span class="mono"><?= e($recent['rel']) ?></span></div><span><?= e(fmt_datetime($recent['mtime'])) ?></span><i data-lucide="chevron-right" aria-hidden="true"></i></a>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="fm-tab-panel hidden" data-fm-panel="trash" role="tabpanel">
          <div class="fm-collection-head"><div><strong>Trash</strong><span>Deleted items stay here until you restore them or empty the trash.</span></div><button class="btn btn-danger btn-sm" type="button" id="fmEmptyTrash"><i data-lucide="trash-2"></i>Empty trash</button></div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Name</th><th>Original location</th><th class="num">Size</th><th>Deleted</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead>
              <tbody id="fmTrashRows"><tr class="empty-row"><td colspan="5">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="split-divider"></div>

      <!-- RIGHT: properties -->
      <div class="fm-props-backdrop" id="fmPropsBackdrop"></div>
      <aside class="split-pane fm-right-pane" id="fmProps" aria-label="Item details">
        <button class="icon-btn fm-props-close" type="button" id="fmPropsClose" title="Close details" aria-label="Close details"><i data-lucide="x"></i></button>
        <div id="fmPropsEmpty" class="fm-empty-hint">Select an item to see its details.</div>
        <div id="fmPropsBody" class="hidden">
          <div class="fm-thumb" id="fmPropThumb"><i data-lucide="file"></i></div>
          <h2 id="fmPropName" class="fm-prop-name"></h2>
          <div id="fmPropPath" class="fm-prop-path mono"></div>

          <div class="fm-prop-row"><span class="k">Size</span><span class="v" id="fmPropSize"></span></div>
          <div class="fm-prop-row"><span class="k">Type</span><span class="v" id="fmPropType"></span></div>
          <div class="fm-prop-row"><span class="k">Owner</span><span class="v" id="fmPropOwner"></span></div>
          <div class="fm-prop-row"><span class="k">Group</span><span class="v" id="fmPropGroup"></span></div>
          <div class="fm-prop-row"><span class="k">Permissions</span><span class="v mono" id="fmPropPerms"></span></div>
          <div class="fm-prop-row"><span class="k">Modified</span><span class="v" id="fmPropModified"></span></div>

          <div class="fm-section-title">Permissions <span class="mono" id="fmModeLabel"></span></div>
          <div class="chmod-grid" id="fmChmodGrid" role="group" aria-label="Permission bits">
            <div></div><div class="hdr">Read</div><div class="hdr">Write</div><div class="hdr">Exec</div>
            <?php foreach (['Owner', 'Group', 'Other'] as $who): ?><div class="lbl"><?= $who ?></div><?php foreach (['read', 'write', 'execute'] as $bit): ?><div><input type="checkbox" data-perm-bit aria-label="<?= $who ?> <?= $bit ?>"></div><?php endforeach; ?><?php endforeach; ?>
          </div>
          <button class="btn btn-secondary btn-sm" type="button" id="fmSavePerms" style="width:100%;margin-top:10px"><i data-lucide="shield-check"></i>Apply permissions</button>

          <div class="fm-prop-actions">
            <a class="btn btn-secondary" id="fmPropOpen"><i data-lucide="file-pen" id="fmPropOpenIcon"></i><span id="fmPropOpenLabel">Open</span></a>
            <a class="btn btn-secondary" id="fmPropDownload"><i data-lucide="download"></i>Download</a>
            <button class="btn btn-danger" type="button" id="fmPropDelete"><i data-lucide="trash-2"></i>Delete</button>
          </div>
        </div>
      </aside>
    </div>

    <!-- Status bar -->
    <div class="fm-statusbar" role="status">
      <span><?= (int) $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?> · <?= (int) $dirCount ?> folder<?= $dirCount === 1 ? '' : 's' ?> · <?= (int) $fileCount ?> file<?= $fileCount === 1 ? '' : 's' ?> · <strong id="fmSelCount">0 selected</strong></span>
      <span>Total: <strong><?= e(human_bytes($totalSize)) ?></strong></span>
    </div>

    <!-- Context menu (keyboard: Shift+F10 / Menu key on a row; arrows to move) -->
    <div class="ctx-menu hidden" id="ctxMenu" role="menu" aria-label="Item actions">
      <button type="button" role="menuitem" class="ctx-item" data-ctx-act="new-file"><i data-lucide="file-plus"></i>New file</button>
      <button type="button" role="menuitem" class="ctx-item" data-ctx-act="new-folder"><i data-lucide="folder-plus"></i>New folder</button>
      <div class="ctx-sep" role="separator"></div>
      <button type="button" role="menuitem" class="ctx-item" data-ctx-act="open"><i data-lucide="file-pen"></i><span data-ctx-open-label>Edit</span></button>
      <button type="button" role="menuitem" class="ctx-item" data-ctx-act="download"><i data-lucide="download"></i>Download</button>
      <button type="button" role="menuitem" class="ctx-item" data-ctx-act="copy"><i data-lucide="copy"></i>Copy</button>
      <button type="button" role="menuitem" class="ctx-item" data-ctx-act="cut"><i data-lucide="scissors"></i>Cut</button>
      <button type="button" role="menuitem" class="ctx-item" data-ctx-act="paste"><i data-lucide="clipboard-paste"></i>Paste here</button>
      <button type="button" role="menuitem" class="ctx-item" data-ctx-act="compress"><i data-lucide="archive"></i>Compress</button>
      <div class="ctx-sep" role="separator"></div>
      <button type="button" role="menuitem" class="ctx-item" data-ctx-act="rename"><i data-lucide="text-cursor-input"></i>Rename</button>
      <button type="button" role="menuitem" class="ctx-item" data-ctx-act="chmod"><i data-lucide="lock"></i>Permissions</button>
      <button type="button" role="menuitem" class="ctx-item" data-ctx-act="details"><i data-lucide="info"></i>Details</button>
      <div class="ctx-sep" role="separator"></div>
      <button type="button" role="menuitem" class="ctx-item danger" data-ctx-act="delete"><i data-lucide="trash-2"></i>Delete</button>
    </div>
  </div>
<?php endif; ?>

<script>
const CURDIR = <?= json_encode($rel) ?>;
document.addEventListener('DOMContentLoaded', () => {
  const { apiGet, apiPost, toast, fmtBytes, fmtDate } = window.Nebula;
  const ask = window.Nebula.confirm, askText = window.Nebula.prompt, dialog = window.Nebula.dialog;
  const reload = () => setTimeout(() => location.reload(), 400);
  const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
  const bindClick = (id, fn) => document.getElementById(id)?.addEventListener('click', fn);
  const NAME_RE = /^[^\/\\\0]+$/;
  const validName = (v) => (!NAME_RE.test(v) || v === '.' || v === '..') ? 'Names cannot contain / or \\ and cannot be "." or "..".' : '';

  // ---- Tabs (Browse / Pinned / Recent / Trash) ----------------------------
  const fmTabs = Array.from(document.querySelectorAll('[data-fm-tab]'));
  const showTab = (name) => {
    fmTabs.forEach((item) => { const on = item.dataset.fmTab === name; item.classList.toggle('active', on); item.setAttribute('aria-selected', on ? 'true' : 'false'); });
    document.querySelectorAll('[data-fm-panel]').forEach((panel) => panel.classList.toggle('hidden', panel.dataset.fmPanel !== name));
    if (name === 'trash') loadTrash();
    history.replaceState(null, '', name === 'browse' ? location.pathname + location.search : '#' + name);
  };
  fmTabs.forEach((tab) => tab.addEventListener('click', () => showTab(tab.dataset.fmTab)));

  bindClick('fmPinCurrent', async () => {
    const res = await apiPost('file-state', { action: 'toggle-pin', path: CURDIR });
    if (res.ok) { toast(res.pinned ? 'Folder pinned' : 'Folder unpinned', 'success'); reload(); }
    else toast(res.error || 'Could not update the pin', 'error');
  });
  bindClick('fmClearRecent', async () => {
    const res = await apiPost('file-state', { action: 'clear-recent' });
    if (res.ok) { toast('Recent files cleared', 'success'); reload(); }
    else toast(res.error || 'Could not clear recent files', 'error');
  });

  // ---- Create / rename / chmod --------------------------------------------
  const createEntry = async (kind) => {
    const name = await askText({ title: kind === 'dir' ? 'New folder' : 'New file', label: 'Name', placeholder: kind === 'dir' ? 'assets' : 'index.html', validate: validName, confirmLabel: 'Create' });
    if (!name) return;
    const res = await apiPost(kind === 'dir' ? 'file-mkdir' : 'file-mkfile', { dir: CURDIR, name });
    if (res.ok) { toast(kind === 'dir' ? 'Folder created' : 'File created', 'success'); reload(); }
    else toast(res.error || 'Could not create it', 'error');
  };
  bindClick('fmNewDir', () => createEntry('dir'));
  bindClick('fmNewFile', () => createEntry('file'));

  const renameEntry = async (path, current) => {
    const name = await askText({ title: 'Rename', label: 'New name', value: current, validate: validName, selectStem: true, confirmLabel: 'Rename' });
    if (!name || name === current) return;
    const res = await apiPost('file-rename', { path, name });
    if (res.ok) { toast('Renamed', 'success'); reload(); }
    else toast(res.error || 'Rename failed', 'error');
  };
  const chmodEntry = async (path, perms) => {
    const mode = await askText({ title: 'Change permissions', label: 'Octal mode', value: perms || '', mono: true, placeholder: '0644',
      validate: (v) => /^0?[0-7]{3}$/.test(v) ? '' : 'Use three or four octal digits, e.g. 644 or 0755.', confirmLabel: 'Apply' });
    if (!mode) return;
    const res = await apiPost('file-chmod', { path, mode });
    if (res.ok) { toast('Permissions changed', 'success'); reload(); }
    else toast(res.error || 'Permissions change failed', 'error');
  };
  document.querySelectorAll('[data-fm-rename]').forEach((btn) => btn.addEventListener('click', (ev) => { ev.stopPropagation(); renameEntry(btn.dataset.fmRename, btn.dataset.name || ''); }));
  document.querySelectorAll('[data-fm-chmod]').forEach((btn) => btn.addEventListener('click', (ev) => { ev.stopPropagation(); chmodEntry(btn.dataset.fmChmod, btn.dataset.perms); }));

  // ---- Delete: one flow, trash by default, optional permanent delete -------
  async function deletePaths(paths) {
    if (!paths.length) return;
    const label = paths.length === 1 ? `“${paths[0].split('/').pop()}”` : `${paths.length} items`;
    const choice = await dialog({
      title: `Delete ${label}?`,
      message: paths.length === 1 ? `In /${paths[0].split('/').slice(0, -1).join('/')}. Items in the trash can be restored from the Trash tab.` : paths.slice(0, 5).join('\n') + (paths.length > 5 ? `\n…and ${paths.length - 5} more` : '') + '\n\nItems in the trash can be restored from the Trash tab.',
      danger: true, icon: 'trash-2', confirmLabel: 'Move to trash', optionConfirmLabel: 'Delete permanently',
      option: { label: 'Skip the trash and delete permanently', help: 'Permanently deleted items cannot be recovered.' },
    });
    if (!choice.ok) return;
    let permanent = choice.option;
    let ok = 0;
    for (const path of paths) {
      let res = await apiPost('file-delete', { path, permanent });
      if (!res.ok && res.code === 'trash_failed') {
        const force = await ask({ title: 'Can’t move to trash', message: `${path}\n\n${res.error}`, danger: true, confirmLabel: 'Delete permanently' });
        if (force) { permanent = true; res = await apiPost('file-delete', { path, permanent: true }); }
        else continue;
      }
      if (res.ok) ok++; else toast(`${path}: ${res.error || 'Delete failed'}`, 'error');
    }
    if (ok) {
      toast(permanent ? `Permanently deleted ${ok} item${ok === 1 ? '' : 's'}` : `Moved ${ok} item${ok === 1 ? '' : 's'} to the trash`, 'success');
      reload();
    }
  }
  document.querySelectorAll('[data-fm-delete]').forEach((btn) => btn.addEventListener('click', (ev) => { ev.stopPropagation(); deletePaths([btn.dataset.fmDelete]); }));

  // ---- Trash tab -----------------------------------------------------------
  const trashRows = document.getElementById('fmTrashRows');
  const trashCount = document.getElementById('fmTrashCount');
  async function loadTrash() {
    if (!trashRows) return;
    let res;
    try { res = await apiGet('file-trash'); } catch (e) { res = { ok: false, error: e.message }; }
    trashRows.replaceChildren();
    const items = res.items || [];
    if (trashCount) trashCount.textContent = String(items.length);
    document.getElementById('fmEmptyTrash').disabled = !items.length;
    if (!res.ok || !items.length) {
      const tr = document.createElement('tr'); tr.className = 'empty-row';
      const td = document.createElement('td'); td.colSpan = 5; td.textContent = res.ok ? 'The trash is empty.' : (res.error || 'Could not load the trash.');
      tr.appendChild(td); trashRows.appendChild(tr); return;
    }
    items.forEach((item) => {
      const tr = document.createElement('tr');
      const name = document.createElement('td');
      name.innerHTML = `<div class="fm-name-cell"><span class="f-ic-wrap" aria-hidden="true"><i data-lucide="${item.is_dir ? 'folder' : 'file'}"></i></span><span class="fname"></span></div>`;
      name.querySelector('.fname').textContent = item.name; name.querySelector('.fname').title = item.name;
      const where = document.createElement('td'); where.className = 'mono text-tertiary'; where.textContent = '/' + (item.rel.split('/').slice(0, -1).join('/'));
      const size = document.createElement('td'); size.className = 'num mono text-tertiary'; size.textContent = fmtBytes(item.size);
      const when = document.createElement('td'); when.className = 'text-tertiary'; when.textContent = fmtDate(item.deleted_at) + (item.deleted_by ? ` · ${item.deleted_by}` : '');
      const act = document.createElement('td'); act.className = 'actions-col';
      const restore = document.createElement('button'); restore.type = 'button'; restore.className = 'btn btn-secondary btn-sm'; restore.innerHTML = '<i data-lucide="undo-2"></i>Restore';
      restore.addEventListener('click', async () => {
        restore.disabled = true;
        const r = await apiPost('file-trash', { action: 'restore', id: item.id });
        if (r.ok) { toast(`Restored to /${r.path}`, 'success'); loadTrash(); } else { toast(r.error || 'Restore failed', 'error'); restore.disabled = false; }
      });
      const purge = document.createElement('button'); purge.type = 'button'; purge.className = 'icon-btn danger'; purge.title = 'Delete permanently'; purge.setAttribute('aria-label', `Delete ${item.name} permanently`); purge.innerHTML = '<i data-lucide="trash-2"></i>';
      purge.addEventListener('click', async () => {
        if (!await ask({ title: `Delete “${item.name}” permanently?`, message: 'This cannot be undone.', danger: true, confirmLabel: 'Delete permanently' })) return;
        const r = await apiPost('file-trash', { action: 'purge', id: item.id });
        if (r.ok) { toast('Deleted permanently', 'success'); loadTrash(); } else toast(r.error || 'Delete failed', 'error');
      });
      act.append(restore, purge);
      tr.append(name, where, size, when, act); trashRows.appendChild(tr);
    });
    if (window.lucide) lucide.createIcons();
  }
  bindClick('fmEmptyTrash', async () => {
    if (!await ask({ title: 'Empty the trash?', message: 'Everything in the trash will be deleted permanently. This cannot be undone.', danger: true, confirmLabel: 'Empty trash' })) return;
    const r = await apiPost('file-trash', { action: 'empty' });
    if (r.ok) toast('Trash emptied', 'success'); else toast(r.error || 'Could not empty the trash', 'error');
    loadTrash();
  });

  // ---- Upload (button + hidden input; supports multiple) -------------------
  const fileInput = document.getElementById('fmUpload');
  bindClick('fmUploadBtn', () => fileInput?.click());

  async function uploadFile(file, overwrite = false) {
    const fd = new FormData();
    fd.append('dir', CURDIR);
    fd.append('file', file);
    fd.append('overwrite', overwrite ? '1' : '0');
    try {
      const r = await fetch(window.Nebula.api('file-upload'), {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf() },
        body: fd,
      });
      // nginx answers an oversized body with an HTML 413 page, not JSON.
      const text = await r.text();
      let res;
      try { res = JSON.parse(text); }
      catch (e) {
        res = { ok: false, error: r.status === 413
          ? `${file.name} is larger than the web server accepts. Re-run install.sh to apply the panel's upload limits.`
          : `Upload failed: ${file.name} (HTTP ${r.status})` };
      }
      if (res.ok) { toast(`${res.overwritten ? 'Replaced' : 'Uploaded'} ${file.name}`, 'success'); return true; }
      if (res.conflict) {
        if (await ask({ title: 'Replace existing file?', message: `"${file.name}" already exists. Replace it with the uploaded file?`, warning: true, icon: 'file-up', confirmLabel: 'Replace' })) {
          return uploadFile(file, true);
        }
        toast(`Skipped ${file.name}`, 'info');
        return false;
      }
      toast(res.error || `Upload failed: ${file.name}`, 'error');
    } catch (e) { toast(`Upload failed: ${file.name}`, 'error'); }
    return false;
  }
  async function uploadFiles(files) {
    let any = false;
    for (const f of files) { if (await uploadFile(f)) any = true; }
    if (any) reload();
  }
  fileInput?.addEventListener('change', async () => {
    if (fileInput.files.length) await uploadFiles(Array.from(fileInput.files));
    fileInput.value = '';
  });

  // ---- Drag-and-drop upload onto the center pane ---------------------------
  const center = document.getElementById('fmCenter');
  if (center) {
    ['dragenter', 'dragover'].forEach((ev) => center.addEventListener(ev, (e) => {
      if (e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) { e.preventDefault(); center.classList.add('fm-dragover'); }
    }));
    ['dragleave', 'dragend'].forEach((ev) => center.addEventListener(ev, (e) => { if (e.target === center) center.classList.remove('fm-dragover'); }));
    center.addEventListener('drop', async (e) => {
      if (!e.dataTransfer || !e.dataTransfer.files.length) return;
      e.preventDefault();
      center.classList.remove('fm-dragover');
      await uploadFiles(Array.from(e.dataTransfer.files));
    });
  }

  // ---- List / grid view toggle (persisted) --------------------------------
  const listView = document.getElementById('fmListView');
  const gridView = document.getElementById('fmGridView');
  const toggle = document.getElementById('fmViewToggle');
  function setView(v) {
    const grid = v === 'grid';
    gridView?.classList.toggle('hidden', !grid);
    listView?.classList.toggle('hidden', grid);
    toggle?.querySelectorAll('button').forEach((b) => { b.classList.toggle('active', b.dataset.view === v); b.setAttribute('aria-pressed', b.dataset.view === v ? 'true' : 'false'); });
    try { localStorage.setItem('nebula.fm.view', v); } catch (e) {}
    clearSelection();
  }
  toggle?.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => setView(b.dataset.view)));

  // ---- Folder filter -------------------------------------------------------
  const search = document.getElementById('fmSearch');
  search?.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    document.querySelectorAll('.fm-row').forEach((row) => row.classList.toggle('hidden', !!q && !(row.dataset.name || '').toLowerCase().includes(q)));
  });

  // ---- Selection: the row checkbox is the single source of truth ----------
  function activeContainer() { return (gridView && !gridView.classList.contains('hidden')) ? gridView : listView; }
  const checkedRows = () => Array.from(activeContainer()?.querySelectorAll('.row-check:checked') || []).map((cb) => cb.closest('.fm-row')).filter(Boolean);
  const selectedPaths = (fallbackRow = null) => {
    const paths = checkedRows().map((row) => row.dataset.path);
    if (!paths.length && fallbackRow?.dataset.path) paths.push(fallbackRow.dataset.path);
    return Array.from(new Set(paths));
  };
  const readClipboard = () => { try { const c = JSON.parse(localStorage.getItem('nebula.fm.clipboard') || 'null'); return c?.paths?.length && ['copy', 'move'].includes(c.op) ? c : null; } catch (e) { return null; } };
  function syncSelection() {
    const rows = checkedRows();
    document.querySelectorAll('.fm-row').forEach((row) => row.classList.toggle('fm-row-selected', !!row.querySelector('.row-check')?.checked));
    const el = document.getElementById('fmSelCount');
    if (el) el.textContent = `${rows.length} selected`;
    document.querySelectorAll('[data-needs-selection]').forEach((btn) => { btn.disabled = !rows.length; });
    const paste = document.getElementById('fmPaste');
    const clip = readClipboard();
    if (paste) { paste.disabled = !clip; paste.title = clip ? `Paste ${clip.paths.length} item(s) here (${clip.op === 'move' ? 'move' : 'copy'})` : 'Clipboard is empty'; }
    const all = document.getElementById('fmSelectAll');
    if (all) {
      const boxes = listView?.querySelectorAll('.fm-row:not(.hidden) .row-check') || [];
      const n = Array.from(boxes).filter((b) => b.checked).length;
      all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length;
    }
  }
  function clearSelection() { document.querySelectorAll('.row-check').forEach((cb) => { cb.checked = false; }); syncSelection(); }
  function selectOnly(row) {
    if (row.querySelector('.row-check')?.checked) return;
    document.querySelectorAll('.row-check').forEach((cb) => { cb.checked = false; });
    const cb = row.querySelector('.row-check'); if (cb) cb.checked = true;
    syncSelection();
  }
  document.querySelectorAll('.row-check').forEach((cb) => {
    cb.addEventListener('click', (e) => e.stopPropagation());
    cb.addEventListener('change', syncSelection);
  });
  document.getElementById('fmSelectAll')?.addEventListener('change', (e) => {
    listView?.querySelectorAll('.fm-row:not(.hidden) .row-check').forEach((cb) => { cb.checked = e.target.checked; });
    syncSelection();
  });
  document.querySelectorAll('.fm-row').forEach((row) => {
    row.addEventListener('click', (e) => {
      // Name links navigate; clicking elsewhere on a row toggles its selection.
      if (e.target.closest('a, button, input')) return;
      const cb = row.querySelector('.row-check');
      if (cb) { cb.checked = !cb.checked; syncSelection(); }
    });
  });
  let savedView = 'list';
  try { savedView = localStorage.getItem('nebula.fm.view') || 'list'; } catch (e) {}
  setView(savedView);
  window.addEventListener('storage', (e) => { if (e.key === 'nebula.fm.clipboard') syncSelection(); });

  // ---- Properties drawer ---------------------------------------------------
  const propsEmpty = document.getElementById('fmPropsEmpty');
  const propsBody = document.getElementById('fmPropsBody');
  const propsPane = document.getElementById('fmProps');
  const propsBackdrop = document.getElementById('fmPropsBackdrop');
  let propsRow = null, propsReturn = null;
  const closeProps = () => {
    if (!propsPane?.classList.contains('open')) return;
    propsPane.classList.remove('open'); propsBackdrop?.classList.remove('open');
    propsReturn?.focus?.();
  };
  const permissionBits = [4, 2, 1, 4, 2, 1, 4, 2, 1];
  function fillPermissionGrid(mode) {
    const digits = String(mode || '000').slice(-3).padStart(3, '0').split('').map((d) => parseInt(d, 8) || 0);
    document.querySelectorAll('[data-perm-bit]').forEach((cb, i) => { cb.checked = !!(digits[Math.floor(i / 3)] & permissionBits[i]); });
    const label = document.getElementById('fmModeLabel');
    if (label) label.textContent = '(' + String(mode || '').padStart(4, '0') + ')';
  }
  function permissionMode() {
    const boxes = Array.from(document.querySelectorAll('[data-perm-bit]'));
    let mode = '';
    for (let r = 0; r < 3; r++) {
      let digit = 0;
      for (let c = 0; c < 3; c++) if (boxes[r * 3 + c]?.checked) digit += permissionBits[r * 3 + c];
      mode += String(digit);
    }
    return '0' + mode;
  }
  document.querySelectorAll('[data-perm-bit]').forEach((cb) => cb.addEventListener('change', () => {
    const label = document.getElementById('fmModeLabel'); if (label) label.textContent = '(' + permissionMode() + ')';
  }));
  function showProps(row) {
    if (!propsBody) return;
    propsRow = row;
    propsReturn = document.activeElement;
    const d = row.dataset;
    const isDir = d.isdir === '1';
    document.getElementById('fmPropName').textContent = d.name;
    document.getElementById('fmPropPath').textContent = '/' + (d.path || '');
    document.getElementById('fmPropSize').textContent = isDir ? '—' : d.size;
    document.getElementById('fmPropType').textContent = d.type;
    document.getElementById('fmPropOwner').textContent = d.owner || '—';
    document.getElementById('fmPropGroup').textContent = d.group || '—';
    document.getElementById('fmPropPerms').textContent = d.perms;
    document.getElementById('fmPropModified').textContent = d.modified;
    fillPermissionGrid(d.perms);
    const thumb = document.getElementById('fmPropThumb');
    if (thumb) {
      const ico = document.createElement('i');
      ico.setAttribute('data-lucide', d.icon || 'file');
      ico.style.color = d.color || 'var(--text-tertiary)';
      thumb.replaceChildren(ico);
    }
    const open = document.getElementById('fmPropOpen');
    const dl = document.getElementById('fmPropDownload');
    open.href = d.href || '#';
    document.getElementById('fmPropOpenLabel').textContent = isDir ? 'Open folder' : 'Open in editor';
    const openIcon = document.createElement('i'); openIcon.setAttribute('data-lucide', isDir ? 'folder-open' : 'file-pen');
    open.querySelector('[data-lucide], svg')?.replaceWith(openIcon);
    dl.classList.toggle('hidden', isDir);
    dl.href = d.download || '#';
    document.getElementById('fmPropDelete').onclick = () => deletePaths([d.path]);
    propsEmpty?.classList.add('hidden');
    propsBody.classList.remove('hidden');
    propsPane?.classList.add('open');
    propsBackdrop?.classList.add('open');
    if (window.lucide) window.lucide.createIcons();
    document.getElementById('fmPropsClose')?.focus();
  }
  document.getElementById('fmPropOpen')?.addEventListener('click', (e) => {
    if (propsRow?.dataset.isdir !== '1') { e.preventDefault(); window.Nebula.openEditor(propsRow.dataset.href); }
  });
  bindClick('fmPropsClose', closeProps);
  bindClick('fmPropsBackdrop', closeProps);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeProps(); });
  bindClick('fmSavePerms', async () => {
    if (!propsRow) return;
    const res = await apiPost('file-chmod', { path: propsRow.dataset.path, mode: permissionMode() });
    if (res.ok) { toast('Permissions changed', 'success'); reload(); }
    else toast(res.error || 'Permissions change failed', 'error');
  });
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-fm-details]');
    if (!button) return;
    event.preventDefault(); event.stopPropagation();
    const row = button.closest('.fm-row');
    if (row) { selectOnly(row); showProps(row); }
  });

  // ---- Clipboard / compress / bulk delete ----------------------------------
  function setClipboard(op, fallbackRow = null) {
    const paths = selectedPaths(fallbackRow);
    if (!paths.length) { toast('Select at least one item', 'warning'); return; }
    localStorage.setItem('nebula.fm.clipboard', JSON.stringify({ op, paths }));
    toast(`${paths.length} item(s) ${op === 'move' ? 'cut — paste in another folder to move them' : 'copied'}`, 'success');
    syncSelection();
  }
  async function pasteClipboard() {
    const clip = readClipboard();
    if (!clip) { toast('Clipboard is empty', 'warning'); return; }
    let ok = 0;
    for (const path of clip.paths) {
      const res = await apiPost('file-op', { path, dest: CURDIR, op: clip.op });
      if (res.ok) ok++; else toast(`${path}: ${res.error || 'Paste failed'}`, 'error');
    }
    if (ok) {
      if (clip.op === 'move' && ok === clip.paths.length) localStorage.removeItem('nebula.fm.clipboard');
      toast(`Pasted ${ok} item(s)`, 'success'); reload();
    }
  }
  async function compressPaths(paths) {
    if (!paths.length) { toast('Select at least one item', 'warning'); return; }
    const suggested = paths.length === 1 ? (paths[0].split('/').pop() || 'archive') : 'archive';
    const name = await askText({ title: 'Compress', label: 'Archive name (.zip or .tar.gz)', value: suggested.replace(/\.(tar\.gz|[^.]+)$/i, '') + '.zip',
      validate: (v) => validName(v) || (/\.(zip|tar\.gz|tgz)$/i.test(v) ? '' : 'End the name with .zip or .tar.gz'), selectStem: true, confirmLabel: 'Create archive' });
    if (!name) return;
    const res = await apiPost('file-compress', { paths, dest: CURDIR, name });
    if (res.ok) { toast(`Created ${name}`, 'success'); reload(); }
    else toast(res.error || 'Compression failed', 'error');
  }
  bindClick('fmCopySelected', () => setClipboard('copy'));
  bindClick('fmCutSelected', () => setClipboard('move'));
  bindClick('fmPaste', pasteClipboard);
  bindClick('fmCompressSelected', () => compressPaths(selectedPaths()));
  bindClick('fmDeleteSelected', () => deletePaths(selectedPaths()));

  // ---- Context menu (mouse + keyboard) -------------------------------------
  const ctx = document.getElementById('ctxMenu');
  if (ctx) {
    let ctxRow = null, ctxReturn = null;
    const items = () => Array.from(ctx.querySelectorAll('.ctx-item')).filter((el) => !el.classList.contains('hidden') && !el.disabled);
    const hideCtx = () => {
      if (ctx.classList.contains('hidden')) return;
      ctx.classList.add('hidden');
      if (ctx.contains(document.activeElement)) ctxReturn?.focus?.();
    };
    const openContext = (x, y, row = null) => {
      ctxRow = row;
      ctxReturn = document.activeElement;
      if (row) selectOnly(row);
      const isFile = row && row.dataset.isdir !== '1';
      ['open', 'rename', 'chmod', 'delete', 'details', 'copy', 'cut', 'compress'].forEach((act) => ctx.querySelector(`[data-ctx-act="${act}"]`)?.classList.toggle('hidden', !row));
      ctx.querySelector('[data-ctx-act="download"]').classList.toggle('hidden', !isFile);
      ctx.querySelector('[data-ctx-open-label]').textContent = isFile ? 'Edit' : 'Open';
      ctx.querySelector('[data-ctx-act="paste"]').disabled = !readClipboard();
      // Hide separators that would sit next to each other or at the edges.
      let previousVisible = null;
      ctx.querySelectorAll('.ctx-item, .ctx-sep').forEach((el) => {
        if (el.classList.contains('ctx-sep')) { el.classList.toggle('hidden', !previousVisible || previousVisible.classList.contains('ctx-sep')); if (!el.classList.contains('hidden')) previousVisible = el; }
        else if (!el.classList.contains('hidden')) previousVisible = el;
      });
      if (previousVisible?.classList.contains('ctx-sep')) previousVisible.classList.add('hidden');
      ctx.classList.remove('hidden');
      ctx.style.position = 'fixed';
      const mw = ctx.offsetWidth || 200, mh = ctx.offsetHeight || 260;
      ctx.style.left = Math.max(8, Math.min(x, window.innerWidth - mw - 8)) + 'px';
      ctx.style.top = Math.max(8, Math.min(y, window.innerHeight - mh - 8)) + 'px';
      if (window.lucide) lucide.createIcons();
      items()[0]?.focus();
    };
    document.querySelectorAll('.fm-row').forEach((row) => {
      row.addEventListener('contextmenu', (e) => { e.preventDefault(); e.stopPropagation(); openContext(e.clientX, e.clientY, row); });
      row.addEventListener('keydown', (e) => {
        if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) {
          e.preventDefault();
          const r = row.getBoundingClientRect();
          openContext(r.left + 48, r.top + r.height, row);
        }
      });
      // Focus anywhere inside a row makes the row the keyboard context target.
      row.addEventListener('focusin', () => { row.tabIndex = 0; });
    });
    center?.addEventListener('contextmenu', (e) => { if (!e.target.closest('.fm-row')) { e.preventDefault(); openContext(e.clientX, e.clientY, null); } });
    ctx.addEventListener('keydown', (e) => {
      const list = items(); const i = list.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length]?.focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length]?.focus(); }
      else if (e.key === 'Home') { e.preventDefault(); list[0]?.focus(); }
      else if (e.key === 'End') { e.preventDefault(); list[list.length - 1]?.focus(); }
      else if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); hideCtx(); }
    });
    ctx.querySelectorAll('[data-ctx-act]').forEach((item) => {
      item.addEventListener('click', (e) => {
        e.stopPropagation();
        const act = item.dataset.ctxAct;
        hideCtx();
        if (act === 'new-file') return createEntry('file');
        if (act === 'new-folder') return createEntry('dir');
        if (act === 'paste') return pasteClipboard();
        if (!ctxRow) return;
        if (act === 'copy') return setClipboard('copy', ctxRow);
        if (act === 'cut') return setClipboard('move', ctxRow);
        if (act === 'compress') return compressPaths(selectedPaths(ctxRow));
        if (act === 'details') return showProps(ctxRow);
        if (act === 'chmod') return chmodEntry(ctxRow.dataset.path, ctxRow.dataset.perms);
        if (act === 'rename') return renameEntry(ctxRow.dataset.path, ctxRow.dataset.name || '');
        if (act === 'delete') return deletePaths(selectedPaths(ctxRow));
        if (act === 'download' && ctxRow.dataset.download) { location.href = ctxRow.dataset.download; return; }
        if (act === 'open') {
          if (ctxRow.dataset.isdir === '1') location.href = ctxRow.dataset.href;
          else window.Nebula.openEditor(ctxRow.dataset.href);
        }
      });
    });
    document.addEventListener('click', hideCtx);
    document.addEventListener('scroll', hideCtx, true);
    window.addEventListener('resize', hideCtx);
  }
  syncSelection();
  const tabFromHash = () => { if (['#pinned', '#recent', '#trash'].includes(location.hash)) showTab(location.hash.slice(1)); };
  tabFromHash();
  window.addEventListener('hashchange', tabFromHash);
});
</script>
