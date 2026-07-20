<?php
/**
 * Small helpers: URL building, escaping, JSON, CSRF, redirects.
 */

/** Base URL of the installed panel, e.g. /a1b2c3d4e5f6 */
function base_url(): string
{
    static $base = null;
    if ($base === null) {
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $base = $dir === '' ? '' : $dir;
    }
    return $base;
}

/** Build an in-app URL, e.g. url('services') => /a1b2c3d4e5f6/?r=services */
function url(string $route = 'dashboard', array $params = []): string
{
    $params = array_merge(['r' => $route], $params);
    return base_url() . '/?' . http_build_query($params);
}

/** Build a URL to a static asset. */
function asset(string $path): string
{
    return base_url() . '/assets/' . ltrim($path, '/');
}

/** HTML-escape. */
function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Send a JSON response and stop. */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Safely replace a JSON file in-place, never exposing a partial write. */
function write_json_file(string $path, array $data, int $mode = 0600): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        return false;
    }
    $tmp = @tempnam($dir, '.nebula-');
    if ($tmp === false) {
        return false;
    }
    $ok = @file_put_contents($tmp, $json . "\n", LOCK_EX) !== false;
    if ($ok) {
        @chmod($tmp, $mode);
        $ok = @rename($tmp, $path);
        // Windows cannot atomically replace an existing file with rename().
        // Keep a locked overwrite fallback for local development there.
        if (!$ok && PHP_OS_FAMILY === 'Windows') {
            $ok = @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
            if ($ok) { @chmod($path, $mode); }
        }
    }
    if (is_file($tmp)) {
        @unlink($tmp);
    }
    return $ok;
}

/** Redirect within the app and stop. */
function redirect(string $route, array $params = []): void
{
    header('Location: ' . url($route, $params));
    exit;
}

/** Current CSRF token (creates one if needed). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden input for forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Verify CSRF for a POST request. Accepts the token from the _csrf field or
 * the X-CSRF-Token header (used by fetch() calls). Aborts on mismatch.
 */
function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $sent)) {
        if (is_json_request()) {
            json_out(['ok' => false, 'error' => 'Invalid CSRF token'], 419);
        }
        http_response_code(419);
        exit('Invalid CSRF token');
    }
}

function is_json_request(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
}

/** Human-readable byte size. */
function human_bytes($bytes, int $decimals = 1): string
{
    $bytes = (float) $bytes;
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = (int) floor(log($bytes, 1024));
    $i = max(0, min($i, count($units) - 1));
    return round($bytes / (1024 ** $i), $decimals) . ' ' . $units[$i];
}

/**
 * Render a view. With $withLayout, the view's output is wrapped in the
 * sidebar/topbar shell (layout.php). $data is extracted into scope.
 */
function render(string $view, array $data = [], bool $withLayout = true): void
{
    global $config;
    extract($data, EXTR_SKIP);
    $viewFile = APP_ROOT . '/views/' . $view . '.php';
    if (!is_file($viewFile)) {
        http_response_code(500);
        exit('View not found: ' . e($view));
    }
    if ($withLayout) {
        $__view = $viewFile;
        $__active = $_GET['r'] ?? 'dashboard';
        require APP_ROOT . '/views/layout.php';
    } else {
        require $viewFile;
    }
}

/** Read a JSON request body, falling back to POST fields. */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $j = json_decode((string) $raw, true);
    return is_array($j) ? $j : $_POST;
}

/** Guard: only allow POST for a write endpoint, else 405 JSON. */
function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_out(['ok' => false, 'error' => 'POST required'], 405);
    }
}

/** Append an entry to the audit log. */
function audit(string $action, string $detail = ''): void
{
    $clean = static function (string $value, int $max): string {
        $value = preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $value) ?? '';
        return substr(trim($value), 0, $max);
    };
    $user = $clean((string) ($_SESSION['username'] ?? 'anon'), 100);
    $ip = $clean(client_ip(), 64);
    $action = $clean($action, 120);
    $detail = $clean($detail, 2000);
    $line = sprintf("[%s] %s (%s) %s %s\n", date('c'), $user, $ip, $action, $detail);
    @file_put_contents(DATA_DIR . '/audit.log', $line, FILE_APPEND | LOCK_EX);
}

/** Client address, honoring forwarding headers only from configured proxies. */
function client_ip(): string
{
    global $config;
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '-');
    if (in_array($remote, (array) ($config['trusted_proxies'] ?? []), true)) {
        $forwarded = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        $candidate = trim($forwarded[0] ?? '');
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    return $remote;
}
