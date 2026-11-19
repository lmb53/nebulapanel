<?php
/**
 * api/account — the signed-in person's own account. Open to every role,
 * never to bearer tokens.
 *   GET  → {sessions, mfa}
 *   POST {action: password | revoke-session | revoke-other-sessions |
 *         mfa-setup | mfa-confirm | mfa-disable | mfa-recovery |
 *         passkey-options | passkey-register | passkey-delete |
 *         token-list | token-generate | token-revoke, ...}
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
$action = (string) ($b['action'] ?? '');
// Actions that check the current password or a second factor are throttled
// like sign-in, so a hijacked session cannot guess them at full speed.
$reauthActions = ['password', 'mfa-disable', 'mfa-recovery', 'mfa-confirm', 'passkey-options', 'passkey-delete', 'reauth'];
if (in_array($action, $reauthActions, true) && ($blocked = reauth_throttle_error()) !== null) {
    json_out(['ok' => false, 'error' => $blocked], 429);
}
switch ($action) {
    case 'reauth':
        $res = reauthenticate_session((string) ($b['password'] ?? ''), (string) ($b['code'] ?? ''));
        break;
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
    case 'passkey-options':
        require_once APP_ROOT . '/lib/mod_passkeys.php';
        $res = passkey_register_options($uid, (string) ($b['password'] ?? ''), (string) ($b['code'] ?? ''));
        break;
    case 'passkey-register':
        require_once APP_ROOT . '/lib/mod_passkeys.php';
        $res = passkey_register_finish($uid, (string) ($b['name'] ?? ''), (string) ($b['clientDataJSON'] ?? ''), (string) ($b['attestationObject'] ?? ''));
        break;
    case 'passkey-delete':
        require_once APP_ROOT . '/lib/mod_passkeys.php';
        $res = passkey_delete($uid, (string) ($b['id'] ?? ''), (string) ($b['password'] ?? ''), (string) ($b['code'] ?? ''));
        break;
    case 'token-list':
        require_once APP_ROOT . '/lib/mod_api.php';
        $res = ['ok' => true, 'tokens' => api_tokens_for($uid)];
        break;
    case 'token-generate':
        require_recent_auth();
        require_once APP_ROOT . '/lib/mod_api.php';
        $owner = api_current_owner();
        $res = $owner === null ? ['ok' => false, 'error' => 'Your account was not found.'] : api_token_generate(
            (string) ($b['label'] ?? ''), (string) ($b['role'] ?? $owner['role']),
            is_array($b['scopes'] ?? null) ? $b['scopes'] : ['get:health'], (int) ($b['ttl_days'] ?? 30),
            is_array($b['allowed_ips'] ?? null) ? $b['allowed_ips'] : [], $owner);
        break;
    case 'token-revoke':
        require_once APP_ROOT . '/lib/mod_api.php';
        $res = api_token_revoke((string) ($b['id'] ?? ''), $uid);
        break;
    default:
        $res = ['ok' => false, 'error' => 'Unknown action.'];
}
if (!empty($res['ok']) && in_array($action, $reauthActions, true)) {
    reauth_succeeded();
}
json_out($res, !empty($res['ok']) ? 200 : 400);
