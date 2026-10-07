<?php
/** POST api/file-delete {path} — delete a file or directory within FM_ROOT.
 * The privileged helper moves the entry into root-owned recovery trash, still
 * confined to the FM root. Without the helper (development installs) the web
 * user removes what it can, recursively. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

$body = read_json_body();
// Resolve the entry itself: deleting a symlink removes the link, never the
// file or folder it points to. The File Manager root is never deletable.
$abs = fm_resolve_entry((string) ($body['path'] ?? ''));
if ($abs === null) {
    json_out(['ok' => false, 'error' => 'Path not found or not allowed.'], 400);
}

/** Best-effort recursive delete as the web user. */
$deleteTree = static function (string $path) use (&$deleteTree): bool {
    if (is_link($path)) {
        return @unlink($path);
    }
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            if (!$deleteTree($path . '/' . $entry)) { return false; }
        }
        return @rmdir($path);
    }
    return @unlink($path);
};

// The UI promises a recoverable delete, so prefer the helper, which moves the
// entry into root-owned recovery trash in one rename. Deleting as the web
// user first could also remove half of a mixed-ownership tree permanently
// before failing on a root-owned file.
$exists = static fn(string $path): bool => file_exists($path) || is_link($path);
$ok = false;
$reason = '';
if (helper_available()) {
    [$code, $out] = helper_cmd('file-delete ' . escapeshellarg($abs), 60);
    clearstatcache();
    $ok = $code === 0 && !$exists($abs);
    $reason = $ok ? '' : trim($out);
} elseif ($exists($abs)) {
    $ok = $deleteTree($abs);
}

audit('file.delete', fm_rel($abs) . ($ok ? '' : ' FAILED'));
json_out(
    $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Delete failed: ' . ($reason !== '' ? $reason : 'permission denied, or the item is in use.')],
    $ok ? 200 : 400
);
