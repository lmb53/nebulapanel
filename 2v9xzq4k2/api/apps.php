<?php
/** api/apps — GET catalog+state; POST {action: install|uninstall|php-install}. */
require APP_ROOT . '/lib/mod_apps.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $b = read_json_body();
    $action = (string) ($b['action'] ?? '');
    if ($action === 'install') {
        $res = app_install((string) ($b['key'] ?? ''));
    } elseif ($action === 'uninstall') {
        $res = app_uninstall((string) ($b['key'] ?? ''));
    } elseif ($action === 'php-install') {
        $res = php_install((string) ($b['version'] ?? ''));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
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
