<?php
/** api/firewall — GET status; POST {action:add|delete|enable|disable, ...}. */
require APP_ROOT . '/lib/mod_firewall.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'add') {
        $res = fw_add(
            (string) ($body['ufwAction'] ?? ''),
            (string) ($body['port'] ?? ''),
            (string) ($body['proto'] ?? '')
        );
    } elseif ($action === 'delete') {
        $res = fw_delete((int) ($body['num'] ?? -1));
    } elseif ($action === 'enable') {
        $res = fw_set(true);
    } elseif ($action === 'disable') {
        $res = fw_set(false);
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, $res['ok'] ? 200 : 400);
}

json_out(fw_status() + ['ok' => true]);
