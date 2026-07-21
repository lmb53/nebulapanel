<?php
/** api/sites — GET status/list; POST {action:create|delete|ssl, ...}. */
require APP_ROOT . '/lib/mod_sites.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'create') {
        $res = site_create(
            (string) ($body['domain'] ?? ''),
            (string) ($body['docroot'] ?? ''),
            (string) ($body['php'] ?? '')
        );
    } elseif ($action === 'delete') {
        $res = site_delete((string) ($body['domain'] ?? ''), (bool) ($body['purge'] ?? false));
    } elseif ($action === 'ssl') {
        $res = site_ssl((string) ($body['domain'] ?? ''), (string) ($body['email'] ?? ''));
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
