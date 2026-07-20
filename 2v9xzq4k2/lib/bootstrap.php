<?php
/**
 * Bootstrap — loaded by index.php on every request before routing.
 * Sets up config, error handling, secure sessions, and pulls in helpers.
 */

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');

$config = require APP_ROOT . '/config.php';

if (!empty($config['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

// Ensure the data dir exists and is writable (setup + audit log live here).
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0700, true);
}

// --- Secure session ---------------------------------------------------------
// Scope the cookie to the panel's own path so the secret prefix is required.
$cookiePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
if ($cookiePath === '') {
    $cookiePath = '/';
}
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['SERVER_PORT'] ?? null) == 443)
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('nebula_sess');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $cookiePath,
    'httponly' => true,
    'secure'   => $https,
    'samesite' => 'Lax',
]);
session_start();

require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/sys.php';

// Enforce idle timeout for logged-in sessions.
if (!empty($_SESSION['uid'])) {
    $timeout = (int) ($config['session_timeout'] ?? 1800);
    if (isset($_SESSION['last_seen']) && (time() - $_SESSION['last_seen']) > $timeout) {
        logout_user();
    } else {
        $_SESSION['last_seen'] = time();
    }
}
