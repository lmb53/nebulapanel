<?php
/** api/sites — GET status/list; POST {action:create|delete|ssl, ...}. */
require APP_ROOT . '/lib/mod_sites.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    $streaming = ($_GET['stream'] ?? '') === '1';
    $emit = null;
    if ($streaming) {
        stream_json_start();
        stream_json_event(['type' => 'start']);
        $emit = static function (string $text, string $channel): void {
            stream_json_event(['type' => 'output', 'channel' => $channel, 'text' => $text]);
        };
    }
    if ($action === 'create') {
        require_capability('websites.manage');
        $res = site_create(
            (string) ($body['domain'] ?? ''),
            (string) ($body['php'] ?? '')
        );
    } elseif ($action === 'delete') {
        require_capability('websites.manage');
        $res = site_delete((string) ($body['domain'] ?? ''), !empty($body['drop_databases']), $emit ?? stream_output_callback());
    } elseif ($action === 'ssl') {
        require_capability('websites.manage');
        $res = site_ssl(
            (string) ($body['domain'] ?? ''),
            (string) ($body['email'] ?? ''),
            $emit
        );
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
    if ($streaming) {
        stream_json_event(['type' => 'result', 'result' => $res]);
        exit;
    }
    json_out($res, $res['ok'] ? 200 : 400);
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
