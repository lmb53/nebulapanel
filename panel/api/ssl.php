<?php
/** api/ssl — GET certificate list; POST {action:issue|renew|delete, ...}. */
require APP_ROOT . '/lib/mod_ssl.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');

    if ($action === 'issue') {
        $res = ssl_issue(
            (string) ($body['domain'] ?? ''),
            (string) ($body['email'] ?? '')
        );
    } elseif ($action === 'renew') {
        $res = ssl_renew((string) ($body['name'] ?? ''));
    } elseif ($action === 'delete') {
        $res = ssl_delete((string) ($body['name'] ?? ''));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }

    json_out($res, !empty($res['ok']) ? 200 : 400);
}

$available = ssl_available();
if (!$available) {
    json_out([
        'ok' => true,
        'available' => false,
        'certs' => [],
        'message' => 'certbot is not installed or the privileged helper is unavailable.',
    ]);
}

$res = ssl_list();
$res['available'] = true;
json_out($res, !empty($res['ok']) ? 200 : 500);
