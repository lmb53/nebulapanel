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
$fmState = fm_state();
$pinnedEntries = fm_state_entries('pinned');
$recentEntries = fm_state_entries('recent');
$ownerOptions = fm_account_names('user');
$groupOptions = fm_account_names('group');
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
// Inline style for the rounded icon badge in the Name column.
$iconWrap = 'width:28px;height:28px;border-radius:7px;background:var(--bg-surface-2);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0';
?>
<?php
// --- Derived view data (does not touch the data block above) ---
// Build a single ordered list of entries (folders first, then files) with the
// presentation fields resolved, so the list and grid views share one source.
$fmEntries = [];
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
    $parentRel = trim(dirname($rel), '.');
    $parentRel = $parentRel === '/' ? '' : $parentRel;
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
      <div style="font-weight:600;color:var(--text-secondary)">Root directory not found</div>
      <div style="font-size:13px;margin-top:4px"><span class="mono"><?= e($config['fm_root']) ?></span> does not exist. Set <span class="mono">NEBULA_FM_ROOT</span> or edit config.php.</div>
    </div></div>
  </div>
<?php else: ?>
  <div class="fm-app">

    <!-- Toolbar -->
    <div class="fm-toolbar">
      <div class="fm-crumbrow">
        <div class="breadcrumb" style="margin-bottom:0;flex-wrap:wrap">
          <i data-lucide="home" style="color:var(--text-tertiary)"></i>
          <?php foreach ($breadcrumbs as $i => $c): ?>
            <?php if ($i > 0): ?><i data-lucide="chevron-right"></i><?php endif; ?>
            <a href="<?= e(url('files', ['path' => $c['rel']])) ?>" style="color:<?= $i === count($breadcrumbs) - 1 ? 'var(--text-primary)' : 'var(--text-tertiary)' ?>;text-decoration:none;<?= $i === count($breadcrumbs) - 1 ? 'font-weight:600' : '' ?>"><?= e($c['name']) ?></a>
          <?php endforeach; ?>
        </div>
        <div class="page-actions" style="gap:8px">
          <a class="btn btn-secondary btn-sm" href="<?= e(url('files', ['path' => $rel])) ?>"><i data-lucide="refresh-cw"></i>Refresh</a>
          <button class="btn btn-primary btn-sm" id="fmUploadBtn"><i data-lucide="upload"></i>Upload</button>
        </div>
      </div>

      <div class="fm-actionrow">
        <button class="icon-btn" id="fmNewFile" title="New File"><i data-lucide="file-plus"></i></button>
        <button class="icon-btn" id="fmNewDir" title="New Folder"><i data-lucide="folder-plus"></i></button>
        <button class="icon-btn" id="fmUploadBtn2" title="Upload"><i data-lucide="upload"></i></button>
        <span class="sep"></span>
        <button class="icon-btn" id="fmCopySelected" title="Copy"><i data-lucide="copy"></i></button>
        <button class="icon-btn" id="fmCutSelected" title="Cut"><i data-lucide="scissors"></i></button>
        <button class="icon-btn" id="fmPaste" title="Paste"><i data-lucide="clipboard-paste"></i></button>
        <button class="icon-btn" id="fmCompressSelected" title="Compress selected items"><i data-lucide="archive"></i></button>
        <button class="icon-btn<?= $currentPinned ? ' active' : '' ?>" id="fmPinCurrent" title="<?= $currentPinned ? 'Unpin' : 'Pin' ?> this folder"><i data-lucide="pin"></i></button>
        <span class="sep"></span>
        <button class="icon-btn" id="fmDeleteSelected" title="Delete selected" style="color:var(--red-400)"><i data-lucide="trash-2"></i></button>
        <span class="sep"></span>
        <div class="topbar-spacer"></div>
        <div class="fm-search"><i data-lucide="search" style="width:13px;height:13px;color:var(--text-tertiary)"></i><input id="fmSearch" placeholder="Search this folder..."></div>
        <div class="fm-viewtoggle" id="fmViewToggle">
          <button data-view="list" class="active" title="List view"><i data-lucide="list"></i></button>
          <button data-view="grid" title="Grid view"><i data-lucide="layout-grid"></i></button>
        </div>
        <input type="file" id="fmUpload" style="display:none" multiple>
      </div>
    </div>

    <!-- 3-pane split -->
    <div class="split fm-split">

      <!-- LEFT: tree -->
      <div class="split-pane fm-tree-pane">
        <div class="fm-tree-section-title">Pinned</div>
        <?php if (!$pinnedEntries): ?><div class="fm-empty-hint" style="padding:8px;text-align:left">No pinned folders</div><?php endif; ?>
        <?php foreach ($pinnedEntries as $pin): ?>
          <a class="tree-node" href="<?= e(url('files', ['path' => $pin['rel']])) ?>">
            <i data-lucide="pin" class="chev"></i><i data-lucide="folder" class="folder-ic" style="color:var(--purple-400)"></i><span><?= e($pin['name']) ?></span>
          </a>
        <?php endforeach; ?>
        <div class="fm-tree-section-title">Location</div>
        <?php foreach ($breadcrumbs as $i => $c): ?>
          <?php $isCur = $i === count($breadcrumbs) - 1; ?>
          <a class="tree-node<?= $isCur ? ' active' : '' ?>" href="<?= e(url('files', ['path' => $c['rel']])) ?>" style="margin-left:<?= (int) ($i * 12) ?>px">
            <i data-lucide="<?= $isCur ? 'chevron-down' : 'chevron-right' ?>" class="chev<?= $isCur ? ' open' : '' ?>"></i>
            <i data-lucide="<?= $isCur ? 'folder-open' : 'folder' ?>" class="folder-ic" style="color:<?= $isCur ? 'var(--blue-400)' : 'var(--text-tertiary)' ?>"></i>
            <span><?= e($c['name']) ?></span>
          </a>
        <?php endforeach; ?>

        <?php if ($rel !== ''): ?>
          <a class="tree-node" href="<?= e(url('files', ['path' => $parentRel])) ?>">
            <i data-lucide="corner-left-up" class="folder-ic" style="color:var(--text-tertiary)"></i>
            <span>..</span>
          </a>
        <?php endif; ?>
        <?php if ($listing['dirs']): ?>
          <?php foreach ($listing['dirs'] as $d): ?>
            <a class="tree-node" href="<?= e(url('files', ['path' => $d['rel']])) ?>">
              <i data-lucide="chevron-right" class="chev"></i>
              <i data-lucide="folder" class="folder-ic" style="color:var(--purple-400)"></i>
              <span><?= e($d['name']) ?></span>
            </a>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="fm-empty-hint" style="padding:10px 8px;text-align:left">No subfolders</div>
        <?php endif; ?>
        <?php foreach ($listing['files'] as $file): ?>
          <?php [$treeIcon, $treeColor] = $fmIcon($file['ext']); ?>
          <a class="tree-node" href="<?= e(url('file-edit', ['path' => $file['rel']])) ?>" style="margin-left:12px" title="Edit <?= e($file['name']) ?>">
            <i data-lucide="<?= e($treeIcon) ?>" class="folder-ic" style="color:<?= e($treeColor) ?>"></i><span><?= e($file['name']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="split-divider"></div>

      <!-- CENTER: file listing (drag-and-drop target) -->
      <div class="split-pane fm-center-pane" id="fmCenter">

        <div class="tabs fm-tabs" id="fmTabs">
          <button class="tab active" type="button" data-fm-tab="browse"><i data-lucide="folder-open"></i>Browse <span class="badge badge-slate"><?= (int) $itemCount ?></span></button>
          <button class="tab" type="button" data-fm-tab="pinned"><i data-lucide="pin"></i>Pinned <span class="badge badge-slate"><?= count($pinnedEntries) ?></span></button>
          <button class="tab" type="button" data-fm-tab="recent"><i data-lucide="history"></i>Recent <span class="badge badge-slate"><?= count($recentEntries) ?></span></button>
        </div>
        <div class="fm-tab-panel" data-fm-panel="browse">

        <!-- List view -->
        <div class="table-wrap" id="fmListView">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width:34px"><input type="checkbox" id="fmSelectAll"></th>
                <th>Name</th>
                <th>Size</th>
                <th>Type</th>
                <th>Modified</th>
                <th>Permissions</th>
                <th>Owner</th>
                <th style="text-align:right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$fmEntries): ?>
                <tr><td colspan="8" class="text-tertiary" style="padding:24px;text-align:center">Empty directory</td></tr>
              <?php endif; ?>
              <?php foreach ($fmEntries as $en): ?>
                <tr data-ctx="true"
                    class="fm-row"
                    data-name="<?= e($en['name']) ?>"
                    data-path="<?= e($en['rel']) ?>"
                    data-isdir="<?= $en['is_dir'] ? '1' : '0' ?>"
                    data-size="<?= e($en['size_h']) ?>"
                    data-type="<?= e($en['type']) ?>"
                    data-owner="<?= e($en['owner']) ?>"
                    data-group="<?= e($en['group']) ?>"
                    data-perms="<?= e($en['perms']) ?>"
                    data-modified="<?= e(date('M j, Y H:i', $en['mtime'])) ?>"
                    data-icon="<?= e($en['icon']) ?>"
                    data-color="<?= e($en['color']) ?>"
                    data-edit="<?= e($en['edit']) ?>"
                    data-download="<?= e($en['download']) ?>">
                  <td><input type="checkbox" class="row-check"></td>
                  <td>
                    <div class="fm-name-cell">
                      <span class="f-ic-wrap"><i data-lucide="<?= e($en['icon']) ?>" style="color:<?= e($en['color']) ?>"></i></span>
                      <a class="fname" href="<?= e($en['href']) ?>"><?= e($en['name']) ?></a>
                    </div>
                  </td>
                  <td class="mono text-tertiary"><?= e($en['size_h']) ?></td>
                  <td class="text-tertiary"><?= e($en['type']) ?></td>
                  <td class="mono text-tertiary"><?= e(date('M j, H:i', $en['mtime'])) ?></td>
                  <td><button class="btn btn-ghost btn-sm mono" data-fm-chmod="<?= e($en['rel']) ?>" data-perms="<?= e($en['perms']) ?>" title="Change permissions"><?= e($en['perms']) ?></button></td>
                  <td class="text-tertiary"><?= e($en['owner']) ?></td>
                  <td style="text-align:right;white-space:nowrap">
                    <div class="fm-row-actions">
                      <?php if (!$en['is_dir']): ?>
                        <a class="icon-btn" href="<?= e($en['edit']) ?>" title="Edit"><i data-lucide="pencil-line"></i></a>
                        <a class="icon-btn" href="<?= e($en['download']) ?>" title="Download"><i data-lucide="download"></i></a>
                      <?php endif; ?>
                      <button class="icon-btn" data-fm-details title="Details"><i data-lucide="info"></i></button>
                      <button class="icon-btn" data-fm-rename="<?= e($en['rel']) ?>" data-name="<?= e($en['name']) ?>" title="Rename"><i data-lucide="pencil"></i></button>
                      <button class="icon-btn" data-fm-delete="<?= e($en['rel']) ?>" title="Delete" style="color:var(--red-400)"><i data-lucide="trash-2"></i></button>
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
            <div class="fm-empty-hint" style="grid-column:1/-1">Empty directory</div>
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
                 data-modified="<?= e(date('M j, Y H:i', $en['mtime'])) ?>"
                 data-icon="<?= e($en['icon']) ?>"
                 data-color="<?= e($en['color']) ?>"
                 data-edit="<?= e($en['edit']) ?>"
                 data-download="<?= e($en['download']) ?>">
              <input type="checkbox" class="row-check">
              <button class="icon-btn fm-card-details" data-fm-details title="Details"><i data-lucide="info"></i></button>
              <span class="f-ic-wrap"><i data-lucide="<?= e($en['icon']) ?>" style="color:<?= e($en['color']) ?>"></i></span>
              <a class="fname" href="<?= e($en['href']) ?>"><?= e($en['name']) ?></a>
              <span class="fm-card-meta"><?= $en['is_dir'] ? 'Folder' : e($en['size_h']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        </div>

        <div class="fm-tab-panel hidden" data-fm-panel="pinned">
          <div class="fm-collection-head"><div><strong>Pinned folders</strong><span>Quick access locations</span></div></div>
          <div class="fm-collection-grid">
            <?php if (!$pinnedEntries): ?><div class="fm-empty-hint">Pin folders from Browse to keep them here.</div><?php endif; ?>
            <?php foreach ($pinnedEntries as $pin): ?>
              <a class="fm-collection-card" href="<?= e(url('files', ['path' => $pin['rel']])) ?>"><i data-lucide="folder-heart"></i><div><strong><?= e($pin['name']) ?></strong><span class="mono"><?= e($pin['rel'] ?: '/') ?></span></div><i data-lucide="chevron-right"></i></a>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="fm-tab-panel hidden" data-fm-panel="recent">
          <div class="fm-collection-head"><div><strong>Recent files</strong><span>Files opened, edited, or downloaded most recently</span></div><button class="btn btn-secondary btn-sm" id="fmClearRecent"><i data-lucide="trash-2"></i>Clear</button></div>
          <div class="fm-collection-list">
            <?php if (!$recentEntries): ?><div class="fm-empty-hint">No recently accessed files yet.</div><?php endif; ?>
            <?php foreach ($recentEntries as $recent): ?>
              <a class="fm-recent-row" href="<?= e(url('file-edit', ['path' => $recent['rel']])) ?>"><i data-lucide="file-clock"></i><div><strong><?= e($recent['name']) ?></strong><span class="mono"><?= e($recent['rel']) ?></span></div><span><?= e(date('M j, H:i', $recent['mtime'])) ?></span><i data-lucide="chevron-right"></i></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="split-divider"></div>

      <!-- RIGHT: properties -->
      <div class="split-pane fm-right-pane" id="fmProps">
        <button class="icon-btn fm-props-close" id="fmPropsClose" title="Close details"><i data-lucide="x"></i></button>
        <div id="fmPropsEmpty" class="fm-empty-hint">Select an item to see its details.</div>
        <div id="fmPropsBody" class="hidden">
          <div class="fm-thumb" id="fmPropThumb"><i data-lucide="file"></i></div>
          <div id="fmPropName" style="font-weight:700;font-size:14px;margin-bottom:2px;word-break:break-all"></div>
          <div id="fmPropPath" style="font-size:11.5px;color:var(--text-tertiary);margin-bottom:12px;word-break:break-all"></div>

          <div class="fm-prop-row"><span class="k">Size</span><span class="v" id="fmPropSize"></span></div>
          <div class="fm-prop-row"><span class="k">Type</span><span class="v" id="fmPropType"></span></div>
          <div class="fm-prop-row"><span class="k">Owner</span><span class="v" id="fmPropOwner"></span></div>
          <div class="fm-prop-row"><span class="k">Group</span><span class="v" id="fmPropGroup"></span></div>
          <div class="fm-prop-row"><span class="k">Permissions</span><span class="v mono" id="fmPropPerms"></span></div>
          <div class="fm-prop-row"><span class="k">Modified</span><span class="v" id="fmPropModified"></span></div>

          <div class="fm-section-title">Permissions <span class="mono" id="fmModeLabel"></span></div>
          <div class="chmod-grid" id="fmChmodGrid">
            <div></div><div class="hdr">Read</div><div class="hdr">Write</div><div class="hdr">Exec</div>
            <?php foreach (['Owner', 'Group', 'Other'] as $who): ?><div class="lbl"><?= $who ?></div><?php for ($bit = 0; $bit < 3; $bit++): ?><div><input type="checkbox" data-perm-bit></div><?php endfor; ?><?php endforeach; ?>
          </div>
          <button class="btn btn-secondary btn-sm" id="fmSavePerms" style="width:100%;justify-content:center;margin-top:8px"><i data-lucide="shield-check"></i>Apply permissions</button>

          <div class="fm-section-title">Ownership</div>
          <label class="field-label" for="fmOwnerSelect">Owner</label>
          <select class="fm-select-mini" id="fmOwnerSelect" style="margin-bottom:8px"><?php foreach ($ownerOptions as $account): ?><option value="<?= e($account) ?>"><?= e($account) ?></option><?php endforeach; ?></select>
          <label class="field-label" for="fmGroupSelect">Group</label>
          <select class="fm-select-mini" id="fmGroupSelect"><?php foreach ($groupOptions as $account): ?><option value="<?= e($account) ?>"><?= e($account) ?></option><?php endforeach; ?></select>
          <button class="btn btn-secondary btn-sm" id="fmSaveOwner" style="width:100%;justify-content:center;margin-top:8px"><i data-lucide="user-cog"></i>Change ownership</button>

          <div style="display:flex;flex-direction:column;gap:8px;margin-top:16px">
            <a class="btn btn-secondary" id="fmPropOpen" style="width:100%;justify-content:center"><i data-lucide="pencil-line"></i><span id="fmPropOpenLabel">Open</span></a>
            <a class="btn btn-secondary" id="fmPropDownload" style="width:100%;justify-content:center"><i data-lucide="download"></i>Download</a>
            <button class="btn btn-secondary" id="fmPropDelete" style="width:100%;justify-content:center;color:var(--red-400)"><i data-lucide="trash-2"></i>Delete</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Status bar -->
    <div class="fm-statusbar">
      <div style="display:flex;align-items:center;gap:16px">
        <span><?= (int) $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?> · <?= (int) $dirCount ?> folder<?= $dirCount === 1 ? '' : 's' ?> · <?= (int) $fileCount ?> file<?= $fileCount === 1 ? '' : 's' ?> · <strong style="color:var(--text-primary)" id="fmSelCount">0 selected</strong></span>
      </div>
      <div style="display:flex;align-items:center;gap:14px">
        <span>Total: <strong style="color:var(--text-primary)"><?= e(human_bytes($totalSize)) ?></strong></span>
      </div>
    </div>

    <!-- Right-click context menu (reuses each row's own action buttons) -->
    <div class="ctx-menu hidden" id="ctxMenu">
      <div class="ctx-item" data-ctx-act="new-file"><i data-lucide="file-plus"></i>New File</div>
      <div class="ctx-item" data-ctx-act="new-folder"><i data-lucide="folder-plus"></i>New Folder</div>
      <div class="ctx-sep"></div>
      <div class="ctx-item" data-ctx-act="open"><i data-lucide="pencil-line"></i>Open / edit</div>
      <div class="ctx-item" data-ctx-act="copy"><i data-lucide="copy"></i>Copy</div>
      <div class="ctx-item" data-ctx-act="cut"><i data-lucide="scissors"></i>Cut</div>
      <div class="ctx-item" data-ctx-act="paste"><i data-lucide="clipboard-paste"></i>Paste</div>
      <div class="ctx-item" data-ctx-act="download"><i data-lucide="download"></i>Download</div>
      <div class="ctx-item" data-ctx-act="compress"><i data-lucide="archive"></i>Compress</div>
      <div class="ctx-sep"></div>
      <div class="ctx-item" data-ctx-act="rename"><i data-lucide="pencil"></i>Rename</div>
      <div class="ctx-item" data-ctx-act="chmod"><i data-lucide="lock"></i>Permissions</div>
      <div class="ctx-item" data-ctx-act="details"><i data-lucide="info"></i>Details</div>
      <div class="ctx-sep"></div>
      <div class="ctx-item danger" data-ctx-act="delete"><i data-lucide="trash-2"></i>Delete</div>
    </div>
  </div>
<?php endif; ?>

<script>
const CURDIR = <?= json_encode($rel) ?>;
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  const reload = () => setTimeout(() => location.reload(), 400);
  const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

  // ---- Create / rename / chmod / delete (unchanged endpoints) --------------
  const bindClick = (id, fn) => document.getElementById(id)?.addEventListener('click', fn);

  // ---- Browse / pinned / recent tabs -------------------------------------
  document.querySelectorAll('[data-fm-tab]').forEach((tab) => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('[data-fm-tab]').forEach((item) => item.classList.toggle('active', item === tab));
      document.querySelectorAll('[data-fm-panel]').forEach((panel) => panel.classList.toggle('hidden', panel.dataset.fmPanel !== tab.dataset.fmTab));
    });
  });
  bindClick('fmPinCurrent', async () => {
    const res = await apiPost('file-state', { action: 'toggle-pin', path: CURDIR });
    if (res.ok) { toast(res.pinned ? 'Folder pinned' : 'Folder unpinned', 'success'); reload(); }
    else toast(res.error || 'Could not update pin', 'error');
  });
  bindClick('fmClearRecent', async () => {
    const res = await apiPost('file-state', { action: 'clear-recent' });
    if (res.ok) { toast('Recent files cleared', 'success'); reload(); }
    else toast(res.error || 'Could not clear recent files', 'error');
  });

  bindClick('fmNewDir', async () => {
    const name = prompt('New folder name:');
    if (!name) return;
    const res = await apiPost('file-mkdir', { dir: CURDIR, name });
    if (res.ok) { toast('Folder created', 'success'); reload(); }
    else toast(res.error || 'Failed', 'error');
  });

  bindClick('fmNewFile', async () => {
    const name = prompt('New file name:');
    if (!name) return;
    const res = await apiPost('file-mkfile', { dir: CURDIR, name });
    if (res.ok) { toast('File created', 'success'); reload(); }
    else toast(res.error || 'Failed', 'error');
  });

  document.querySelectorAll('[data-fm-rename]').forEach((btn) => {
    btn.addEventListener('click', async (ev) => {
      ev.stopPropagation();
      const path = btn.dataset.fmRename;
      const name = prompt('Rename to:', btn.dataset.name || '');
      if (!name || name === btn.dataset.name) return;
      const res = await apiPost('file-rename', { path, name });
      if (res.ok) { toast('Renamed', 'success'); reload(); }
      else toast(res.error || 'Failed', 'error');
    });
  });

  document.querySelectorAll('[data-fm-chmod]').forEach((btn) => {
    btn.addEventListener('click', async (ev) => {
      ev.stopPropagation();
      const path = btn.dataset.fmChmod;
      const mode = prompt('New permissions (octal, e.g. 0644):', btn.dataset.perms || '');
      if (!mode) return;
      const res = await apiPost('file-chmod', { path, mode });
      if (res.ok) { toast('Permissions changed', 'success'); reload(); }
      else toast(res.error || 'Failed', 'error');
    });
  });

  document.querySelectorAll('[data-fm-delete]').forEach((btn) => {
    btn.addEventListener('click', async (ev) => {
      ev.stopPropagation();
      const path = btn.dataset.fmDelete;
      if (!confirm(`Delete "${path}"? This cannot be undone.`)) return;
      const res = await apiPost('file-delete', { path });
      if (res.ok) { toast('Deleted', 'success'); reload(); }
      else toast(res.error || 'Delete failed', 'error');
    });
  });

  // ---- Upload (button + hidden input; supports multiple) -------------------
  const fileInput = document.getElementById('fmUpload');
  bindClick('fmUploadBtn', () => fileInput?.click());
  bindClick('fmUploadBtn2', () => fileInput?.click());

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
      const res = await r.json();
      if (res.ok) { toast(`${res.overwritten ? 'Replaced' : 'Uploaded'} ${file.name}`, 'success'); return true; }
      if (res.conflict) {
        if (confirm(`"${file.name}" already exists. Replace it with the uploaded file?`)) {
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
      if (e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) {
        e.preventDefault();
        center.classList.add('fm-dragover');
      }
    }));
    ['dragleave', 'dragend'].forEach((ev) => center.addEventListener(ev, (e) => {
      if (e.target === center) center.classList.remove('fm-dragover');
    }));
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
    toggle?.querySelectorAll('button').forEach((b) => b.classList.toggle('active', b.dataset.view === v));
    try { localStorage.setItem('nebula.fm.view', v); } catch (e) {}
  }
  toggle?.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => setView(b.dataset.view)));
  let savedView = 'list';
  try { savedView = localStorage.getItem('nebula.fm.view') || 'list'; } catch (e) {}
  setView(savedView);

  // ---- Folder search filter ------------------------------------------------
  const search = document.getElementById('fmSearch');
  search?.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    document.querySelectorAll('.fm-row').forEach((row) => {
      const match = !q || (row.dataset.name || '').toLowerCase().includes(q);
      row.classList.toggle('hidden', !match);
    });
  });

  // ---- Properties pane -----------------------------------------------------
  const propsEmpty = document.getElementById('fmPropsEmpty');
  const propsBody = document.getElementById('fmPropsBody');
  const propsPane = document.getElementById('fmProps');
  let propsRow = null;
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
    for (let row = 0; row < 3; row++) {
      let digit = 0;
      for (let col = 0; col < 3; col++) if (boxes[row * 3 + col]?.checked) digit += permissionBits[row * 3 + col];
      mode += String(digit);
    }
    return '0' + mode;
  }
  function showProps(row) {
    if (!propsBody) return;
    propsRow = row;
    const d = row.dataset;
    const isDir = d.isdir === '1';
    document.getElementById('fmPropName').textContent = d.name;
    document.getElementById('fmPropPath').textContent = d.path || '/';
    document.getElementById('fmPropSize').textContent = isDir ? '—' : d.size;
    document.getElementById('fmPropType').textContent = d.type;
    document.getElementById('fmPropOwner').textContent = d.owner;
    document.getElementById('fmPropGroup').textContent = d.group || 'â€”';
    document.getElementById('fmPropPerms').textContent = d.perms;
    document.getElementById('fmPropModified').textContent = d.modified;
    fillPermissionGrid(d.perms);
    const ownerSelect = document.getElementById('fmOwnerSelect');
    const groupSelect = document.getElementById('fmGroupSelect');
    if (ownerSelect && Array.from(ownerSelect.options).some((o) => o.value === d.owner)) ownerSelect.value = d.owner;
    if (groupSelect && Array.from(groupSelect.options).some((o) => o.value === d.group)) groupSelect.value = d.group;
    const thumb = document.getElementById('fmPropThumb');
    if (thumb) {
      const ico = document.createElement('i');
      ico.setAttribute('data-lucide', d.icon || 'file');
      ico.style.color = d.color || 'var(--text-tertiary)';
      thumb.innerHTML = '';
      thumb.appendChild(ico);
    }

    const open = document.getElementById('fmPropOpen');
    const dl = document.getElementById('fmPropDownload');
    const del = document.getElementById('fmPropDelete');
    const nameLink = row.querySelector('a.fname');
    open.href = nameLink ? nameLink.getAttribute('href') : '#';
    document.getElementById('fmPropOpenLabel').textContent = isDir ? 'Open folder' : 'Open in editor';
    open.style.display = '';
    if (isDir) {
      dl.style.display = 'none';
    } else {
      dl.style.display = '';
      dl.href = d.download || '#';
    }
    del.onclick = async () => {
      if (!confirm(`Delete "${d.path}"? This cannot be undone.`)) return;
      const res = await apiPost('file-delete', { path: d.path });
      if (res.ok) { toast('Deleted', 'success'); reload(); }
      else toast(res.error || 'Delete failed', 'error');
    };

    propsEmpty?.classList.add('hidden');
    propsBody.classList.remove('hidden');
    propsPane?.classList.add('open');
    if (window.lucide) window.lucide.createIcons();
  }

  bindClick('fmPropsClose', () => propsPane?.classList.remove('open'));
  bindClick('fmSavePerms', async () => {
    if (!propsRow) return;
    const mode = permissionMode();
    const res = await apiPost('file-chmod', { path: propsRow.dataset.path, mode });
    if (res.ok) { toast('Permissions changed', 'success'); reload(); }
    else toast(res.error || 'Permissions change failed', 'error');
  });
  bindClick('fmSaveOwner', async () => {
    if (!propsRow) return;
    const owner = document.getElementById('fmOwnerSelect').value;
    const group = document.getElementById('fmGroupSelect').value;
    const res = await apiPost('file-owner', { path: propsRow.dataset.path, owner, group });
    if (res.ok) { toast('Ownership changed', 'success'); reload(); }
    else toast(res.error || 'Ownership change failed', 'error');
  });

  function markSelected(row) {
    document.querySelectorAll('.fm-row').forEach((r) => r.classList.remove('fm-row-selected'));
    row.classList.add('fm-row-selected');
  }

  document.querySelectorAll('.fm-row').forEach((row) => {
    row.addEventListener('click', (e) => {
      // Filename links navigate. Clicking row whitespace participates in
      // multi-select; details open only from an explicit Details action.
      if (e.target.closest('a, button, input')) return;
      const cb = row.querySelector('.row-check');
      if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); }
    });
  });
  document.querySelectorAll('[data-fm-details]').forEach((button) => {
    button.addEventListener('click', (event) => { event.stopPropagation(); showProps(button.closest('.fm-row')); });
  });

  // ---- Multi-select --------------------------------------------------------
  function activeContainer() {
    return (gridView && !gridView.classList.contains('hidden')) ? gridView : listView;
  }
  function selectedPaths(fallbackRow = null) {
    const checked = Array.from(activeContainer()?.querySelectorAll('.row-check:checked') || []);
    const paths = checked.map((cb) => cb.closest('.fm-row')?.dataset.path).filter(Boolean);
    if (!paths.length && fallbackRow?.dataset.path) paths.push(fallbackRow.dataset.path);
    return Array.from(new Set(paths));
  }
  function setClipboard(op, fallbackRow = null) {
    const paths = selectedPaths(fallbackRow);
    if (!paths.length) { toast('Select at least one item', 'error'); return; }
    localStorage.setItem('nebula.fm.clipboard', JSON.stringify({ op, paths }));
    toast(`${paths.length} item(s) ${op === 'move' ? 'cut' : 'copied'}`, 'success');
  }
  async function pasteClipboard() {
    let clip = null;
    try { clip = JSON.parse(localStorage.getItem('nebula.fm.clipboard') || 'null'); } catch (e) {}
    if (!clip?.paths?.length || !['copy', 'move'].includes(clip.op)) { toast('Clipboard is empty', 'error'); return; }
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
    if (!paths.length) { toast('Select at least one item', 'error'); return; }
    const suggested = paths.length === 1 ? (paths[0].split('/').pop() || 'archive') : 'archive';
    const name = prompt('Archive name (.zip or .tar.gz):', suggested.replace(/\.(tar\.gz|[^.]+)$/i, '') + '.zip');
    if (!name) return;
    const res = await apiPost('file-compress', { paths, dest: CURDIR, name });
    if (res.ok) { toast(`Created ${name}`, 'success'); reload(); }
    else toast(res.error || 'Compression failed', 'error');
  }
  bindClick('fmCopySelected', () => setClipboard('copy'));
  bindClick('fmCutSelected', () => setClipboard('move'));
  bindClick('fmPaste', pasteClipboard);
  bindClick('fmCompressSelected', () => compressPaths(selectedPaths()));
  function updateSelCount() {
    const n = activeContainer()?.querySelectorAll('.row-check:checked').length || 0;
    const el = document.getElementById('fmSelCount');
    if (el) el.textContent = `${n} selected`;
  }
  document.querySelectorAll('.row-check').forEach((cb) => {
    cb.addEventListener('click', (e) => e.stopPropagation());
    cb.addEventListener('change', () => {
      const row = cb.closest('.fm-row');
      if (row) row.classList.toggle('fm-row-selected', cb.checked);
      updateSelCount();
    });
  });
  document.getElementById('fmSelectAll')?.addEventListener('change', (e) => {
    listView?.querySelectorAll('.row-check').forEach((cb) => {
      cb.checked = e.target.checked;
      cb.closest('.fm-row')?.classList.toggle('fm-row-selected', cb.checked);
    });
    updateSelCount();
  });

  bindClick('fmDeleteSelected', async () => {
    const checked = Array.from(activeContainer()?.querySelectorAll('.row-check:checked') || []);
    const paths = checked.map((cb) => cb.closest('.fm-row')?.dataset.path).filter(Boolean);
    if (!paths.length) { toast('Nothing selected', 'error'); return; }
    if (!confirm(`Delete ${paths.length} item(s)? This cannot be undone.`)) return;
    let ok = 0;
    for (const path of paths) {
      const res = await apiPost('file-delete', { path });
      if (res.ok) ok++; else toast(res.error || `Failed: ${path}`, 'error');
    }
    if (ok) { toast(`Deleted ${ok} item(s)`, 'success'); reload(); }
  });

  // ---- Right-click anywhere: context actions + slide-out details ----------
  const ctx = document.getElementById('ctxMenu');
  if (ctx) {
    let ctxRow = null;
    const hideCtx = () => ctx.classList.add('hidden');

    const openContext = (e, row = null) => {
        e.preventDefault();
        e.stopPropagation();
        ctxRow = row;
        if (row) { markSelected(row); }
        const isFile = row && row.dataset.isdir !== '1';
        ['open', 'rename', 'chmod', 'delete', 'details', 'copy', 'cut', 'compress'].forEach((act) => {
          const el = ctx.querySelector(`[data-ctx-act="${act}"]`);
          if (el) el.style.display = row ? '' : 'none';
        });
        ctx.querySelector('[data-ctx-act="download"]').style.display = isFile ? '' : 'none';
        ctx.classList.remove('hidden');
        const mw = ctx.offsetWidth || 200, mh = ctx.offsetHeight || 260;
        let x = e.clientX, y = e.clientY;
        if (x + mw > window.innerWidth) x = window.innerWidth - mw - 8;
        if (y + mh > window.innerHeight) y = window.innerHeight - mh - 8;
        ctx.style.left = x + 'px';
        ctx.style.top = y + 'px';
    };
    document.querySelectorAll('.fm-row').forEach((row) => {
      row.addEventListener('contextmenu', (e) => openContext(e, row));
    });
    center?.addEventListener('contextmenu', (e) => { if (!e.target.closest('.fm-row')) openContext(e, null); });

    ctx.querySelectorAll('[data-ctx-act]').forEach((item) => {
      item.addEventListener('click', () => {
        const act = item.dataset.ctxAct;
        hideCtx();
        if (act === 'new-file') return document.getElementById('fmNewFile')?.click();
        if (act === 'new-folder') return document.getElementById('fmNewDir')?.click();
        if (act === 'paste') return pasteClipboard();
        if (!ctxRow) return;
        if (act === 'copy') return setClipboard('copy', ctxRow);
        if (act === 'cut') return setClipboard('move', ctxRow);
        if (act === 'compress') return compressPaths(selectedPaths(ctxRow));
        if (act === 'details' || act === 'chmod') return showProps(ctxRow);
        if (act === 'open') return ctxRow.querySelector('a.fname')?.click();
        if (act === 'download' && ctxRow.dataset.download) { location.href = ctxRow.dataset.download; return; }
        if (act === 'rename') {
          const name = prompt('Rename to:', ctxRow.dataset.name || '');
          if (name && name !== ctxRow.dataset.name) apiPost('file-rename', { path: ctxRow.dataset.path, name }).then((res) => res.ok ? reload() : toast(res.error || 'Rename failed', 'error'));
          return;
        }
        if (act === 'delete' && confirm(`Delete "${ctxRow.dataset.path}"? This cannot be undone.`)) {
          apiPost('file-delete', { path: ctxRow.dataset.path }).then((res) => res.ok ? reload() : toast(res.error || 'Delete failed', 'error'));
        }
      });
    });

    document.addEventListener('click', hideCtx);
    document.addEventListener('scroll', hideCtx, true);
    window.addEventListener('resize', hideCtx);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideCtx(); });
  }
});
</script>
