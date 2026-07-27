<?php
/**
 * Backups module — creates, lists, resolves and deletes .tar.gz archives
 * stored under data/backups. Site selection uses an immutable ID and the
 * helper derives the root from root-owned site state.
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
    if (!preg_match('/^[A-Za-z0-9_.-]{1,100}-[0-9]{8}-[0-9]{6}\.tar\.gz$/', $name)) {
        return null;
    }
    $abs = backup_store() . '/' . $name;
    return is_file($abs) ? $abs : null;
}

/** Create a .tar.gz archive of a managed site, labelled $label. */
function backup_create(string $siteId, string $label): array
{
    require_once APP_ROOT . '/lib/mod_sites.php';
    if (!preg_match('/^[a-f0-9]{32}$/', $siteId)) return ['ok' => false, 'error' => 'Invalid site ID.'];
    $site = null;
    foreach (sites_list() as $candidate) {
        if (($candidate['id'] ?? '') === $siteId) { $site = $candidate; break; }
    }
    if (!$site) return ['ok' => false, 'error' => 'Managed site not found.'];
    $label = trim($label);
    if ($label === '') {
        $label = (string) ($site['domain'] ?? 'site');
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $label)) {
        return ['ok' => false, 'error' => 'Label may only contain letters, numbers, _ . -'];
    }
    $fname = $label . '-' . date('Ymd-His') . '.tar.gz';
    [$c, $o] = helper_cmd('site-backup ' . escapeshellarg($siteId) . ' ' . escapeshellarg($fname), 900);
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'Backup failed.'];
    }
    $meta = ['site_id'=>$siteId,'domain'=>(string)($site['domain']??''),'file'=>$fname,'created_at'=>date('c')];
    foreach (preg_split('/\r?\n/', trim($o)) as $line) {
        if (str_contains($line, '=')) { [$key,$value]=explode('=',$line,2);$meta[$key]=$value; }
    }
    if (!write_json_file(backup_store() . '/' . $fname . '.manifest.json', $meta)) {
        return ['ok'=>false,'error'=>'Archive created, but its integrity manifest could not be saved.'];
    }
    audit('backup.create', $fname . ' <= site ' . $siteId);
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
    if ($ok) @unlink($abs . '.manifest.json');
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
    $entries = $out === '' ? 0 : count(preg_split('/\r?\n/', trim($out)));
    audit('backup.verify', basename($abs) . ' (exit ' . $code . ', entries ' . $entries . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => trim($out . ' ' . $err) ?: 'Archive integrity check failed.'];
    }
    if ($entries > 100000) return ['ok'=>false,'error'=>'Archive exceeds the 100,000-entry safety limit.'];
    foreach (preg_split('/\r?\n/', trim($out)) as $entry) {
        if ($entry === '' || str_starts_with($entry, '/') || preg_match('#(^|/)\.\.(/|$)#', $entry)) {
            return ['ok'=>false,'error'=>'Archive contains an unsafe path.'];
        }
    }
    $manifest = json_decode((string) @file_get_contents($abs . '.manifest.json'), true);
    $actualHash = hash_file('sha256', $abs);
    if (!is_array($manifest) || !preg_match('/^[a-f0-9]{64}$/', (string)($manifest['sha256']??''))
        || $actualHash === false || !hash_equals((string)$manifest['sha256'], $actualHash)) {
        return ['ok'=>false,'error'=>'Backup checksum manifest is missing or does not match.'];
    }
    return ['ok' => true, 'entries' => $entries, 'size' => @filesize($abs) ?: 0, 'sha256'=>$actualHash];
}
