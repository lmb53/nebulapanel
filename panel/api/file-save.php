<?php
/** POST api/file-save {path, content} — save text content within FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

$body = read_json_body();
$res = fm_save((string) ($body['path'] ?? ''), (string) ($body['content'] ?? ''), (string) ($body['base_hash'] ?? ''));
json_out($res, $res['ok'] ? 200 : (!empty($res['conflict']) ? 409 : 400));
