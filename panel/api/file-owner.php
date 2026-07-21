<?php
/** POST {path, owner, group} — change ownership within FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();
$body = read_json_body();
$res = fm_chown((string) ($body['path'] ?? ''), (string) ($body['owner'] ?? ''), (string) ($body['group'] ?? ''));
json_out($res, !empty($res['ok']) ? 200 : 400);
