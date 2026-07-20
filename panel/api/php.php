<?php
/** api/php — GET version details; POST {action:set, version, key, value}. */
require APP_ROOT . '/lib/mod_apps.php';
require APP_ROOT . '/lib/mod_php.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();

    if (($body['action'] ?? '') !== 'set') {
        json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }

    $version = (string) ($body['version'] ?? '');
    if (!in_array($version, php_installed_versions(), true)) {
        json_out(['ok' => false, 'error' => 'PHP version is not installed.'], 400);
    }

    $res = php_set(
        $version,
        (string) ($body['key'] ?? ''),
        (string) ($body['value'] ?? '')
    );
    json_out($res, !empty($res['ok']) ? 200 : 400);
}

$versions = php_installed_versions();
$version = (string) ($_GET['version'] ?? php_default_version());
if ($version === '' || !in_array($version, $versions, true)) {
    json_out(['ok' => false, 'error' => 'PHP version is not installed.', 'versions' => $versions], 404);
}

json_out([
    'ok' => true,
    'versions' => $versions,
    'version' => $version,
    'settings' => php_read_settings($version),
    'modules' => php_modules($version),
]);
