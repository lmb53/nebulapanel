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
    if (strlen($username) < 3) {
        return ['ok' => false, 'error' => 'Username must be at least 3 characters.'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }
    $data = [
        'username' => $username,
        'hash'     => password_hash($password, PASSWORD_DEFAULT),
        'created'  => date('c'),
    ];
    $ok = @file_put_contents(admin_file(), json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    if ($ok === false) {
        return ['ok' => false, 'error' => 'Could not write ' . admin_file() . ' — check permissions.'];
    }
    @chmod(admin_file(), 0600);
    return ['ok' => true];
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
    if (!hash_equals($admin['username'], $username) || !password_verify($password, $admin['hash'])) {
        // Constant-ish delay to blunt brute force / timing.
        usleep(300000);
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = 1;
    $_SESSION['username'] = $admin['username'];
    $_SESSION['last_seen'] = time();
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
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function current_user(): ?string
{
    return $_SESSION['username'] ?? null;
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
    if (!is_logged_in()) {
        redirect('login');
    }
}
