<?php
/** api/file-trash — GET lists the File Manager trash; POST {action: restore|purge|empty, id?}. */
require APP_ROOT . '/lib/files.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out(['ok' => true, 'items' => fm_trash_list()]);
}
require_post();
csrf_check();
$body = read_json_body();
$id = (string) ($body['id'] ?? '');
switch ((string) ($body['action'] ?? '')) {
    case 'restore': $res = fm_trash_restore($id); break;
    case 'purge':   $res = fm_trash_purge($id); break;
    case 'empty':   $res = fm_trash_empty(); break;
    default:        $res = ['ok' => false, 'error' => 'Unknown action.'];
}
json_out($res, $res['ok'] ? 200 : 400);
