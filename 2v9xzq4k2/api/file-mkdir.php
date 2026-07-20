<?php
/** POST api/file-mkdir {dir, name} — create a directory within FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

$body = read_json_body();
$res = fm_mkdir((string) ($body['dir'] ?? ''), (string) ($body['name'] ?? ''));
json_out($res, $res['ok'] ? 200 : 400);
