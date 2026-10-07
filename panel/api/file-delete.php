<?php
/** POST api/file-delete {path, permanent?} — delete a file or directory within FM_ROOT.
 * By default the entry moves to the File Manager trash, where it can be restored
 * from the Trash tab. If the web user cannot move it (root-owned files), the
 * privileged helper moves it into root-owned recovery trash instead.
 * With permanent: true the entry is removed: through the helper when available
 * (one rename into recovery trash, so a mixed-ownership tree is never left half
 * deleted), otherwise by the web user, recursively. */
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
if ($abs === fm_root()) {
    json_out(['ok' => false, 'error' => 'The File Manager root folder cannot be deleted.'], 400);
}

$exists = static fn(string $path): bool => file_exists($path) || is_link($path);

if (empty($body['permanent'])) {
    $res = fm_trash_move($abs);
    if (!empty($res['ok'])) {
        json_out($res);
    }
    // Root-owned entries cannot be moved by the web user; the helper's
    // recovery trash still keeps them recoverable by an administrator.
    if (helper_available()) {
        [$code, $out] = helper_cmd('file-delete ' . escapeshellarg($abs), 60);
        clearstatcache();
        if ($code === 0 && !$exists($abs)) {
            audit('file.delete', fm_rel($abs) . ' (recovery trash)');
            json_out(['ok' => true, 'trashed' => 'recovery',
                'notice' => 'Moved to the administrator recovery trash (restore requires server access).']);
        }
        $res['error'] = 'Delete failed: ' . (trim((string) $out) ?: 'permission denied, or the item is in use.');
        unset($res['code']);
    }
    json_out($res, 409);
}

$ok = false;
$reason = '';
if (helper_available()) {
    [$code, $out] = helper_cmd('file-delete ' . escapeshellarg($abs), 60);
    clearstatcache();
    $ok = $code === 0 && !$exists($abs);
    $reason = $ok ? '' : trim($out);
} elseif ($exists($abs)) {
    $ok = fm_remove_tree($abs);
}

audit('file.delete', fm_rel($abs) . ($ok ? '' : ' FAILED'));
json_out(
    $ok ? ['ok' => true, 'trashed' => false] : ['ok' => false, 'error' => 'Delete failed: ' . ($reason !== '' ? $reason : 'permission denied, or the item is in use.')],
    $ok ? 200 : 400
);
