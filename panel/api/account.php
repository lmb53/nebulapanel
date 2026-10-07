<?php
/** POST api/account {action: password, current, new} — the signed-in person's own account. */
require APP_ROOT . '/lib/mod_settings.php';
require_post();
csrf_check();

$b = read_json_body();
if ((string) ($b['action'] ?? '') !== 'password') {
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}
$res = change_admin_password((string) ($b['current'] ?? ''), (string) ($b['new'] ?? ''));
json_out($res, $res['ok'] ? 200 : 400);
