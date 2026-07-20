<?php
/** POST api/file-chmod {path, mode} — change permissions within FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

$body = read_json_body();
$res = fm_chmod((string) ($body['path'] ?? ''), (string) ($body['mode'] ?? ''));
json_out($res, $res['ok'] ? 200 : 400);
