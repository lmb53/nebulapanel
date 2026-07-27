<?php
/** Hashed bearer-token management for scripted access to JSON API routes. */

function api_tokens_file(): string { return DATA_DIR . '/api_tokens.json'; }

function api_tokens_load(): array
{
    $data = @json_decode((string) @file_get_contents(api_tokens_file()), true);
    return is_array($data) ? array_values($data) : [];
}

function api_tokens_save(array $tokens): bool
{
    return write_json_file(api_tokens_file(), array_values($tokens));
}

function with_api_tokens_lock(callable $callback)
{
    $handle = @fopen(DATA_DIR . '/api_tokens.lock', 'c');
    if ($handle === false || !@flock($handle, LOCK_EX)) {
        if (is_resource($handle)) { fclose($handle); }
        return ['ok' => false, 'error' => 'Could not lock the token store.'];
    }
    try { return $callback(); }
    finally { @flock($handle, LOCK_UN); fclose($handle); }
}

function api_token_public(array $token): array
{
    return array_intersect_key($token, array_flip(['id', 'label', 'role', 'scopes', 'allowed_ips', 'created_at', 'expires_at', 'last_used_at']));
}

function api_token_generate(string $label, string $role = 'auditor', array $scopes = ['get:health'], int $ttlDays = 30, array $allowedIps = []): array
{
    $label = trim($label);
    if ($label === '' || strlen($label) > 80) { return ['ok' => false, 'error' => 'Label must be 1–80 characters.']; }
    if (!isset(panel_roles()[$role])) { return ['ok' => false, 'error' => 'Invalid token role.']; }
    $ttlDays = max(1, min(365, $ttlDays));
    $scopes = array_values(array_unique(array_map('strtolower', array_map('trim', $scopes))));
    if (!$scopes || count($scopes) > 50) { return ['ok' => false, 'error' => 'At least one scope is required.']; }
    foreach ($scopes as $scope) {
        if ($scope !== '*' && !preg_match('/^(get|post):[a-z0-9_-]+$/', $scope) && !preg_match('/^[a-z0-9_-]+:\*$/', $scope)) {
            return ['ok' => false, 'error' => 'Invalid token scope: ' . $scope];
        }
    }
    if ($role !== 'admin' && in_array('*', $scopes, true)) {
        return ['ok' => false, 'error' => 'Only administrator tokens may use the wildcard scope.'];
    }
    $allowedIps = array_values(array_unique(array_filter(array_map('trim', $allowedIps))));
    if (count($allowedIps) > 20) return ['ok'=>false,'error'=>'At most 20 source IP addresses may be assigned.'];
    foreach ($allowedIps as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return ['ok'=>false,'error'=>'Invalid allowed IP: '.$ip];
    }
    $plain = 'nbp_' . bin2hex(random_bytes(24));
    $record = [
        'id' => bin2hex(random_bytes(8)),
        'label' => $label,
        'hash' => hash('sha256', $plain),
        'role' => $role,
        'scopes' => $scopes,
        'allowed_ips' => $allowedIps,
        'created_at' => date('c'),
        'expires_at' => date('c', time() + $ttlDays * 86400),
        'last_used_at' => null,
    ];
    $saved = with_api_tokens_lock(static function () use ($record): array {
        $tokens = api_tokens_load();
        $tokens[] = $record;
        return ['ok' => api_tokens_save($tokens)];
    });
    if (empty($saved['ok'])) { return ['ok' => false, 'error' => 'Could not save token.']; }
    audit('api_token.generate', $label);
    return ['ok' => true, 'token' => $plain, 'record' => api_token_public($record)];
}

function api_token_revoke(string $id): array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) { return ['ok' => false, 'error' => 'Invalid token.']; }
    $result = with_api_tokens_lock(static function () use ($id): array {
        $tokens = api_tokens_load();
        $next = array_values(array_filter($tokens, fn($token) => ($token['id'] ?? '') !== $id));
        if (count($next) === count($tokens)) { return ['ok' => false, 'error' => 'Token not found.']; }
        return api_tokens_save($next) ? ['ok' => true] : ['ok' => false, 'error' => 'Could not revoke token.'];
    });
    if (!empty($result['ok'])) { audit('api_token.revoke', $id); }
    return $result;
}

function api_token_authenticate(string $plain): bool
{
    global $apiAuthLabel, $apiAuthRole, $apiAuthScopes, $apiAuthId;
    if (!preg_match('/^nbp_[a-f0-9]{48}$/', $plain)) { return false; }
    $wanted = hash('sha256', $plain);
    $matched = with_api_tokens_lock(static function () use ($wanted): array {
        $tokens = api_tokens_load();
        foreach ($tokens as &$token) {
            if (empty($token['hash']) || !hash_equals((string) $token['hash'], $wanted)) { continue; }
            $expiry = strtotime((string) ($token['expires_at'] ?? ''));
            if ($expiry === false || $expiry < time()) {
                unset($token);
                return ['ok' => false];
            }
            $allowedIps = is_array($token['allowed_ips'] ?? null) ? $token['allowed_ips'] : [];
            if ($allowedIps && !in_array(client_ip(), $allowedIps, true)) {
                unset($token);
                return ['ok'=>false];
            }
            $record = $token;
            $last = isset($token['last_used_at']) ? strtotime((string) $token['last_used_at']) : false;
            if ($last === false || time() - $last > 60) {
                $token['last_used_at'] = date('c');
                api_tokens_save($tokens);
            }
            unset($token);
            return ['ok' => true, 'record' => $record];
        }
        unset($token);
        return ['ok' => false];
    });
    if (empty($matched['ok'])) { return false; }
    $token = $matched['record'];
    $apiAuthLabel = (string) ($token['label'] ?? 'token');
    $apiAuthId = (string) ($token['id'] ?? '');
    $apiAuthRole = isset(panel_roles()[(string) ($token['role'] ?? '')]) ? (string) $token['role'] : 'auditor';
    $apiAuthScopes = is_array($token['scopes'] ?? null) ? $token['scopes'] : [];
    return true;
}

function api_token_scope_allows(string $endpoint, string $method): bool
{
    global $apiAuthScopes;
    $scopes = is_array($apiAuthScopes ?? null) ? $apiAuthScopes : [];
    return in_array('*', $scopes, true)
        || in_array(strtolower($method) . ':' . $endpoint, $scopes, true)
        || in_array($endpoint . ':*', $scopes, true);
}

function is_api_token_authenticated(): bool
{
    global $apiAuthLabel;
    return is_string($apiAuthLabel ?? null) && $apiAuthLabel !== '';
}
