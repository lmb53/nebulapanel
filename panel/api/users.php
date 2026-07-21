<?php
/** api/users - control-panel accounts and role assignments. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post(); csrf_check(); $body = read_json_body(); $action = (string) ($body['action'] ?? '');
    if ($action === 'create') $res = panel_user_create((string) ($body['username'] ?? ''), (string) ($body['password'] ?? ''), (string) ($body['role'] ?? 'auditor'));
    elseif ($action === 'update') $res = panel_user_update((int) ($body['id'] ?? 0), (string) ($body['role'] ?? 'auditor'), !empty($body['enabled']), (string) ($body['password'] ?? ''));
    elseif ($action === 'delete') $res = panel_user_delete((int) ($body['id'] ?? 0));
    else $res = ['ok' => false, 'error' => 'Unknown action.'];
    json_out($res, !empty($res['ok']) ? 200 : 400);
}
json_out(['ok' => true, 'users' => array_map('panel_user_public', panel_users()), 'roles' => panel_roles(), 'current_id' => (int) ($_SESSION['uid'] ?? 0)]);
