<?php
/** api/cron — GET list; POST {action:add|delete, ...}. */
require APP_ROOT . '/lib/mod_cron.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'add') {
        $res = cron_add((string) ($body['schedule'] ?? ''), (string) ($body['command'] ?? ''));
    } elseif ($action === 'delete') {
        $res = cron_delete((int) ($body['index'] ?? -1));
    } elseif ($action === 'update') {
        $res = cron_update((int)($body['index']??-1),(string)($body['schedule']??''),(string)($body['command']??''));
    } elseif ($action === 'run') {
        $res = cron_run_now((int)($body['index']??-1));
    } elseif ($action === 'toggle') {
        $res = cron_toggle((int)($body['index']??-1), (bool)($body['enabled']??false));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, $res['ok'] ? 200 : 400);
}

json_out(['ok' => true, 'jobs' => cron_list()]);
