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
    return array_intersect_key($token, array_flip(['id', 'label', 'role', 'scopes', 'allowed_ips', 'created_at', 'expires_at', 'last_used_at', 'owner_id', 'owner']));
}

/** Role strength, so a token can never act above the person who owns it. */
function api_role_rank(string $role): int
{
    return ['auditor' => 0, 'developer' => 1, 'operator' => 2, 'admin' => 3][$role] ?? 0;
}

/** The signed-in person as a token owner, or null for bearer/CLI contexts. */
function api_current_owner(): ?array
{
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($uid <= 0) { return null; }
    foreach (panel_users() as $user) {
        if ((int) ($user['id'] ?? 0) === $uid) { return ['id' => $uid, 'username' => (string) $user['username'], 'role' => (string) ($user['role'] ?? 'auditor')]; }
    }
    return null;
}

/** Tokens owned by one person (newest first). */
function api_tokens_for(int $ownerId): array
{
    $mine = array_values(array_filter(api_tokens_load(), static fn($t) => (int) ($t['owner_id'] ?? 0) === $ownerId));
    return array_reverse(array_map('api_token_public', $mine));
}

function api_token_generate(string $label, string $role = 'auditor', array $scopes = ['get:health'], int $ttlDays = 30, array $allowedIps = [], ?array $owner = null): array
{
    $owner = $owner ?? api_current_owner();
    // Every token has an owner, except ones made by local maintenance code:
    // an ownerless token has no role ceiling and outlives every account.
    if ($owner === null && PHP_SAPI !== 'cli') {
        return ['ok' => false, 'error' => 'Tokens can only be created from a signed-in browser session.'];
    }
    if ($owner !== null && api_role_rank($role) > api_role_rank($owner['role'])) {
        return ['ok' => false, 'error' => 'A token cannot have more access than your own role.'];
    }
    $label = trim($label);
    if ($label === '' || strlen($label) > 80) { return ['ok' => false, 'error' => 'Label must be 1–80 characters.']; }
    if (!isset(panel_roles()[$role]) || (panel_roles()[$role]['assignable'] ?? true) === false) { return ['ok' => false, 'error' => 'Invalid token role.']; }
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
    ] + ($owner !== null ? ['owner_id' => (int) $owner['id'], 'owner' => (string) $owner['username']] : []);
    $saved = with_api_tokens_lock(static function () use ($record): array {
        $tokens = api_tokens_load();
        $tokens[] = $record;
        return ['ok' => api_tokens_save($tokens)];
    });
    if (empty($saved['ok'])) { return ['ok' => false, 'error' => 'Could not save token.']; }
    audit('api_token.generate', $label);
    return ['ok' => true, 'token' => $plain, 'record' => api_token_public($record)];
}

function api_token_revoke(string $id, ?int $ownerId = null): array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) { return ['ok' => false, 'error' => 'Invalid token.']; }
    $result = with_api_tokens_lock(static function () use ($id, $ownerId): array {
        $tokens = api_tokens_load();
        $next = array_values(array_filter($tokens, fn($token) => ($token['id'] ?? '') !== $id
            || ($ownerId !== null && (int) ($token['owner_id'] ?? 0) !== $ownerId)));
        if (count($next) === count($tokens)) { return ['ok' => false, 'error' => 'Token not found.']; }
        return api_tokens_save($next) ? ['ok' => true] : ['ok' => false, 'error' => 'Could not revoke token.'];
    });
    if (!empty($result['ok'])) { audit('api_token.revoke', $id); }
    return $result;
}

/**
 * Revoke every token a person owns. Called when their credentials change
 * (password, two-factor reset, account recovery): a token minted from a
 * hijacked session must not outlive the fix.
 */
function api_tokens_revoke_owner(int $ownerId, string $reason): int
{
    $removed = 0;
    with_api_tokens_lock(static function () use ($ownerId, &$removed): array {
        $tokens = api_tokens_load();
        $kept = array_values(array_filter($tokens, static fn($t) => (int) ($t['owner_id'] ?? 0) !== $ownerId));
        $removed = count($tokens) - count($kept);
        return ['ok' => $removed === 0 || api_tokens_save($kept)];
    });
    if ($removed > 0) { audit('api_token.revoke_all', 'owner ' . $ownerId . ': ' . $removed . ' token(s), ' . $reason); }
    return $removed;
}

function api_token_authenticate(string $plain): bool
{
    global $apiAuthLabel, $apiAuthRole, $apiAuthScopes, $apiAuthId;
    if (!preg_match('/^nbp_[a-f0-9]{48}$/', $plain)) { return false; }
    $wanted = hash('sha256', $plain);
    // Verify without the lock; only take it (and rewrite the store) when the
    // once-a-minute last-used timestamp is due. Every bearer request used to
    // serialise on the lock.
    $matched = ['ok' => false];
    foreach (api_tokens_load() as $token) {
        if (empty($token['hash']) || !hash_equals((string) $token['hash'], $wanted)) { continue; }
        $expiry = strtotime((string) ($token['expires_at'] ?? ''));
        $allowedIps = is_array($token['allowed_ips'] ?? null) ? $token['allowed_ips'] : [];
        if ($expiry === false || $expiry < time() || ($allowedIps && !in_array(client_ip(), $allowedIps, true))) { break; }
        $matched = ['ok' => true, 'record' => $token];
        $last = isset($token['last_used_at']) ? strtotime((string) $token['last_used_at']) : false;
        if ($last === false || time() - $last > 60) {
            with_api_tokens_lock(static function () use ($wanted): array {
                $tokens = api_tokens_load();
                foreach ($tokens as &$candidate) {
                    if (!empty($candidate['hash']) && hash_equals((string) $candidate['hash'], $wanted)) {
                        $candidate['last_used_at'] = date('c');
                    }
                }
                unset($candidate);
                return ['ok' => api_tokens_save($tokens)];
            });
        }
        break;
    }
    if (empty($matched['ok'])) { return false; }
    $token = $matched['record'];
    $role = isset(panel_roles()[(string) ($token['role'] ?? '')]) ? (string) $token['role'] : 'auditor';
    // A person's tokens stop working when they are disabled or deleted, and
    // never act above their current role (a demoted admin's token is demoted too).
    if (isset($token['owner_id'])) {
        $owner = null;
        foreach (panel_users() as $user) { if ((int) ($user['id'] ?? 0) === (int) $token['owner_id']) { $owner = $user; break; } }
        if ($owner === null || empty($owner['enabled'])) { return false; }
        if (api_role_rank((string) ($owner['role'] ?? 'auditor')) < api_role_rank($role)) { $role = (string) ($owner['role'] ?? 'auditor'); }
    }
    $apiAuthLabel = (string) ($token['label'] ?? 'token') . (isset($token['owner']) ? ' (' . $token['owner'] . ')' : '');
    $apiAuthId = (string) ($token['id'] ?? '');
    $apiAuthRole = $role;
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
