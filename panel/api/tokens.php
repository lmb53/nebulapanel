<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post(); csrf_check(); $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'generate') { $res = api_token_generate((string) ($body['label'] ?? '')); }
    elseif ($action === 'revoke') { $res = api_token_revoke((string) ($body['id'] ?? '')); }
    else { $res = ['ok' => false, 'error' => 'Unknown action.']; }
    json_out($res, $res['ok'] ? 200 : 400);
}
json_out(['ok' => true, 'tokens' => array_map('api_token_public', api_tokens_load())]);
