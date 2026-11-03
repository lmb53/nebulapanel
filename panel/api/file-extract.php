<?php
/** POST {path, dest, folder} — unpack an archive within FM_ROOT. Supports ?stream=1. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();
require_capability('files.manage');
$body = read_json_body();
$res = fm_extract((string) ($body['path'] ?? ''), (string) ($body['dest'] ?? ''), (string) ($body['folder'] ?? ''), stream_output_callback());
action_out($res);
