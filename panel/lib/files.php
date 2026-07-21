<?php
/**
 * File Manager backend — every path is confined below FM_ROOT.
 * The confinement check uses realpath() so symlinks and ../ can't escape.
 */

function fm_root(): string
{
    global $config;
    $root = realpath($config['fm_root'] ?? '/var/www');
    return $root ?: '';
}

function fm_forbidden_roots(): array
{
    global $config;
    $roots = array_merge([APP_ROOT, DATA_DIR], (array) ($config['fm_denied_paths'] ?? []));
    $resolved = [];
    foreach ($roots as $root) {
        $real = realpath((string) $root);
        if ($real !== false) { $resolved[] = rtrim($real, DIRECTORY_SEPARATOR); }
    }
    return array_values(array_unique($resolved));
}

function fm_path_forbidden(string $absolute): bool
{
    $absolute = rtrim($absolute, DIRECTORY_SEPARATOR);
    foreach (fm_forbidden_roots() as $blocked) {
        if ($absolute === $blocked || str_starts_with($absolute, $blocked . DIRECTORY_SEPARATOR)) { return true; }
    }
    return false;
}

function fm_absolute_allowed(string $absolute): bool
{
    $root = fm_root();
    $real = realpath($absolute);
    return $root !== '' && $real !== false
        && ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR))
        && !fm_path_forbidden($real);
}

/**
 * Resolve a user-supplied relative path to an absolute path that is
 * guaranteed to live inside FM_ROOT. Returns null if it escapes or the
 * root is unavailable. $mustExist=false allows resolving a not-yet-created
 * target (e.g. for delete of a broken entry) by checking the parent.
 */
