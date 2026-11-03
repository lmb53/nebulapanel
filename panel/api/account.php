<?php
/**
 * api/account — the signed-in person's own account. Open to every role,
 * never to bearer tokens.
 *   GET  → {sessions, mfa}
 *   POST {action: password | revoke-session | revoke-other-sessions |
 *         mfa-setup | mfa-confirm | mfa-disable | mfa-recovery, ...}
 */
require APP_ROOT . '/lib/mod_settings.php';
require_once APP_ROOT . '/lib/mod_mfa.php';

$uid = (int) ($_SESSION['uid'] ?? 0);
$token = (string) ($_SESSION['sid_token'] ?? '');
if ($uid <= 0) {
    json_out(['ok' => false, 'error' => 'Sign in with a browser session to manage your account.'], 403);
}
$sessionsPayload = static fn(): array => session_registry_list($uid, (int) ($_SESSION['session_version'] ?? 1), $token);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => true, 'sessions' => $sessionsPayload(), 'mfa' => mfa_status($uid)]);
}
require_post();
csrf_check();

$b = read_json_body();
switch ((string) ($b['action'] ?? '')) {
    case 'password':
        $res = change_admin_password((string) ($b['current'] ?? ''), (string) ($b['new'] ?? ''));
        break;
    case 'revoke-session':
        $id = (string) ($b['id'] ?? '');
        $res = preg_match('/^[a-f0-9]{20}$/', $id) && session_registry_revoke($uid, $token, $id) > 0
            ? ['ok' => true, 'sessions' => $sessionsPayload()]
            : ['ok' => false, 'error' => 'That session was not found. It may already have ended.'];
        break;
    case 'revoke-other-sessions':
        $count = session_registry_revoke($uid, $token, null);
        $res = ['ok' => true, 'ended' => $count, 'sessions' => $sessionsPayload()];
        break;
    case 'mfa-setup':
        $res = mfa_begin_setup($uid, (string) ($_SESSION['username'] ?? 'user'));
        break;
    case 'mfa-confirm':
        $res = mfa_confirm_setup($uid, (string) ($b['code'] ?? ''));
        break;
    case 'mfa-disable':
        $res = mfa_disable($uid, (string) ($b['password'] ?? ''), (string) ($b['code'] ?? ''));
        break;
    case 'mfa-recovery':
        $res = mfa_regenerate_recovery($uid, (string) ($b['password'] ?? ''), (string) ($b['code'] ?? ''));
        break;
    default:
        $res = ['ok' => false, 'error' => 'Unknown action.'];
}
json_out($res, !empty($res['ok']) ? 200 : 400);
