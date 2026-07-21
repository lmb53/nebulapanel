<?php
/** GET pins/history; POST {action:toggle-pin|clear-recent, path?}. */
require APP_ROOT . '/lib/files.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'toggle-pin') {
        $res = fm_toggle_pin((string) ($body['path'] ?? ''));
        json_out($res, !empty($res['ok']) ? 200 : 400);
    }
    if ($action === 'clear-recent') {
        $state = fm_state();
        $state['recent'] = [];
        $ok = fm_save_state($state);
        json_out($ok ? ['ok' => true] : ['ok' => false, 'error' => 'Could not clear recent files.'], $ok ? 200 : 500);
    }
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

json_out(['ok' => true, 'pinned' => fm_state_entries('pinned'), 'recent' => fm_state_entries('recent')]);