function fm_resolve(string $rel, bool $mustExist = true): ?string
{
    $root = fm_root();
    if ($root === '') {
        return null;
    }
    $rel = str_replace('\\', '/', $rel);
    $rel = ltrim($rel, '/');
    $candidate = $root . '/' . $rel;

    $real = realpath($candidate);
    if ($real === false) {
        if ($mustExist) {
            return null;
        }
        // Validate the parent directory instead.
        $parent = realpath(dirname($candidate));
        if ($parent === false) {
            return null;
        }
        $real = $parent . '/' . basename($candidate);
        $checkAgainst = $parent;
    } else {
        $checkAgainst = $real;
    }

    // Confinement: resolved path must be the root or sit beneath it.
    if ($checkAgainst !== $root && strpos($checkAgainst, $root . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    if (fm_path_forbidden($checkAgainst)) { return null; }
    return $real;
}

/** Path relative to FM_ROOT, for display / links (uses '/' at root). */
function fm_rel(string $abs): string
{
    $root = fm_root();
    $rel = str_replace('\\', '/', substr($abs, strlen($root)));
    $rel = ltrim($rel, '/');
    return $rel === '' ? '' : $rel;
}

/** Return a safe File Manager-relative link target for an absolute path. */
function fm_link_path(string $abs): ?string
{
    $root = fm_root();
    $real = realpath($abs);
    if ($root === '' || $real === false) {
        return null;
    }
    if ($real !== $root && strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return fm_rel($real);
}

/** List a directory. Returns ['dirs' => [...], 'files' => [...]] sorted. */
function fm_list(string $absDir): array
{
    $dirs = [];
    $files = [];
    $handle = @opendir($absDir);
    if (!$handle) {
        return ['dirs' => [], 'files' => []];
    }
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $absDir . '/' . $entry;
        $entryReal = realpath($full);
        if ($entryReal !== false && fm_path_forbidden($entryReal)) { continue; }
        $isDir = is_dir($full);
        $item = [
            'name'     => $entry,
            'rel'      => fm_rel($full),
            'is_dir'   => $isDir,
            'size'     => $isDir ? null : (@filesize($full) ?: 0),
            'mtime'    => @filemtime($full) ?: 0,
            'perms'    => substr(sprintf('%o', @fileperms($full) ?: 0), -4),
            'readable' => is_readable($full),
            'owner'    => fm_owner($full),
            'group'    => fm_group($full),
            'ext'      => $isDir ? '' : strtolower(pathinfo($full, PATHINFO_EXTENSION)),
        ];
        if ($isDir) {
            $dirs[] = $item;
        } else {
            $files[] = $item;
        }
    }
    closedir($handle);
    $byName = fn($a, $b) => strcasecmp($a['name'], $b['name']);
    usort($dirs, $byName);
    usort($files, $byName);
    return ['dirs' => $dirs, 'files' => $files];
}

/** Breadcrumb segments for a relative path. */
function fm_breadcrumbs(string $rel): array
{
    $crumbs = [['name' => 'root', 'rel' => '']];
    $acc = '';
    foreach (array_filter(explode('/', $rel)) as $seg) {
        $acc = $acc === '' ? $seg : $acc . '/' . $seg;
        $crumbs[] = ['name' => $seg, 'rel' => $acc];
    }
    return $crumbs;
}

/** Is this file safe to show inline as text? */
function fm_is_text(string $abs, int $maxBytes = 512000): bool
{
    $size = @filesize($abs);
    if ($size === false || $size > $maxBytes) {
        return false;
    }
    $sample = @file_get_contents($abs, false, null, 0, 4096);
    if ($sample === false) {
        return false;
    }
    // Treat as binary if it contains a null byte.
    return strpos($sample, "\0") === false;
}

/** Owner name for an absolute path. Falls back to numeric uid, '?' on failure. */
function fm_owner(string $abs): string
{
    $uid = @fileowner($abs);
    if ($uid === false) {
        return '?';
    }
    if (function_exists('posix_getpwuid')) {
        $info = @posix_getpwuid($uid);
        if (is_array($info) && isset($info['name'])) {
            return (string) $info['name'];
        }
    }
    return (string) $uid;
}

/** Group name for an absolute path. Falls back to numeric gid. */
function fm_group(string $abs): string
{
    $gid = @filegroup($abs);
    if ($gid === false) {
        return '?';
    }
    if (function_exists('posix_getgrgid')) {
        $info = @posix_getgrgid($gid);
        if (is_array($info) && isset($info['name'])) {
            return (string) $info['name'];
        }
    }
    return (string) $gid;
}

/** File-manager pins and history live in panel-private data/. */
function fm_state_file(): string
{
    return DATA_DIR . '/file_manager.json';
}

function fm_state(): array
{
    $data = @json_decode((string) @file_get_contents(fm_state_file()), true);
    $key = (string) ((int) ($_SESSION['uid'] ?? 0));
    $state = is_array($data['users'][$key] ?? null) ? $data['users'][$key] : (is_array($data) ? $data : []);
    return [
        'pinned' => array_values(array_unique((array) ($state['pinned'] ?? []))),
        'recent' => array_values(array_unique((array) ($state['recent'] ?? []))),
    ];
}

function fm_save_state(array $state): bool
{
    return !empty(fm_update_state(static fn(array $current): array => $state)['ok']);
}

/** Mutate one user's File Manager state without losing concurrent changes. */
function fm_update_state(callable $mutator): array
{
    $path = fm_state_file();
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX)) { if (is_resource($lock)) fclose($lock); return ['ok'=>false]; }
    $data = @json_decode((string) @file_get_contents($path), true);
    $data = is_array($data) && isset($data['users']) ? $data : ['version'=>2,'users'=>[]];
    $key = (string) ((int) ($_SESSION['uid'] ?? 0));
    $current = is_array($data['users'][$key] ?? null) ? $data['users'][$key] : ['pinned'=>[],'recent'=>[]];
    $state = $mutator([
        'pinned'=>array_values(array_unique((array)($current['pinned']??[]))),
        'recent'=>array_values(array_unique((array)($current['recent']??[]))),
    ]);
    if (!is_array($state)) { @flock($lock, LOCK_UN); fclose($lock); return ['ok'=>false]; }
    $data['users'][$key] = [
        'pinned' => array_slice(array_values(array_unique((array) ($state['pinned'] ?? []))), 0, 30),
        'recent' => array_slice(array_values(array_unique((array) ($state['recent'] ?? []))), 0, 30),
    ];
    $ok = write_json_file($path, $data);
    @flock($lock, LOCK_UN); fclose($lock);
    return ['ok'=>$ok,'state'=>$data['users'][$key]];
}

function fm_clear_recent(): bool
{
    $result=fm_update_state(static function(array $state):array{$state['recent']=[];return $state;});return !empty($result['ok']);
}

function fm_record_recent(string $rel): void
{
    $abs = fm_resolve($rel);
    if ($abs === null || !is_file($abs)) {
        return;
    }
    $rel = fm_rel($abs);
    fm_update_state(static function(array $state) use($rel):array{
        $state['recent']=array_values(array_filter($state['recent'],fn($p)=>$p!==$rel));array_unshift($state['recent'],$rel);return $state;
    });
}

