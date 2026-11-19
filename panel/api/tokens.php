<?php
/**
 * api/tokens — every API token (administrators). People manage their own
 * tokens through api/account.
 *   GET  → {tokens}
 *   POST {action: generate | revoke, ...}
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'generate') {
        // Never from a bearer token (it would mint tokens without an owner),
        // and only after a recent password check.
        require_recent_auth();
        $res = api_token_generate(
            (string) ($body['label'] ?? ''),
            (string) ($body['role'] ?? 'auditor'),
            is_array($body['scopes'] ?? null) ? $body['scopes'] : ['get:health'],
            (int) ($body['ttl_days'] ?? 30),
            is_array($body['allowed_ips'] ?? null) ? $body['allowed_ips'] : []
        );
    } elseif ($action === 'revoke') {
        $res = api_token_revoke((string) ($body['id'] ?? ''));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, $res['ok'] ? 200 : 400);
}
json_out(['ok' => true, 'tokens' => array_map('api_token_public', api_tokens_load())]);
