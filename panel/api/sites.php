<?php
/** api/sites — GET status/list; POST {action:create|delete|ssl, ...}. */
require APP_ROOT . '/lib/mod_sites.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    // Starts the NDJSON progress stream when ?stream=1 (or in a background job).
    $emit = stream_output_callback();
    if ($action === 'create') {
        require_capability('websites.manage');
        $res = site_create(
            (string) ($body['domain'] ?? ''),
            (string) ($body['php'] ?? '')
        );
    } elseif ($action === 'delete') {
        require_capability('websites.manage');
        $res = site_delete((string) ($body['domain'] ?? ''), !empty($body['drop_databases']), $emit);
    } elseif ($action === 'ssl') {
        require_capability('websites.manage');
        $res = site_ssl(
            (string) ($body['domain'] ?? ''),
            (string) ($body['email'] ?? ''),
            $emit
        );
    } elseif ($action === 'cron-list') {
        require_capability('websites.manage');
        $res = site_cron_lines((string) ($body['domain'] ?? ''));
    } elseif ($action === 'cron-add') {
        require_capability('websites.manage');
        $res = site_cron_add((string) ($body['domain'] ?? ''), (string) ($body['schedule'] ?? ''), (string) ($body['command'] ?? ''));
    } elseif ($action === 'cron-delete') {
        require_capability('websites.manage');
        $res = site_cron_delete((string) ($body['domain'] ?? ''), (int) ($body['index'] ?? -1), (string) ($body['expect'] ?? ''));
    } elseif ($action === 'sftp') {
        require_capability('websites.manage');
        $res = site_sftp((string) ($body['domain'] ?? ''), !empty($body['enable']), (string) ($body['password'] ?? ''));
    } elseif ($action === 'clone') {
        require_capability('websites.manage');
        $res = site_clone((string) ($body['domain'] ?? ''), (string) ($body['new_domain'] ?? ''), !empty($body['databases']), $emit);
    } elseif ($action === 'settings') {
        require_capability('websites.manage');
        $res = site_save_settings((string) ($body['domain'] ?? ''), (array) ($body['settings'] ?? []));
    } elseif ($action === 'trash-purge') {
        require_capability('websites.manage');
        $res = sites_trash_purge((string) ($body['name'] ?? ''));
    } elseif ($action === 'php') {
        require_capability('websites.manage');
        $res = site_set_php((string) ($body['domain'] ?? ''), (string) ($body['version'] ?? ''));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    action_out($res);
}

if (($_GET['trash'] ?? '') === '1') {
    json_out(['ok' => true, 'archived' => sites_trash_list()]);
}

json_out([
    'ok'           => true,
    'available'    => sites_available(),
    'sites'        => sites_with_runtime(),
    'php_versions' => php_versions(),
]);