function fm_toggle_pin(string $rel): array
{
    $abs = fm_resolve($rel);
    if ($abs === null || !is_dir($abs)) {
        return ['ok' => false, 'error' => 'Only existing folders can be pinned.'];
    }
    $rel = fm_rel($abs);
    $pinned=false;
    $result=fm_update_state(static function(array $state) use($rel,&$pinned):array{
        $pinned=in_array($rel,$state['pinned'],true);$state['pinned']=array_values(array_filter($state['pinned'],fn($p)=>$p!==$rel));if(!$pinned)array_unshift($state['pinned'],$rel);return $state;
    });
    $ok = !empty($result['ok']);
    if ($ok) { audit($pinned ? 'file.unpin' : 'file.pin', $rel); }
    return $ok ? ['ok' => true, 'pinned' => !$pinned] : ['ok' => false, 'error' => 'Could not save pinned folders.'];
}

/** Resolve saved paths and discard stale/out-of-scope records. */
function fm_state_entries(string $key): array
{
    $state = fm_state();
    $items = [];
    foreach ((array) ($state[$key] ?? []) as $rel) {
        $abs = fm_resolve((string) $rel);
        if ($abs === null || ($key === 'pinned' ? !is_dir($abs) : !is_file($abs))) {
            continue;
        }
        $items[] = [
            'name' => basename($abs),
            'rel' => fm_rel($abs),
            'is_dir' => is_dir($abs),
            'size' => is_file($abs) ? (@filesize($abs) ?: 0) : null,
            'mtime' => @filemtime($abs) ?: 0,
            'perms' => substr(sprintf('%o', @fileperms($abs) ?: 0), -4),
            'owner' => fm_owner($abs),
            'group' => fm_group($abs),
            'ext' => is_file($abs) ? strtolower(pathinfo($abs, PATHINFO_EXTENSION)) : '',
        ];
    }
    return $items;
}

/** Common interactive/service accounts exposed by the ownership editor. */
function fm_account_names(string $kind): array
{
    $file = $kind === 'group' ? '/etc/group' : '/etc/passwd';
    $names = ['root', 'www-data'];
    foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $parts = explode(':', $line);
        $name = (string) ($parts[0] ?? '');
        $id = (int) ($parts[2] ?? -1);
        if ($name !== '' && ($id >= 1000 || in_array($name, ['root', 'www-data', 'staff'], true))) {
            $names[] = $name;
        }
    }
    $names = array_values(array_unique($names));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

/** Change owner/group through the root-owned helper, confined to FM_ROOT. */
function fm_chown(string $rel, string $owner, string $group): array
{
    $abs = fm_resolve($rel);
    if ($abs === null || !file_exists($abs)) {
        return ['ok' => false, 'error' => 'Path not found or not allowed.'];
    }
    if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $owner)
        || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $group)) {
        return ['ok' => false, 'error' => 'Invalid owner or group.'];
    }
    [$code, $out] = helper_cmd(
        'file-chown ' . escapeshellarg($abs) . ' ' . escapeshellarg($owner) . ' ' . escapeshellarg($group)
    );
    audit('file.chown', fm_rel($abs) . " $owner:$group");
    return $code === 0 ? ['ok' => true] : ['ok' => false, 'error' => trim($out) ?: 'Ownership change failed.'];
}

/** Create a zip (default) or .tar.gz archive in a directory within FM_ROOT. */
function fm_compress(array $paths, string $destDir, string $name): array
{
    $dest = fm_resolve($destDir);
    if ($dest === null || !is_dir($dest)) {
        return ['ok' => false, 'error' => 'Destination folder is not allowed.'];
    }
    $name = trim($name);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(zip|tar\.gz)$/i', $name, $match)) {
        return ['ok' => false, 'error' => 'Archive name must end in .zip or .tar.gz and use safe characters.'];
    }
    $target = $dest . '/' . $name;
    if (file_exists($target)) {
        return ['ok' => false, 'error' => 'An archive with that name already exists.'];
    }
    $rels = [];
    foreach (array_slice(array_values(array_unique($paths)), 0, 100) as $rel) {
        $abs = fm_resolve((string) $rel);
        if ($abs === null || $abs === fm_root()) {
            return ['ok' => false, 'error' => 'One of the selected paths is not allowed.'];
        }
        $rels[] = fm_rel($abs);
    }
    if (!$rels) {
        return ['ok' => false, 'error' => 'Select at least one item to compress.'];
    }
    $format = strtolower($match[1]) === 'zip' ? 'zip' : 'tar.gz';
    $sources = [];
    foreach ($rels as $sourceRel) {
        $sourceAbs = fm_resolve($sourceRel);
        if ($sourceAbs === null) {
            return ['ok' => false, 'error' => 'One of the selected paths is no longer available.'];
        }
        $sources[] = escapeshellarg($sourceAbs);
    }
    // Hosting roots are commonly not writable by www-data. The root-owned
    // helper re-validates every path against FM_ROOT before invoking zip/tar.
    [$code, $out, $err] = helper_cmd(
        'file-compress ' . escapeshellarg($format) . ' ' . escapeshellarg($target) . ' ' . implode(' ', $sources),
        300
    );
    if ($code !== 0) {
        @unlink($target);
        return ['ok' => false, 'error' => trim($out . "\n" . $err) ?: 'Compression failed.'];
    }
    audit('file.compress', implode(', ', $rels) . ' -> ' . fm_rel($target));
    return ['ok' => true, 'path' => fm_rel($target)];
}

