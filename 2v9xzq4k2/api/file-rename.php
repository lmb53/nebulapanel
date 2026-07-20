<?php
/** POST api/file-rename {path, name} — rename an entry within FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

$body = read_json_body();
$res = fm_rename((string) ($body['path'] ?? ''), (string) ($body['name'] ?? ''));
json_out($res, $res['ok'] ? 200 : 400);
