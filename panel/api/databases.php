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
    } elseif ($action === 'user_password') {
        $res = db_user_password((string) ($body['user'] ?? ''), (string) ($body['host'] ?? ''), (string) ($body['password'] ?? ''));
    } elseif ($action === 'grant') {
        $res = db_grant((string) ($body['user'] ?? ''), (string) ($body['host'] ?? ''), (string) ($body['database'] ?? ''), (string) ($body['level'] ?? ''));
    } elseif ($action === 'export') {
        $res = db_export((string) ($body['name'] ?? ''), stream_output_callback());
    } elseif ($action === 'import') {
        $res = db_import((string) ($body['name'] ?? ''), (string) ($body['staged'] ?? ''), stream_output_callback());
    } elseif ($action === 'link_website') {
        $res = db_link_website((string) ($body['database'] ?? ''), (string) ($body['website'] ?? ''));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    action_out($res);
}

$dbs = db_list();
$schemaUsers = db_schema_users();
$links = db_links();
$databases = array_values(array_filter($dbs['databases'] ?? [], static function (array $database) use ($links): bool {
    $name = (string) ($database['name'] ?? '');
    // Databases created outside the panel (or before linking existed) are
    // listed too, as "Not linked", instead of being invisible.
    return !in_array($name, SYSTEM_DBS, true) && !preg_match('/^nebula_(rs|old)_[0-9a-f]{10}$/', $name);
}));
foreach ($databases as &$database) {
    $name = (string) ($database['name'] ?? '');
    $database['users'] = $schemaUsers[$name] ?? [];
    $database['website'] = (string) ($links[$name] ?? '');
}
unset($database);
// Every ordinary account, including ones without grants yet; system and
// panel-internal accounts (root, mariadb.sys, phpMyAdmin/import helpers) are hidden.
$visibleUsers = [];
foreach ((db_users()['users'] ?? []) as $account) {
    $user = (string) ($account['user'] ?? '');
    if ($user === '' || db_user_reserved($user) || preg_match('/^nebula_(pma|imp)_/', $user)) { continue; }
    $visibleUsers[$user . '@' . $account['host']] = ['user' => $user, 'host' => (string) $account['host']];
}
require_once APP_ROOT . '/lib/mod_sites.php';
json_out([
    'ok'        => true,
    'databases' => $databases,
    'users'     => array_values($visibleUsers),
    'version'   => db_version(),
    'websites'  => array_values(array_map(fn($site) => (string) ($site['domain'] ?? ''), sites_list())),
]);
