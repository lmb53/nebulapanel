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
    $path = ltrim($path, '/');
    $file = APP_ROOT . '/assets/' . $path;
    return base_url() . '/assets/' . $path . (is_file($file) ? '?v=' . (int) filemtime($file) : '');
}

function csp_nonce(): string
{
    return defined('CSP_NONCE') ? CSP_NONCE : '';
}

/**
 * Blocking <head> snippet that applies the stored light/dark preference before
 * the browser paints. Without it the dark defaults in :root render first and
 * light-mode users see a dark flash until app.js runs at the end of <body>.
 * Must be emitted before the stylesheet link so the class is on <html> for the
 * first style resolution.
 */
function theme_boot_script(): string
{
    return '<script>(function(){try{if(localStorage.getItem("nebula-theme")==="light")'
        . '{document.documentElement.classList.add("light");}}catch(e){}})();</script>';
}

/** HTML-escape. */
function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Validate an ASCII DNS name label-by-label (IDNA must be supplied as A-labels). */
function domain_name_ok(string $domain, bool $requireDot = true, bool $allowWildcard = false): bool
{
    $domain = rtrim($domain, '.');
    if ($allowWildcard && str_starts_with($domain, '*.')) $domain = substr($domain, 2);
    if ($domain === '' || strlen($domain) > 253 || str_contains($domain, '..')) return false;
    if ($requireDot && !str_contains($domain, '.')) return false;
    foreach (explode('.', $domain) as $label) {
        if (strlen($label) < 1 || strlen($label) > 63
            || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/', $label)) {
            return false;
        }
    }
    return true;
}

/** Send a JSON response and stop. */
function json_out($data, int $code = 200): void
{
    if ($code === 400 && is_array($data)) {
        $message = strtolower((string) ($data['error'] ?? ''));
        if (!empty($data['conflict'])) $code = 409;
        elseif (str_contains($message,'not found')) $code = 404;
        elseif (str_contains($message,'not installed') || str_contains($message,'not available')
            || str_contains($message,'helper required') || str_contains($message,'unavailable')) $code = 503;
        elseif (preg_match('/^(invalid|enter |must |unknown action|unsupported)/',$message)) $code = 422;
        elseif (str_contains($message,'too many')) $code = 429;
    }
    if ($code >= 400 && is_array($data) && isset($data['error'])) {
        $names = [400=>'bad_request',401=>'unauthorized',403=>'forbidden',404=>'not_found',
            405=>'method_not_allowed',409=>'conflict',413=>'payload_too_large',
            415=>'unsupported_media_type',419=>'csrf_failed',422=>'validation_failed',
            429=>'rate_limited',500=>'internal_error',503=>'unavailable'];
        $data += [
            'code'=>$names[$code] ?? 'request_failed',
            'message'=>(string)$data['error'],
            'request_id'=>request_id(),
        ];
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Begin a newline-delimited JSON response that proxies must not buffer. */
function stream_json_start(): void
{
    ignore_user_abort(false);
    @set_time_limit(900);
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: application/x-ndjson; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) { @ob_end_flush(); }
}

/** Emit one immediately-flushed NDJSON event. */
function stream_json_event(array $event): void
{
    echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    @ob_flush();
    flush();
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
    if (function_exists('is_api_token_authenticated') && is_api_token_authenticated()) {
        return;
    }
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
    ob_start();
    if ($withLayout) {
        $__view = $viewFile;
        $__active = $_GET['r'] ?? 'dashboard';
        require APP_ROOT . '/views/layout.php';
    } else {
        require $viewFile;
    }
    $html = (string) ob_get_clean();
    $nonce = e(csp_nonce());
    echo preg_replace('/<script(?![^>]*\bnonce=)/i', '<script nonce="' . $nonce . '"', $html) ?? $html;
}

/** Safe attachment header for arbitrary filesystem names. */
function attachment_header(string $filename): string
{
    $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';
    return 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
}

/** Small shared cache for expensive read-only system snapshots. */
function cache_remember(string $key, int $ttl, callable $producer)
{
    $safe = preg_replace('/[^a-z0-9_-]/i', '-', $key) ?: 'cache';
    $dir = DATA_DIR . '/cache';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $path = $dir . '/' . $safe . '.json';
    $cached = @json_decode((string) @file_get_contents($path), true);
    if (is_array($cached) && (int) ($cached['expires'] ?? 0) >= time() && array_key_exists('value', $cached)) {
        return $cached['value'];
    }
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX)) { return $producer(); }
    try {
        // Another request may have populated the cache while this one waited.
        $cached = @json_decode((string) @file_get_contents($path), true);
        if (is_array($cached) && (int) ($cached['expires'] ?? 0) >= time() && array_key_exists('value', $cached)) {
            return $cached['value'];
        }
        $value = $producer();
        write_json_file($path, ['expires' => time() + max(1, $ttl), 'value' => $value]);
        return $value;
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Read a JSON request body. API endpoints intentionally reject form bodies. */
function read_json_body(): array
{
    $type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($type !== 'application/json') {
        json_out(['ok' => false, 'error' => 'Content-Type must be application/json.'], 415);
    }
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 1024 * 1024) {
        json_out(['ok' => false, 'error' => 'Request body is too large.'], 413);
    }
    $raw = (string) file_get_contents('php://input');
    try {
        $j = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        json_out(['ok' => false, 'error' => 'Malformed JSON request body.'], 400);
    }
    if (!is_array($j)) {
        json_out(['ok' => false, 'error' => 'JSON body must be an object.'], 400);
    }
    return $j;
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
    $user = $clean((string) (function_exists('current_user') ? (current_user() ?? 'anon') : ($_SESSION['username'] ?? 'anon')), 100);
    $ip = $clean(client_ip(), 64);
    $action = $clean($action, 120);
    $detail = redact_secrets($clean($detail, 2000));
    global $apiAuthId;
    $authId = function_exists('is_api_token_authenticated') && is_api_token_authenticated()
        ? 'token:' . $clean((string) ($apiAuthId ?? ''), 32)
        : (session_status() === PHP_SESSION_ACTIVE && session_id() !== ''
            ? 'session:' . substr(hash('sha256', session_id()), 0, 16) : 'none');
    $event = [
        'time' => date('c'),
        'actor' => $user,
        'role' => function_exists('current_role') ? current_role() : 'anonymous',
        'auth_id' => $authId,
        'ip' => $ip,
        'action' => $action,
        'detail' => $detail,
        'request_id' => request_id(),
    ];
    @file_put_contents(DATA_DIR . '/audit.log', json_encode($event, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    if (function_exists('openlog') && function_exists('syslog')) {
        openlog('nebula-panel', LOG_PID, LOG_AUTHPRIV);
        syslog(LOG_NOTICE, json_encode($event, JSON_UNESCAPED_SLASHES));
        closelog();
    }
}

function request_id(): string
{
    static $id;
    if ($id === null) {
        $provided = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
        $id = preg_match('/^[A-Za-z0-9._-]{8,80}$/', $provided) ? $provided : bin2hex(random_bytes(8));
        if (!headers_sent()) header('X-Request-ID: ' . $id);
    }
    return $id;
}

function redact_secrets(string $value): string
{
    $value = preg_replace('#(https?://)[^/@\s:]+:[^/@\s]+@#i', '$1[redacted]@', $value) ?? $value;
    $value = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [redacted]', $value) ?? $value;
    $value = preg_replace('/\b(authorization|cookie)\s*[:=]\s*[^\r\n]+/i', '$1=[redacted]', $value) ?? $value;
    $value = preg_replace('/\b(password|passwd|token|secret)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $value) ?? $value;
    return $value;
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
