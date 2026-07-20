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
        return ['ok' => false, 'error' => 'chmod failed (permissions?).'];
    }
    audit('file.chmod', fm_rel($abs) . ' ' . $mode);
    return ['ok' => true];
}

/** Save text content to the file at $rel. */
function fm_save(string $rel, string $content): array
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
    if (@file_put_contents($abs, $content) === false) {
        return ['ok' => false, 'error' => 'Save failed (permissions?).'];
    }
    audit('file.save', fm_rel($abs));
    return ['ok' => true];
}

/** Recursively copy a file or directory tree. */
function fm_copy_recursive(string $src, string $dst): bool
{
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
function fm_upload(string $relDir, array $file): array
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
        return ['ok' => false, 'error' => 'A file with that name already exists.'];
    }
    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'error' => 'Could not save uploaded file (permissions?).'];
    }
    audit('file.upload', fm_rel($target));
    return ['ok' => true];
}
