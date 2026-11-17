<?php
/** api/apps — GET catalog+state; POST {action: install|uninstall|php-install}. */
require APP_ROOT . '/lib/mod_apps.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('packages.manage');
    $b = read_json_body();
    $action = (string) ($b['action'] ?? '');
    // Starts the NDJSON progress stream when ?stream=1 (or in a background job).
    $emit = stream_output_callback();
    if ($action === 'install') {
        $res = app_install((string) ($b['key'] ?? ''), $emit);
    } elseif ($action === 'uninstall') {
        $res = app_uninstall((string) ($b['key'] ?? ''), $emit);
    } elseif ($action === 'php-install') {
        $res = php_install((string) ($b['version'] ?? ''), $emit);
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    action_out($res);
}

$catalog = [];
foreach (app_catalog() as $key => $c) {
    $catalog[] = [
        'key'       => $key,
        'label'     => $c['label'],
        'desc'      => $c['desc'],
        'icon'      => $c['icon'],
        'logo'      => !empty($c['logo']) ? asset($c['logo']) : '',
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
