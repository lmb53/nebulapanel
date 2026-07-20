<?php
/** POST api/file-op {path, dest, op} — copy or move an entry within FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

$body = read_json_body();
$res = fm_op((string) ($body['path'] ?? ''), (string) ($body['dest'] ?? ''), (string) ($body['op'] ?? ''));
json_out($res, $res['ok'] ? 200 : 400);
