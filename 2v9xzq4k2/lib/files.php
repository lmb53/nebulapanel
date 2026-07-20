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
    $rel = ltrim(substr($abs, strlen($root)), '/');
    return $rel === '' ? '' : $rel;
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
