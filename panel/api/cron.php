<?php
/** api/cron — GET list; POST {action:add|delete, ...}. */
require APP_ROOT . '/lib/mod_cron.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('cron.manage');
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    // The raw crontab line the page showed; guards index-based actions
    // against a crontab that changed in the meantime.
    $expect = isset($body['expect']) ? (string) $body['expect'] : null;
    if ($action === 'add') {
        $res = cron_add((string) ($body['schedule'] ?? ''), (string) ($body['command'] ?? ''));
    } elseif ($action === 'delete') {
        $res = cron_delete((int) ($body['index'] ?? -1), $expect);
    } elseif ($action === 'update') {
        $res = cron_update((int)($body['index']??-1),(string)($body['schedule']??''),(string)($body['command']??''),$expect);
    } elseif ($action === 'run') {
        $res = cron_run_now((int)($body['index']??-1), $expect);
    } elseif ($action === 'toggle') {
        $res = cron_toggle((int)($body['index']??-1), (bool)($body['enabled']??false), $expect);
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, $res['ok'] ? 200 : 400);
}

json_out(['ok' => true, 'jobs' => cron_list()]);