/** Validate a single path segment (no separators, not . or ..). */
function fm_valid_name(string $n): bool
{
    return $n !== '' && $n !== '.' && $n !== '..'
        && !preg_match('#[/\\\\\x00]#', $n)
        && strlen($n) <= 255;
}

/** Create a new directory named $name inside relative dir $relDir. */
function fm_mkdir(string $relDir, string $name): array
{
    $abs = fm_resolve($relDir);
    if ($abs === null || !is_dir($abs)) {
        return ['ok' => false, 'error' => 'Directory not found or not allowed.'];
    }
    if (!fm_valid_name($name)) {
        return ['ok' => false, 'error' => 'Invalid name.'];
    }
    $target = $abs . '/' . $name;
    if (file_exists($target)) {
        return ['ok' => false, 'error' => 'A file or folder with that name already exists.'];
    }
    if (!@mkdir($target, 0755)) {
        return ['ok' => false, 'error' => 'Could not create directory (permissions?).'];
    }
    audit('file.mkdir', fm_rel($target));
    return ['ok' => true];
}

/** Create a new empty file named $name inside relative dir $relDir. */
function fm_mkfile(string $relDir, string $name): array
{
    $abs = fm_resolve($relDir);
    if ($abs === null || !is_dir($abs)) {
        return ['ok' => false, 'error' => 'Directory not found or not allowed.'];
    }
    if (!fm_valid_name($name)) {
        return ['ok' => false, 'error' => 'Invalid name.'];
    }
    $target = $abs . '/' . $name;
    if (file_exists($target)) {
        return ['ok' => false, 'error' => 'A file or folder with that name already exists.'];
    }
    if (!@touch($target)) {
        return ['ok' => false, 'error' => 'Could not create file (permissions?).'];
    }
    audit('file.mkfile', fm_rel($target));
    return ['ok' => true];
}

/** Rename the entry at $rel to $newName (same directory). */
function fm_rename(string $rel, string $newName): array
{
    $abs = fm_resolve($rel);
    if ($abs === null || !file_exists($abs)) {
        return ['ok' => false, 'error' => 'Path not found or not allowed.'];
    }
    if (!fm_valid_name($newName)) {
        return ['ok' => false, 'error' => 'Invalid name.'];
    }
    $target = dirname($abs) . '/' . $newName;
    if (file_exists($target)) {
        return ['ok' => false, 'error' => 'A file or folder with that name already exists.'];
    }
    if (!@rename($abs, $target)) {
        return ['ok' => false, 'error' => 'Rename failed (permissions?).'];
    }
    audit('file.rename', fm_rel($abs) . ' -> ' . fm_rel($target));
    return ['ok' => true];
}

/** Change permissions of the entry at $rel to octal $mode. */
function fm_chmod(string $rel, string $mode): array
{
    $abs = fm_resolve($rel);
    if ($abs === null || !file_exists($abs)) {
        return ['ok' => false, 'error' => 'Path not found or not allowed.'];
    }
    if (!preg_match('/^[0-7]{3,4}$/', $mode)) {
        return ['ok' => false, 'error' => 'Invalid mode.'];
    }
    if (!@chmod($abs, octdec($mode))) {
        [$code, $out] = helper_cmd('file-chmod ' . escapeshellarg($abs) . ' ' . escapeshellarg($mode));
        if ($code !== 0) {
            return ['ok' => false, 'error' => trim($out) ?: 'chmod failed (permissions?).'];
        }
    }
    audit('file.chmod', fm_rel($abs) . ' ' . $mode);
    return ['ok' => true];
}

