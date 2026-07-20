<?php
/** POST api/settings — {action: general|password, ...}. */
require APP_ROOT . '/lib/mod_settings.php';
require_post();
csrf_check();

$b = read_json_body();
$action = (string) ($b['action'] ?? '');

if ($action === 'general') {
    $res = settings_update_general(
        array_key_exists('panel_name', $b) ? (string) $b['panel_name'] : null,
        $b['session_timeout'] ?? null,
        $b['health_warn_percent'] ?? null,
        $b['health_critical_percent'] ?? null
    );
} elseif ($action === 'password') {
    $res = change_admin_password((string) ($b['current'] ?? ''), (string) ($b['new'] ?? ''));
} else {
    $res = ['ok' => false, 'error' => 'Unknown action.'];
}

json_out($res, $res['ok'] ? 200 : 400);
