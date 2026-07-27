<?php
/** api/modsecurity — GET WAF status; POST {action:mode|log, ...}. */
require APP_ROOT . '/lib/mod_modsecurity.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('firewall.manage');
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'mode') {
        $res = modsec_set_mode((string) ($body['mode'] ?? ''));
    } elseif ($action === 'log') {
        $res = modsec_log((int) ($body['lines'] ?? 200));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, !empty($res['ok']) ? 200 : 400);
}

require_capability('firewall.manage');
json_out(['ok' => true] + modsec_status());
