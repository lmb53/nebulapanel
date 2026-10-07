<?php
/** POST api/file-save {path, content} — save text content within FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

// The editor accepts files up to 5 MB; allow for JSON escaping overhead.
$body = read_json_body(12 * 1024 * 1024);
$res = fm_save((string) ($body['path'] ?? ''), (string) ($body['content'] ?? ''), (string) ($body['base_hash'] ?? ''));
json_out($res, $res['ok'] ? 200 : (!empty($res['conflict']) ? 409 : 400));
