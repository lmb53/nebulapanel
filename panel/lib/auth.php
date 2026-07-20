<?php
/**
 * Authentication: first-run setup, login, logout, guards.
 * The admin account is stored (hashed) in data/admin.json.
 */

function admin_file(): string
{
    return DATA_DIR . '/admin.json';
}

/** Has an admin account been created yet? */
function is_setup_complete(): bool
{
    return is_file(admin_file());
}

/** Create the admin account (first-run only). */
function create_admin(string $username, string $password): array
{
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
        return ['ok' => false, 'error' => 'Username must be 3–64 letters, numbers, dots, dashes, or underscores.'];
    }
    if (strlen($password) < 12 || strlen($password) > 1024) {
        return ['ok' => false, 'error' => 'Password must be between 12 and 1024 characters.'];
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        return ['ok' => false, 'error' => 'Could not hash the password.'];
    }
    $lock = @fopen(DATA_DIR . '/setup.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) { @fclose($lock); }
        return ['ok' => false, 'error' => 'Could not lock the setup process. Check data directory permissions.'];
    }
    if (is_setup_complete()) {
        @flock($lock, LOCK_UN);
        fclose($lock);
        return ['ok' => false, 'error' => 'The administrator account has already been created.'];
    }
    $data = [
        'username' => $username,
        'hash'     => $hash,
        'created'  => date('c'),
    ];
    $ok = write_json_file(admin_file(), $data);
    @flock($lock, LOCK_UN);
    fclose($lock);
    if (!$ok) {
        return ['ok' => false, 'error' => 'Could not write ' . admin_file() . ' — check permissions.'];
    }
    return ['ok' => true];
}

function login_attempts_file(): string
{
    return DATA_DIR . '/login_attempts.json';
}

/** Return seconds until another login is allowed (0 means allowed). */
function login_retry_after(?string $ip = null): int
{
    global $config;
    $ip = $ip ?? client_ip();
    $max = max(1, (int) ($config['login_max_attempts'] ?? 5));
    $window = max(60, (int) ($config['login_window'] ?? 600));
    $data = @json_decode((string) @file_get_contents(login_attempts_file()), true);
    $attempts = is_array($data) && isset($data[hash('sha256', $ip)]) && is_array($data[hash('sha256', $ip)])
        ? $data[hash('sha256', $ip)] : [];
    $cutoff = time() - $window;
    $attempts = array_values(array_filter($attempts, fn($ts) => (int) $ts > $cutoff));
    if (count($attempts) < $max) {
        return 0;
    }
    return max(1, ((int) $attempts[0] + $window) - time());
}

/** Atomically reserve one attempt, returning retry seconds when blocked. */
function reserve_login_attempt(?string $ip = null): int
{
    global $config;
    $ip = $ip ?? client_ip();
    $key = hash('sha256', $ip);
    $max = max(1, (int) ($config['login_max_attempts'] ?? 5));
    $window = max(60, (int) ($config['login_window'] ?? 600));
    $path = login_attempts_file();
    $handle = @fopen($path, 'c+');
    if ($handle === false || !@flock($handle, LOCK_EX)) {
        if (is_resource($handle)) { @fclose($handle); }
        return $window; // fail closed if the throttle store is unavailable
    }
    $data = json_decode((string) stream_get_contents($handle), true);
    $data = is_array($data) ? $data : [];
    $cutoff = time() - $window;
    foreach ($data as $k => $attempts) {
        $data[$k] = is_array($attempts)
            ? array_values(array_filter($attempts, fn($ts) => (int) $ts > $cutoff)) : [];
        if (!$data[$k]) { unset($data[$k]); }
    }
    $attempts = $data[$key] ?? [];
    $retry = count($attempts) >= $max ? max(1, ((int) $attempts[0] + $window) - time()) : 0;
    if ($retry === 0) {
        $data[$key][] = time();
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($data));
    fflush($handle);
    @flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($path, 0600);
    return $retry;
}

function record_login_attempt(bool $success, ?string $ip = null): void
{
    global $config;
    $ip = $ip ?? client_ip();
    $key = hash('sha256', $ip);
    $window = max(60, (int) ($config['login_window'] ?? 600));
    $path = login_attempts_file();
    $handle = @fopen($path, 'c+');
    if ($handle === false || !@flock($handle, LOCK_EX)) {
        if (is_resource($handle)) { @fclose($handle); }
        return;
    }
    $raw = stream_get_contents($handle);
    $data = json_decode((string) $raw, true);
    $data = is_array($data) ? $data : [];
    $cutoff = time() - $window;
    foreach ($data as $k => $attempts) {
        if (!is_array($attempts)) { unset($data[$k]); continue; }
        $data[$k] = array_values(array_filter($attempts, fn($ts) => (int) $ts > $cutoff));
        if (!$data[$k]) { unset($data[$k]); }
    }
    if ($success) {
        unset($data[$key]);
    } else {
        $data[$key][] = time();
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($data));
    fflush($handle);
    @flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($path, 0600);
}

/** Attempt a login. Returns true on success. */
function attempt_login(string $username, string $password): bool
{
    if (!is_setup_complete()) {
        return false;
    }
    $admin = json_decode((string) @file_get_contents(admin_file()), true);
    if (!is_array($admin) || empty($admin['hash'])) {
        return false;
    }
    $userOk = hash_equals((string) ($admin['username'] ?? ''), $username);
    $passwordOk = password_verify($password, (string) $admin['hash']);
    if (!$userOk || !$passwordOk) {
        // Constant-ish delay to blunt brute force / timing.
        usleep(300000);
        return false;
    }
    if (password_needs_rehash($admin['hash'], PASSWORD_DEFAULT)) {
        $admin['hash'] = password_hash($password, PASSWORD_DEFAULT);
        write_json_file(admin_file(), $admin);
    }
    session_regenerate_id(true);
    unset($_SESSION['csrf']);
    $_SESSION['uid'] = 1;
    $_SESSION['username'] = $admin['username'];
    $_SESSION['last_seen'] = time();
    record_login_attempt(true);
    audit('login', 'success');
    return true;
}

function logout_user(): void
{
    if (!empty($_SESSION['username'])) {
        audit('logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function current_user(): ?string
{
    global $apiAuthLabel;
    return $_SESSION['username'] ?? (isset($apiAuthLabel) ? 'api:' . $apiAuthLabel : null);
}

function is_logged_in(): bool
{
    return !empty($_SESSION['uid']);
}

/** Guard: require an authenticated session or bounce to login/setup. */
function require_auth(): void
{
    if (!is_setup_complete()) {
        redirect('setup');
    }
    if (!is_logged_in() && !is_api_token_authenticated()) {
        redirect('login');
    }
}
