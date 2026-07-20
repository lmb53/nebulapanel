<?php
/** api/databases — GET lists; POST {action, ...} for create/drop of dbs & users. */
require APP_ROOT . '/lib/mod_db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'create_db') {
        $res = db_create((string) ($body['name'] ?? ''));
    } elseif ($action === 'drop_db') {
        $res = db_drop((string) ($body['name'] ?? ''));
    } elseif ($action === 'create_user') {
        $res = db_create_user(
            (string) ($body['user'] ?? ''),
            (string) ($body['host'] ?? ''),
            (string) ($body['password'] ?? ''),
            (string) ($body['grant_db'] ?? '')
        );
    } elseif ($action === 'drop_user') {
        $res = db_drop_user((string) ($body['user'] ?? ''), (string) ($body['host'] ?? ''));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, $res['ok'] ? 200 : 400);
}

json_out([
    'ok'        => true,
    'databases' => db_list()['databases'] ?? [],
    'users'     => db_users()['users'] ?? [],
]);
