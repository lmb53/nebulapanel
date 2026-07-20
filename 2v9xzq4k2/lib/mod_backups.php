<?php
/**
 * Backups module — creates, lists, resolves and deletes .tar.gz archives
 * stored under data/backups. Archives are created with tar; root-owned
 * sources may require a passwordless sudo rule for tar (see README).
 */

/** Absolute path to the backups storage directory (created on demand). */
function backup_store(): string
{
    $dir = APP_ROOT . '/data/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/** List archives, newest first: [['file','size','mtime'], ...]. */
function backup_list(): array
{
    $rows = [];
    foreach (glob(backup_store() . '/*.tar.gz') ?: [] as $path) {
        $rows[] = [
            'file'  => basename($path),
            'size'  => @filesize($path) ?: 0,
            'mtime' => @filemtime($path) ?: 0,
        ];
    }
    usort($rows, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $rows;
}

/**
 * Resolve a user-supplied filename to an absolute path inside the store.
 * basename() strips any directory components, preventing path traversal.
 * Returns null if the file does not exist.
 */
function backup_resolve(string $file): ?string
{
    $name = basename($file);
    if ($name === '') {
        return null;
    }
    $abs = backup_store() . '/' . $name;
    return is_file($abs) ? $abs : null;
}

/** Create a .tar.gz archive of $source, labelled $label. */
function backup_create(string $source, string $label): array
{
    $real = realpath($source);
    if ($real === false) {
        return ['ok' => false, 'error' => 'Source path not found.'];
    }
    $label = trim($label);
    if ($label === '') {
        $label = basename($real);
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $label)) {
        return ['ok' => false, 'error' => 'Label may only contain letters, numbers, _ . -'];
    }
    $fname = $label . '-' . date('Ymd-His') . '.tar.gz';
    $dest = backup_store() . '/' . $fname;
    $parent = dirname($real);
    $base = basename($real);
    $cmd = 'tar -czf ' . escapeshellarg($dest) . ' -C ' . escapeshellarg($parent) . ' ' . escapeshellarg($base);
    [$c, $o, $e] = run_cmd($cmd, 600);
    if ($c !== 0) {
        // Retry with sudo for root-owned sources.
        [$c, $o, $e] = sudo_cmd($cmd, 600);
    }
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o . ' ' . $e) ?: 'tar failed (exit ' . $c . ')'];
    }
    @chmod($dest, 0600);
    audit('backup.create', $fname . ' <= ' . $real);
    return ['ok' => true, 'file' => $fname];
}

/** Delete an archive by filename. */
function backup_delete(string $file): array
{
    $abs = backup_resolve($file);
    if ($abs === null) {
        return ['ok' => false, 'error' => 'Not found.'];
    }
    $ok = @unlink($abs);
    audit('backup.delete', basename($abs) . ($ok ? '' : ' FAILED'));
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Delete failed.'];
}

/** Test gzip/tar integrity and return the number of archived entries. */
function backup_verify(string $file): array
{
    $abs = backup_resolve($file);
    if ($abs === null) {
        return ['ok' => false, 'error' => 'Not found.'];
    }
    [$code, $out, $err] = run_cmd('tar -tzf ' . escapeshellarg($abs), 120);
    if ($code !== 0) {
        [$code, $out, $err] = sudo_cmd('tar -tzf ' . escapeshellarg($abs), 120);
    }
    $entries = $out === '' ? 0 : count(preg_split('/\r?\n/', trim($out)));
    audit('backup.verify', basename($abs) . ' (exit ' . $code . ', entries ' . $entries . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => trim($out . ' ' . $err) ?: 'Archive integrity check failed.'];
    }
    return ['ok' => true, 'entries' => $entries, 'size' => @filesize($abs) ?: 0];
}
