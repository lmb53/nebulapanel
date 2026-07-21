<?php
/** api/apps — GET catalog+state; POST {action: install|uninstall|php-install}. */
require APP_ROOT . '/lib/mod_apps.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('packages.manage');
    $b = read_json_body();
    $action = (string) ($b['action'] ?? '');
    $streaming = ($_GET['stream'] ?? '') === '1';
    $emit = null;
    if ($streaming) {
        stream_json_start();
        stream_json_event(['type' => 'start']);
        $emit = static function (string $text, string $channel): void {
            stream_json_event(['type' => 'output', 'channel' => $channel, 'text' => $text]);
        };
    }
    if ($action === 'install') {
        $res = app_install((string) ($b['key'] ?? ''), $emit);
    } elseif ($action === 'uninstall') {
        $res = app_uninstall((string) ($b['key'] ?? ''), $emit);
    } elseif ($action === 'php-install') {
        $res = php_install((string) ($b['version'] ?? ''), $emit);
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    if ($streaming) {
        stream_json_event(['type' => 'result', 'result' => $res]);
        exit;
    }
    json_out($res, $res['ok'] ? 200 : 400);
}

$catalog = [];
foreach (app_catalog() as $key => $c) {
    $catalog[] = [
        'key'       => $key,
        'label'     => $c['label'],
        'desc'      => $c['desc'],
        'icon'      => $c['icon'],
        'installed' => app_installed($key),
    ];
}
json_out([
    'ok'            => true,
    'helper'        => helper_available(),
    'catalog'       => $catalog,
    'php_installed' => php_installed_versions(),
    'php_available' => php_installable_versions(),
]);
