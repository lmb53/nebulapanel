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
    } elseif ($action === 'create_bundle') {
        $res = db_create_bundle(
            (string) ($body['name'] ?? ''),
            (string) ($body['user'] ?? ''),
            (string) ($body['host'] ?? 'localhost'),
            (string) ($body['password'] ?? ''),
            (string) ($body['website'] ?? '')
        );
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
    } elseif ($action === 'link_website') {
        $res = db_link_website((string) ($body['database'] ?? ''), (string) ($body['website'] ?? ''));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, $res['ok'] ? 200 : 400);
}

$dbs = db_list();
$schemaUsers = db_schema_users();
$links = db_links();
$databases = $dbs['databases'] ?? [];
foreach ($databases as &$database) {
    $name = (string) ($database['name'] ?? '');
    $database['users'] = $schemaUsers[$name] ?? [];
    $database['website'] = (string) ($links[$name] ?? '');
}
unset($database);
require_once APP_ROOT . '/lib/mod_sites.php';
json_out([
    'ok'        => true,
    'databases' => $databases,
    'users'     => db_users()['users'] ?? [],
    'version'   => db_version(),
    'websites'  => array_values(array_map(fn($site) => (string) ($site['domain'] ?? ''), sites_list())),
]);