/** Save text content to the file at $rel. */
function fm_save(string $rel, string $content, string $expectedHash = ''): array
{
    $abs = fm_resolve($rel);
    if ($abs === null || !is_file($abs)) {
        return ['ok' => false, 'error' => 'File not found or not allowed.'];
    }
    if (!is_writable($abs)) {
        return ['ok' => false, 'error' => 'File is not writable.'];
    }
    if (strlen($content) > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Content too large (max 5 MB).'];
    }
    $currentHash = hash_file('sha256', $abs) ?: '';
    if ($expectedHash !== '' && !hash_equals($currentHash, $expectedHash)) {
        return ['ok'=>false,'conflict'=>true,'error'=>'This file changed on disk after you opened it. Reload or copy your draft before saving.','current_hash'=>$currentHash];
    }
    $tmp = @tempnam(dirname($abs), '.nebula-edit-');
    if ($tmp === false) {
        return ['ok' => false, 'error' => 'Save failed (permissions?).'];
    }
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'Save failed (permissions?).'];
    }
    @chmod($tmp, @fileperms($abs) & 0777);
    if (!@rename($tmp, $abs)) { @unlink($tmp); return ['ok'=>false,'error'=>'Could not replace the file atomically.']; }
    audit('file.save', fm_rel($abs));
    return ['ok' => true, 'hash' => hash_file('sha256', $abs) ?: '', 'mtime' => @filemtime($abs) ?: time()];
}

/** Recursively copy a file or directory tree. */
function fm_copy_recursive(string $src, string $dst): bool
{
    // Never follow symlinks while recursively copying: a link below FM_ROOT
    // may point outside the confined tree.
    if (is_link($src)) {
        return false;
    }
    if (is_dir($src)) {
        if (!@mkdir($dst, 0755) && !is_dir($dst)) {
            return false;
        }
        $handle = @opendir($src);
        if (!$handle) {
            return false;
        }
        $ok = true;
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!fm_copy_recursive($src . '/' . $entry, $dst . '/' . $entry)) {
                $ok = false;
            }
        }
        closedir($handle);
        return $ok;
    }
    return @copy($src, $dst);
}

/** Copy or move the entry at $rel into directory $destDir. */
function fm_op(string $rel, string $destDir, string $op): array
{
    if ($op !== 'copy' && $op !== 'move') {
        return ['ok' => false, 'error' => 'Invalid operation.'];
    }
    $abs = fm_resolve($rel);
    if ($abs === null || !file_exists($abs)) {
        return ['ok' => false, 'error' => 'Source not found or not allowed.'];
    }
    $destAbs = fm_resolve($destDir);
    if ($destAbs === null || !is_dir($destAbs)) {
        return ['ok' => false, 'error' => 'Destination not found or not allowed.'];
    }
    $target = $destAbs . '/' . basename($abs);
    if (is_dir($abs) && ($destAbs === $abs || strpos($destAbs, $abs . DIRECTORY_SEPARATOR) === 0)) {
        return ['ok' => false, 'error' => 'A folder cannot be copied or moved into itself.'];
    }
    if (file_exists($target)) {
        return ['ok' => false, 'error' => 'Target already exists in destination.'];
    }
    if ($op === 'copy') {
        $ok = is_dir($abs) ? fm_copy_recursive($abs, $target) : @copy($abs, $target);
    } else {
        $ok = @rename($abs, $target);
    }
    if (!$ok) {
        return ['ok' => false, 'error' => ucfirst($op) . ' failed (permissions?).'];
    }
    audit('file.' . $op, fm_rel($abs) . ' -> ' . fm_rel($target));
    return ['ok' => true];
}

/** Handle an uploaded file into relative directory $relDir. */
function fm_upload(string $relDir, array $file, bool $overwrite = false): array
{
    $abs = fm_resolve($relDir);
    if ($abs === null || !is_dir($abs)) {
        return ['ok' => false, 'error' => 'Directory not found or not allowed.'];
    }
    if (empty($file) || !isset($file['tmp_name'], $file['name'], $file['error'])) {
        return ['ok' => false, 'error' => 'No file uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload error (code ' . (int) $file['error'] . ').'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    $name = basename((string) $file['name']);
    if (!fm_valid_name($name)) {
        return ['ok' => false, 'error' => 'Invalid file name.'];
    }
    $target = $abs . '/' . $name;
    if (file_exists($target)) {
        if (!$overwrite) {
            return ['ok' => false, 'conflict' => true, 'name' => $name, 'error' => 'A file with that name already exists.'];
        }
        if (!is_file($target) || is_link($target)) {
            return ['ok' => false, 'error' => 'Only an existing regular file can be overwritten.'];
        }
    }
    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'error' => 'Could not save uploaded file (permissions?).'];
    }
    audit($overwrite ? 'file.upload.overwrite' : 'file.upload', fm_rel($target));
    return ['ok' => true, 'overwritten' => $overwrite];
}
