<?php
/**
 * Small helpers: URL building, escaping, JSON, CSRF, redirects.
 */

/** Base URL of the panel (the secret directory), e.g. /2v9xzq4k2 */
function base_url(): string
{
    static $base = null;
    if ($base === null) {
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $base = $dir === '' ? '' : $dir;
    }
    return $base;
}

/** Build an in-app URL for a route, e.g. url('services') => /2v9xzq4k2/?r=services */
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
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
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

/** Append an entry to the audit log. */
function audit(string $action, string $detail = ''): void
{
    $user = $_SESSION['username'] ?? 'anon';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    $line = sprintf("[%s] %s (%s) %s %s\n", date('c'), $user, $ip, $action, $detail);
    @file_put_contents(DATA_DIR . '/audit.log', $line, FILE_APPEND | LOCK_EX);
}
