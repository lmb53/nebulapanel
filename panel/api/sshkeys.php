<?php
require APP_ROOT . '/lib/mod_sshkeys.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post(); csrf_check(); $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'add') { $res = sshkey_add((string) ($body['user'] ?? ''), (string) ($body['key'] ?? '')); }
    elseif ($action === 'delete') { $res = sshkey_delete((string) ($body['user'] ?? ''), (int) ($body['number'] ?? 0)); }
    else { $res = ['ok' => false, 'error' => 'Unknown action.']; }
    json_out($res, $res['ok'] ? 200 : 400);
}
$user = (string) ($_GET['user'] ?? '');
json_out(['ok' => true, 'keys' => sshkey_list($user)]);
