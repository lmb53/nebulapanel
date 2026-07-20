<?php
/** api/updates — GET list; POST {action:refresh|upgrade}. */
require APP_ROOT . '/lib/mod_updates.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'refresh') {
        $res = upd_refresh();
    } elseif ($action === 'upgrade') {
        $res = upd_upgrade();
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, $res['ok'] ? 200 : 400);
}

json_out(['ok' => true, 'count' => upd_count(), 'packages' => upd_list()]);
