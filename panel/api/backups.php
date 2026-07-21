<?php
/** api/backups — GET list; POST {action:create|delete, ...}. */
require APP_ROOT . '/lib/mod_backups.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('backups.manage');
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'create') {
        $res = backup_create((string) ($body['source'] ?? ''), (string) ($body['label'] ?? ''));
    } elseif ($action === 'delete') {
        $res = backup_delete((string) ($body['file'] ?? ''));
    } elseif ($action === 'verify') {
        $res = backup_verify((string) ($body['file'] ?? ''));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, $res['ok'] ? 200 : 400);
}

json_out(['ok' => true, 'backups' => backup_list()]);
