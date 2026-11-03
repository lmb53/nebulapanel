<?php
/**
 * api/backups — GET list + schedule; POST {action: create|delete|verify|
 * restore|schedule|run-schedule, ...}. create, restore and run-schedule
 * support ?stream=1 progress output.
 */
require APP_ROOT . '/lib/mod_backups.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('backups.manage');
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'create') {
        $res = backup_create((string) ($body['site_id'] ?? ''), (string) ($body['label'] ?? ''),
            (bool) ($body['include_databases'] ?? true), stream_output_callback());
    } elseif ($action === 'delete') {
        $res = backup_delete((string) ($body['file'] ?? ''));
    } elseif ($action === 'verify') {
        $res = backup_verify((string) ($body['file'] ?? ''));
    } elseif ($action === 'restore') {
        $res = backup_restore(
            (string) ($body['file'] ?? ''),
            (bool) ($body['files'] ?? true),
            array_map('strval', (array) ($body['databases'] ?? [])),
            (string) ($body['target_site_id'] ?? ''),
            (bool) ($body['safety_backup'] ?? true),
            stream_output_callback()
        );
    } elseif ($action === 'schedule') {
        $res = backup_schedule_save($body);
    } elseif ($action === 'run-schedule') {
        @set_time_limit(0);
        $res = backup_schedule_run(stream_output_callback());
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    action_out($res);
}

$schedule = backup_schedule();
json_out(['ok' => true, 'backups' => backup_list(), 'schedule' => $schedule, 'next_run' => backup_schedule_next($schedule)]);
