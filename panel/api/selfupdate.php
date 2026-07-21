<?php
/** api/selfupdate — GET check; POST {action:apply}. */
require APP_ROOT . '/lib/mod_selfupdate.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('panel.update');
    $body = read_json_body();
    if ((string) ($body['action'] ?? '') === 'apply') {
        $res = su_apply();
        json_out($res, $res['ok'] ? 200 : 400);
    }
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

json_out(su_check());
