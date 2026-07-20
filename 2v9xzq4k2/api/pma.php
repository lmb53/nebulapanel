<?php
/** api/pma — GET status; POST {action:install|remove}. */
require APP_ROOT . '/lib/mod_pma.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'install') {
        $res = pma_install();
    } elseif ($action === 'remove') {
        $res = pma_remove();
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, $res['ok'] ? 200 : 400);
}

json_out([
    'ok'        => true,
    'installed' => pma_installed(),
    'url'       => pma_url(),
    'helper'    => helper_available(),
]);
