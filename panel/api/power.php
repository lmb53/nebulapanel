<?php
/** POST api/power {action: reboot|poweroff, force?} — restart or shut down the server (administrators only). */
require_post();
csrf_check();
require_capability('system.power');

$body = read_json_body();
$action = (string) ($body['action'] ?? '');
if ($action !== 'reboot' && $action !== 'poweroff') {
    json_out(['ok' => false, 'error' => 'Unknown power action.'], 400);
}
if (!helper_available()) {
    json_out(['ok' => false, 'error' => 'Privileged helper not installed — re-run install.sh.'], 400);
}
$args = 'system-power ' . escapeshellarg($action) . (!empty($body['force']) ? ' force' : '');
[$code, $out] = helper_cmd($args, 30);
audit('system.' . $action, $code === 0 ? 'scheduled' : 'refused: ' . trim($out));
if ($code !== 0) {
    $error = trim($out);
    if (stripos($error, 'unknown command') !== false) {
        $error = 'The privileged helper on this server is out of date. Update the panel (Panel Updates) or re-run install.sh.';
    }
    json_out(['ok' => false, 'error' => $error ?: 'The server refused the request.', 'busy' => stripos($error, 'wait for it') !== false], 409);
}
json_out(['ok' => true, 'action' => $action]);
