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

function api_token_public(array $token): array
{
    return array_intersect_key($token, array_flip(['id', 'label', 'created_at', 'last_used_at']));
}

function api_token_generate(string $label): array
{
    $label = trim($label);
    if ($label === '' || strlen($label) > 80) { return ['ok' => false, 'error' => 'Label must be 1–80 characters.']; }
    $plain = 'nbp_' . bin2hex(random_bytes(24));
    $tokens = api_tokens_load();
    $record = ['id' => bin2hex(random_bytes(8)), 'label' => $label, 'hash' => hash('sha256', $plain), 'created_at' => date('c'), 'last_used_at' => null];
    $tokens[] = $record;
    if (!api_tokens_save($tokens)) { return ['ok' => false, 'error' => 'Could not save token.']; }
    audit('api_token.generate', $label);
    return ['ok' => true, 'token' => $plain, 'record' => api_token_public($record)];
}

function api_token_revoke(string $id): array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) { return ['ok' => false, 'error' => 'Invalid token.']; }
    $tokens = api_tokens_load();
    $next = array_values(array_filter($tokens, fn($token) => ($token['id'] ?? '') !== $id));
    if (count($next) === count($tokens)) { return ['ok' => false, 'error' => 'Token not found.']; }
    $ok = api_tokens_save($next);
    if ($ok) { audit('api_token.revoke', $id); }
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Could not revoke token.'];
}

function api_token_authenticate(string $plain): bool
{
    global $apiAuthLabel;
    if (!preg_match('/^nbp_[a-f0-9]{48}$/', $plain)) { return false; }
    $wanted = hash('sha256', $plain);
    $tokens = api_tokens_load();
    foreach ($tokens as &$token) {
        if (!empty($token['hash']) && hash_equals((string) $token['hash'], $wanted)) {
            $apiAuthLabel = (string) ($token['label'] ?? 'token');
            $last = isset($token['last_used_at']) ? strtotime((string) $token['last_used_at']) : false;
            if ($last === false || time() - $last > 60) {
                $token['last_used_at'] = date('c');
                api_tokens_save($tokens);
            }
            unset($token);
            return true;
        }
    }
    unset($token);
    return false;
}

function is_api_token_authenticated(): bool
{
    global $apiAuthLabel;
    return is_string($apiAuthLabel ?? null) && $apiAuthLabel !== '';
}
