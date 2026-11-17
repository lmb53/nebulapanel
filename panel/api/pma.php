<?php
/** api/pma — GET status; POST {action:install|remove}. */
require APP_ROOT . '/lib/mod_pma.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    // Starts the NDJSON progress stream when ?stream=1 (or in a background job).
    $emit = stream_output_callback();
    if ($action === 'install') {
        $res = pma_install($emit);
    } elseif ($action === 'remove') {
        $res = pma_remove();
    } elseif ($action === 'launch') {
        $res = pma_launch((string) ($body['database'] ?? ''));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    action_out($res);
}

json_out([
    'ok'        => true,
    'installed' => pma_installed(),
    'url'       => pma_url(),
    'helper'    => helper_available(),
]);
