<?php
/**
 * Active-session registry: lets a person see where they are signed in and
 * sign out other devices.
 *
 * Each signed-in browser session carries a random, stable `sid_token` (it
 * survives session-ID rotation). The registry maps tokens to their account,
 * address, browser and activity. A session whose token disappears from the
 * registry is signed out on its next request — that is how "Sign out" for
 * another device takes effect.
 */

/** Seconds between last-seen writes for the same session. */
const SESSION_TOUCH_INTERVAL = 60;

function session_registry_file(): string
{
    return DATA_DIR . '/session-registry.json';
}

function session_registry_read(): array
{
    $data = @json_decode((string) @file_get_contents(session_registry_file()), true);
    return is_array($data) && is_array($data['sessions'] ?? null) ? $data['sessions'] : [];
}

/** Read-modify-write the registry under an exclusive lock, pruning expired entries. */
function session_registry_mutate(callable $mutator)
{
    global $config;
    $path = session_registry_file();
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) { fclose($lock); }
        return null;
    }
    try {
        $sessions = session_registry_read();
        $now = time();
        $idle = max(60, (int) ($config['session_timeout'] ?? 1800));
        $absolute = max($idle, (int) ($config['session_absolute_timeout'] ?? 43200));
        foreach ($sessions as $token => $entry) {
            if (!is_array($entry) || $now - (int) ($entry['last_seen'] ?? 0) > $idle + 120
                || $now - (int) ($entry['created'] ?? 0) > $absolute + 120) {
                unset($sessions[$token]);
            }
        }
        $result = $mutator($sessions);
        write_json_file($path, ['version' => 1, 'sessions' => $sessions]);
        return $result;
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function session_client_agent(): string
{
    return substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '', 0, 300);
}

/** Record the current (just signed-in) session. */
function session_registry_register(): void
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['sid_token'] = $token;
    $entry = [
        'uid' => (int) ($_SESSION['uid'] ?? 0),
        'version' => (int) ($_SESSION['session_version'] ?? 1),
        'created' => (int) ($_SESSION['created_at'] ?? time()),
        'last_seen' => time(),
        'ip' => client_ip(),
        'agent' => session_client_agent(),
        'mfa' => !empty($_SESSION['mfa_verified']),
    ];
    session_registry_mutate(static function (array &$sessions) use ($token, $entry): void { $sessions[$token] = $entry; });
}

/**
 * Called on every authenticated request. Returns false when this session was
 * signed out from elsewhere and must end now.
 */
function session_registry_check(): bool
{
    if (empty($_SESSION['uid'])) { return true; }
    $token = (string) ($_SESSION['sid_token'] ?? '');
    if ($token === '') {
        // Sessions created before the registry existed join it on first use.
        session_registry_register();
        return true;
    }
    $entry = session_registry_read()[$token] ?? null;
    if (!is_array($entry) || (int) ($entry['uid'] ?? 0) !== (int) $_SESSION['uid']) { return false; }
    $ip = client_ip();
    if (time() - (int) ($entry['last_seen'] ?? 0) >= SESSION_TOUCH_INTERVAL || ($entry['ip'] ?? '') !== $ip) {
        $alive = session_registry_mutate(static function (array &$sessions) use ($token, $ip): bool {
            if (!isset($sessions[$token])) { return false; }
            $sessions[$token]['last_seen'] = time();
            $sessions[$token]['ip'] = $ip;
            $sessions[$token]['agent'] = session_client_agent();
            $sessions[$token]['version'] = (int) ($_SESSION['session_version'] ?? 1);
            return true;
        });
        return $alive !== false;
    }
    return true;
}

function session_registry_forget(string $token): void
{
    if ($token === '') { return; }
    session_registry_mutate(static function (array &$sessions) use ($token): void { unset($sessions[$token]); });
}

/** Sessions of one account, newest activity first, for the account page. */
function session_registry_list(int $uid, int $currentVersion, string $currentToken): array
{
    $rows = [];
    foreach (session_registry_read() as $token => $entry) {
        if (!is_array($entry) || (int) ($entry['uid'] ?? 0) !== $uid) { continue; }
        $isCurrent = $currentToken !== '' && hash_equals($currentToken, (string) $token);
        // Sessions from before a password change are already signed out.
        if (!$isCurrent && (int) ($entry['version'] ?? 1) !== $currentVersion) { continue; }
        $device = session_describe_agent((string) ($entry['agent'] ?? ''));
        $rows[] = [
            'id' => substr(hash('sha256', $token), 0, 20),
            'current' => $isCurrent,
            'ip' => (string) ($entry['ip'] ?? ''),
            'browser' => $device['browser'],
            'os' => $device['os'],
            'icon' => $device['icon'],
            'created' => (int) ($entry['created'] ?? 0),
            'last_seen' => (int) ($entry['last_seen'] ?? 0),
        ];
    }
    usort($rows, static fn($a, $b) => [$b['current'], $b['last_seen']] <=> [$a['current'], $a['last_seen']]);
    return $rows;
}

/**
 * Sign out one of the account's other sessions (by public id), or every
 * other session when $publicId is null. Returns how many were ended.
 */
function session_registry_revoke(int $uid, string $currentToken, ?string $publicId = null): int
{
    $count = (int) session_registry_mutate(static function (array &$sessions) use ($uid, $currentToken, $publicId): int {
        $ended = 0;
        foreach ($sessions as $token => $entry) {
            if ((int) ($entry['uid'] ?? 0) !== $uid || hash_equals($currentToken, (string) $token)) { continue; }
            if ($publicId !== null && !hash_equals(substr(hash('sha256', (string) $token), 0, 20), $publicId)) { continue; }
            unset($sessions[$token]);
            $ended++;
        }
        return $ended;
    });
    if ($count > 0) { audit('session.revoke', $publicId === null ? 'all other sessions (' . $count . ')' : 'session ' . $publicId); }
    return $count;
}

/** "Chrome on macOS" from a User-Agent string. */
function session_describe_agent(string $agent): array
{
    $browser = 'Unknown browser';
    foreach ([
        '/Edg(e|A|iOS)?\//' => 'Edge', '/OPR\/|Opera/' => 'Opera', '/Firefox\/|FxiOS/' => 'Firefox',
        '/SamsungBrowser/' => 'Samsung Internet', '/Chrome\/|CriOS/' => 'Chrome', '/Safari\//' => 'Safari',
        '/^curl\//' => 'curl', '/^Wget/' => 'Wget',
    ] as $pattern => $name) {
        if (preg_match($pattern, $agent)) { $browser = $name; break; }
    }
    $os = '';
    foreach ([
        '/iPhone|iPad|iPod/' => 'iOS', '/Android/' => 'Android', '/CrOS/' => 'ChromeOS', '/Mac OS X|Macintosh/' => 'macOS',
        '/Windows/' => 'Windows', '/Linux/' => 'Linux',
    ] as $pattern => $name) {
        if (preg_match($pattern, $agent)) { $os = $name; break; }
    }
    $icon = in_array($os, ['iOS', 'Android'], true) ? 'smartphone' : ($os === '' ? 'globe' : 'monitor');
    return ['browser' => $browser, 'os' => $os, 'icon' => $icon];
}
