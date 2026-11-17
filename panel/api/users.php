<?php
/**
 * api/users — control-panel accounts and role assignments (administrators).
 *   GET  → {users, roles, current_id}
 *   POST {action: create | update | reset-mfa | delete, ...}
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    $id = (int) ($body['id'] ?? 0);
    switch ($action) {
        case 'create':
            $res = panel_user_create((string) ($body['username'] ?? ''), (string) ($body['password'] ?? ''), (string) ($body['role'] ?? 'auditor'));
            break;
        case 'update':
            $res = panel_user_update($id, (string) ($body['role'] ?? 'auditor'), !empty($body['enabled']), (string) ($body['password'] ?? ''));
            break;
        case 'reset-mfa':
            require_once APP_ROOT . '/lib/mod_mfa.php';
            $res = $id === (int) ($_SESSION['uid'] ?? 0)
                ? ['ok' => false, 'error' => 'Turn off your own two-factor sign-in from My account.']
                : mfa_admin_reset($id);
            break;
        case 'delete':
            $res = panel_user_delete($id);
            break;
        default:
            $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, !empty($res['ok']) ? 200 : 400);
}
json_out(['ok' => true, 'users' => array_map('panel_user_public', panel_users()), 'roles' => panel_roles(), 'current_id' => (int) ($_SESSION['uid'] ?? 0)]);
