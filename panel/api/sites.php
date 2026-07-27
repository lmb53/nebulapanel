<?php
/** api/sites — GET status/list; POST {action:create|delete|ssl, ...}. */
require APP_ROOT . '/lib/mod_sites.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'create') {
        require_capability('websites.manage');
        $res = site_create(
            (string) ($body['domain'] ?? ''),
            (string) ($body['php'] ?? '')
        );
    } elseif ($action === 'delete') {
        require_capability('websites.manage');
        $res = site_delete((string) ($body['domain'] ?? ''), (bool) ($body['purge'] ?? false));
    } elseif ($action === 'ssl') {
        require_capability('websites.manage');
        $res = site_ssl((string) ($body['domain'] ?? ''), (string) ($body['email'] ?? ''));
    } elseif ($action === 'php') {
        require_capability('websites.manage');
        $res = site_set_php((string) ($body['domain'] ?? ''), (string) ($body['version'] ?? ''));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, $res['ok'] ? 200 : 400);
}

json_out([
    'ok'           => true,
    'available'    => sites_available(),
    'sites'        => sites_with_runtime(),
    'php_versions' => php_versions(),
]);
