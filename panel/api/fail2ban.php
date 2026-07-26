<?php
/** api/fail2ban — GET jail status; POST {action:ban|unban|log, ...}. */
require APP_ROOT . '/lib/mod_fail2ban.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('firewall.manage');
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'ban' || $action === 'unban') {
        $res = f2b_action($action, (string) ($body['jail'] ?? ''), (string) ($body['ip'] ?? ''));
    } elseif ($action === 'log') {
        $res = f2b_log((int) ($body['lines'] ?? 200));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, !empty($res['ok']) ? 200 : 400);
}

require_capability('firewall.manage');
json_out(['ok' => true] + f2b_status());
