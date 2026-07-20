<?php
require_once APP_ROOT . '/lib/mod_users.php';

function sshkey_users(): array
{
    return array_values(array_filter(system_users(), fn($u) => !empty($u['human']) && !in_array($u['shell'], ['/usr/sbin/nologin', '/bin/false'], true)));
}

function sshkey_user_allowed(string $user): bool
{
    return in_array($user, array_column(sshkey_users(), 'name'), true);
}

function sshkey_list(string $user): array
{
    if (!sshkey_user_allowed($user)) { return []; }
    [$code, $out] = helper_cmd('ssh-keys-list ' . escapeshellarg($user));
    if ($code !== 0 || $out === '') { return []; }
    $rows = [];
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        [$number, $meta] = array_pad(explode("\t", $line, 2), 2, '');
        if (ctype_digit($number) && $meta !== '') { $rows[] = ['number' => (int) $number, 'meta' => $meta]; }
    }
    return $rows;
}

function sshkey_add(string $user, string $key): array
{
    if (!sshkey_user_allowed($user)) { return ['ok' => false, 'error' => 'User is not allowed.']; }
    $key = trim($key);
    if (strlen($key) < 40 || strlen($key) > 16384 || strpos($key, "\n") !== false) { return ['ok' => false, 'error' => 'Enter one valid OpenSSH public key.']; }
    [$code, $out] = helper_cmd('ssh-key-add ' . escapeshellarg($user) . ' ' . escapeshellarg(base64_encode($key)));
    audit('sshkey.add', $user);
    return $code === 0 ? ['ok' => true] : ['ok' => false, 'error' => trim($out) ?: 'Could not add key.'];
}

function sshkey_delete(string $user, int $number): array
{
    if (!sshkey_user_allowed($user) || $number < 1) { return ['ok' => false, 'error' => 'Invalid key selection.']; }
    [$code, $out] = helper_cmd('ssh-key-delete ' . escapeshellarg($user) . ' ' . $number);
    audit('sshkey.delete', $user . ' #' . $number);
    return $code === 0 ? ['ok' => true] : ['ok' => false, 'error' => trim($out) ?: 'Could not remove key.'];
}
