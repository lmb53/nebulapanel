<?php
/**
 * Users module: read-only inspection of local system accounts.
 * Parses /etc/passwd and resolves group membership via `id`.
 * Degrades gracefully (returns [] ) when data is unavailable.
 */

/**
 * Parse /etc/passwd into a list of user records.
 * Each record: ['name','uid'=>int,'gid'=>int,'home','shell','human'=>bool].
 * "human" accounts are uid >= 1000 && uid < 65534.
 * Human users are sorted first, then by uid ascending. Returns [] if unreadable.
 */
function system_users(): array
{
    if (!is_readable('/etc/passwd')) {
        return [];
    }
    $lines = @file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }
    $users = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $f = explode(':', $line);
        if (count($f) < 7) {
            continue;
        }
        $uid = (int) $f[2];
        $users[] = [
            'name'  => $f[0],
            'uid'   => $uid,
            'gid'   => (int) $f[3],
            'home'  => $f[5],
            'shell' => $f[6],
            'human' => ($uid >= 1000 && $uid < 65534),
        ];
    }
    usort($users, function ($a, $b) {
        if ($a['human'] !== $b['human']) {
            return $a['human'] ? -1 : 1;
        }
        return $a['uid'] <=> $b['uid'];
    });
    return $users;
}

/**
 * Resolve the group names a user belongs to via `id -nG`.
 * Returns a trimmed array of group names, or [] on failure.
 */
function user_groups(string $user): array
{
    [$code, $out] = run_cmd('id -nG ' . escapeshellarg($user));
    if ($code !== 0) {
        return [];
    }
    $out = trim($out);
    if ($out === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', preg_split('/\s+/', $out)), 'strlen'));
}
