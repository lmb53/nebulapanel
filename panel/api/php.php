<?php
/** PHP Manager API: settings, versions, extensions, php.ini and FPM actions. */
require APP_ROOT . '/lib/mod_apps.php';
require APP_ROOT . '/lib/mod_php.php';

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

    if ($action === 'set') {
        $res = php_set((string) ($body['version'] ?? ''), (string) ($body['key'] ?? ''), (string) ($body['value'] ?? ''));
    } elseif ($action === 'install') {
        $res = php_install((string) ($body['version'] ?? ''), $emit);
    } elseif ($action === 'extension') {
        $res = php_extension_action(
            (string) ($body['version'] ?? ''),
            (string) ($body['extension'] ?? ''),
            (string) ($body['operation'] ?? ''),
            $emit
        );
    } elseif ($action === 'restart') {
        $res = php_restart_fpm((string) ($body['version'] ?? ''));
    } elseif ($action === 'opcache_reset') {
        $res = php_restart_fpm((string) ($body['version'] ?? ''));
        if (!empty($res['ok'])) { audit('php.opcache.reset', (string) ($body['version'] ?? '')); }
    } elseif ($action === 'composer_install') {
        $res = php_composer_install($emit);
    } elseif ($action === 'ini_save') {
        $res = php_ini_replace((string) ($body['version'] ?? ''), (string) ($body['content'] ?? ''));
    } elseif ($action === 'ini_restore') {
        $res = php_ini_restore((string) ($body['version'] ?? ''));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }

    if ($streaming) {
        stream_json_event(['type' => 'result', 'result' => $res]);
        exit;
    }
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
    'extensions' => php_extension_states($version),
    'ini' => php_ini_content($version),
    'pools' => php_fpm_pools($version),
    'summaries' => php_version_summaries(),
]);
