<?php
/**
 * Bootstrap — loaded by index.php on every request before routing.
 * Sets up config, error handling, secure sessions, and pulls in helpers.
 */

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');

$config = require APP_ROOT . '/config.php';

// Runtime overrides written by the Settings page (panel_name, session_timeout).
$__overrides = @json_decode((string) @file_get_contents(APP_ROOT . '/data/settings.json'), true);
if (is_array($__overrides)) {
    $config = array_merge($config, $__overrides);
}

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

// Baseline browser protections for every dynamic response.
$cspNonce = base64_encode(random_bytes(18));
define('CSP_NONCE', $cspNonce);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
header('Cache-Control: no-store');

// --- Secure session ---------------------------------------------------------
// Scope the cookie to the panel's URL path. Avoid filesystem dirname(), whose
// separator is OS-dependent and can produce an invalid `\/` cookie path.
$scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
$lastSlash = strrpos($scriptPath, '/');
$scriptDir = $lastSlash === false ? '' : substr($scriptPath, 0, $lastSlash);
$cookiePath = $scriptDir === '' ? '/' : rtrim($scriptDir, '/') . '/';
$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$trustProxy = in_array($remote, (array) ($config['trusted_proxies'] ?? []), true);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['SERVER_PORT'] ?? null) == 443)
      || ($trustProxy && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
if ($https) { header('Strict-Transport-Security: max-age=31536000'); }

session_name('nebula_sess');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $cookiePath,
    'httponly' => true,
    'secure'   => $https,
    'samesite' => 'Strict',
]);
session_start();

require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/sys.php';
require APP_ROOT . '/lib/modules.php';

// Account changes take effect immediately for every active session.
if (!empty($_SESSION['uid'])) {
    refresh_session_identity();
}

// Enforce idle timeout for logged-in sessions.
if (!empty($_SESSION['uid'])) {
    $timeout = (int) ($config['session_timeout'] ?? 1800);
    if (isset($_SESSION['last_seen']) && (time() - $_SESSION['last_seen']) > $timeout) {
        logout_user();
    } else {
        $_SESSION['last_seen'] = time();
    }
}
