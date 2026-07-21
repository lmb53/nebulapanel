<?php
/** POST {paths:[], dest, name} — create a .tar.gz archive within FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();
$body = read_json_body();
$res = fm_compress((array) ($body['paths'] ?? []), (string) ($body['dest'] ?? ''), (string) ($body['name'] ?? ''));
json_out($res, !empty($res['ok']) ? 200 : 400);
