<?php
/**
 * POST api/file-upload-chunk&upload_id=&name=&dir=&subdir=&offset=&total=&overwrite=
 * Body: the raw bytes of one chunk (application/octet-stream).
 * Replies {ok, done, received}; {resume, received} tells the client where to continue.
 */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();
require_capability('files.manage');
$type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($type !== 'application/octet-stream') {
    json_out(['ok' => false, 'error' => 'Content-Type must be application/octet-stream.'], 415);
}
$chunk = (string) file_get_contents('php://input', false, null, 0, FM_CHUNK_BYTES + 1);
if (strlen($chunk) > FM_CHUNK_BYTES) {
    json_out(['ok' => false, 'error' => 'Chunks may be at most ' . human_bytes(FM_CHUNK_BYTES) . '.'], 413);
}
$res = fm_upload_chunk($_GET, $chunk);
json_out($res, !empty($res['ok']) ? 200 : (!empty($res['conflict']) || !empty($res['resume']) ? 409 : 400));
