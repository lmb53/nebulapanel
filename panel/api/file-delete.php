<?php
/** POST api/file-delete {path, permanent?} — move an entry within FM_ROOT to the
 * recoverable trash, or (permanent: true) delete it outright. Permanent
 * directory deletes are recursive. When the web user cannot remove a target
 * (root-owned files, or a non-empty directory it doesn't fully own) the
 * privileged helper does it as root, still confined to the FM root. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

$body = read_json_body();
$abs = fm_resolve((string) ($body['path'] ?? ''), false);
if ($abs === null || !file_exists($abs)) {
    json_out(['ok' => false, 'error' => 'Path not found or not allowed.'], 400);
}
if ($abs === fm_root()) {
    json_out(['ok' => false, 'error' => 'The File Manager root folder cannot be deleted.'], 400);
}

if (empty($body['permanent'])) {
    $res = fm_trash_move($abs);
    json_out($res, $res['ok'] ? 200 : 409);
}

$ok = fm_remove_tree($abs);

// Fall back to the privileged helper for anything the web user can't remove
// (e.g. root-owned deploy artifacts, or a leftover website docroot).
if (!$ok && file_exists($abs) && helper_available()) {
    [$code] = helper_cmd('file-delete ' . escapeshellarg($abs), 60);
    $ok = $code === 0 && !file_exists($abs);
}

audit('file.delete', fm_rel($abs) . ($ok ? '' : ' FAILED'));
json_out(
    $ok ? ['ok' => true, 'trashed' => false] : ['ok' => false, 'error' => 'Delete failed (permission denied, or the item is in use).'],
    $ok ? 200 : 400
);
