<?php
/** POST api/file-delete {path} — delete a file or empty dir within FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

$body = read_json_body();
$abs = fm_resolve((string) ($body['path'] ?? ''), false);
if ($abs === null || !file_exists($abs)) {
    json_out(['ok' => false, 'error' => 'Path not found or not allowed.'], 400);
}
$ok = is_dir($abs) ? @rmdir($abs) : @unlink($abs);
audit('file.delete', fm_rel($abs) . ($ok ? '' : ' FAILED'));
json_out(
    $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Delete failed (permissions, or directory not empty).'],
    $ok ? 200 : 400
);
