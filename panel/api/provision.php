<?php
/** POST api/provision — first-run stack installation and wizard completion. */
require APP_ROOT . '/lib/mod_apps.php';
require APP_ROOT . '/lib/mod_pma.php';

require_post();
csrf_check();
require_capability('panel.provision');
$body = read_json_body();
$action = (string) ($body['action'] ?? '');

if ($action === 'finish') {
    $ok = write_json_file(DATA_DIR . '/provisioned.json', [
        'finished_at' => date('c'),
        'user' => current_user(),
    ]);
    if ($ok) {
        audit('provision.finish');
    }
    json_out($ok ? ['ok' => true] : ['ok' => false, 'error' => 'Could not save provisioning state.'], $ok ? 200 : 500);
}

if ($action !== 'install') {
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$key = (string) ($body['key'] ?? '');
$streaming = ($_GET['stream'] ?? '') === '1';
$emit = null;
if ($streaming) {
    stream_json_start();
    stream_json_event(['type' => 'start']);
    $emit = static function (string $text, string $channel): void {
        stream_json_event(['type' => 'output', 'channel' => $channel, 'text' => $text]);
    };
}
if (strpos($key, 'php:') === 0) {
    $version = substr($key, 4);
    $allowed = array_values(array_unique(array_merge(php_installable_versions(), php_installed_versions())));
    $res = in_array($version, $allowed, true)
        ? (in_array($version, php_installed_versions(), true) ? ['ok' => true] : php_install($version, $emit))
        : ['ok' => false, 'error' => 'Unsupported PHP version.'];
} elseif ($key === 'phpmyadmin') {
    $res = pma_install($emit);
} else {
    $res = app_install($key, $emit);
}

if ($streaming) {
    stream_json_event(['type' => 'result', 'result' => $res]);
    exit;
}
json_out($res, !empty($res['ok']) ? 200 : 400);
