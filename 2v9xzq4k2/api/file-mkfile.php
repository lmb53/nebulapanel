<?php
/** POST api/file-mkfile {dir, name} — create an empty file within FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

$body = read_json_body();
$res = fm_mkfile((string) ($body['dir'] ?? ''), (string) ($body['name'] ?? ''));
json_out($res, $res['ok'] ? 200 : 400);
