<?php
/**
 * Background job worker: runs one queued API action outside the web request.
 * Started detached by job_spawn() as the panel user; never run it by hand.
 *   php bin/job.php <job-id>
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('NEBULA_JOB_ALLOW_ROOT') !== '1') {
    fwrite(STDERR, "Jobs run as the panel user, not root.\n");
    exit(1);
}
$jobId = (string) ($argv[1] ?? '');
if (!preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $jobId)) {
    fwrite(STDERR, "Invalid job id.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', getenv('NEBULA_DATA_DIR') ?: APP_ROOT . '/data');
define('NEBULA_JOB', $jobId);

$config = require APP_ROOT . '/config.php';
$overrides = @json_decode((string) @file_get_contents(DATA_DIR . '/settings.json'), true);
if (is_array($overrides)) {
    $config = array_merge($config, $overrides);
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', DATA_DIR . '/php-errors.log');
@set_time_limit(0);
ignore_user_abort(true);

require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/sys.php';
require APP_ROOT . '/lib/modules.php';
require APP_ROOT . '/lib/mod_api.php';
require APP_ROOT . '/lib/mod_jobs.php';

$job = job_load($jobId);
if ($job === null) {
    exit(1);
}
$_SERVER['REMOTE_ADDR'] = (string) ($job['ip'] ?? 'local');

// Claim the job exactly once.
$claimed = false;
$lock = @fopen(job_path($jobId, '.lock'), 'c');
if ($lock !== false && @flock($lock, LOCK_EX)) {
    $fresh = job_load($jobId);
    if ($fresh !== null && ($fresh['status'] ?? '') === 'queued') {
        $fresh['status'] = 'running';
        $fresh['pid'] = getmypid();
        $fresh['started_at'] = time();
        $claimed = job_save($fresh);
        $job = $fresh;
    }
    @flock($lock, LOCK_UN);
    fclose($lock);
}
if (!$claimed) {
    exit(0);
}

/** The result as the browser would have received it, minus bulky output. */
function job_result_for_storage(array $result): array
{
    unset($result['output'], $result['log']);
    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded !== false && strlen($encoded) <= 65536) { return $result; }
    return array_filter([
        'ok' => !empty($result['ok']),
        'error' => isset($result['error']) ? substr((string) $result['error'], 0, 4000) : null,
        'message' => isset($result['message']) ? substr((string) $result['message'], 0, 4000) : null,
    ], static fn($value) => $value !== null);
}

$finish = static function (array $result) use ($jobId): void {
    static $done = false;
    if ($done) { return; }
    $done = true;
    job_update($jobId, ['status' => !empty($result['ok']) ? 'done' : 'failed', 'finished_at' => time(),
        'result' => job_result_for_storage($result)]);
};
register_shutdown_function(static function () use ($finish): void {
    $result = $GLOBALS['NEBULA_JOB_RESULT'] ?? null;
    if (!is_array($result)) {
        $fatal = error_get_last();
        $message = $fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
            ? 'Internal error: ' . redact_secrets((string) $fatal['message'])
            : 'The action ended without reporting a result.';
        $result = ['ok' => false, 'error' => $message];
        job_log_event(['type' => 'result', 'result' => $result]);
    }
    $finish($result);
});

$body = @json_decode((string) @file_get_contents(job_path($jobId, '.body')), true);
@unlink(job_path($jobId, '.body'));
if (!is_array($body)) {
    job_log_event(['type' => 'result', 'result' => ['ok' => false, 'error' => 'The job request could not be read.']]);
    exit(1);
}

// Act as the requester, with their *current* role and status.
$endpoint = (string) ($job['endpoint'] ?? '');
if ((string) ($job['token'] ?? '') !== '') {
    $token = null;
    foreach (api_tokens_load() as $candidate) {
        if (($candidate['id'] ?? '') === $job['token']) { $token = $candidate; break; }
    }
    $expiry = $token ? strtotime((string) ($token['expires_at'] ?? '')) : false;
    if (!$token || $expiry === false || $expiry < time()) {
        job_log_event(['type' => 'result', 'result' => ['ok' => false, 'error' => 'The API token was revoked or expired.']]);
        exit(1);
    }
    $apiAuthLabel = (string) ($token['label'] ?? 'token');
    $apiAuthId = (string) $token['id'];
    $apiAuthRole = isset(panel_roles()[(string) ($token['role'] ?? '')]) ? (string) $token['role'] : 'auditor';
    $apiAuthScopes = is_array($token['scopes'] ?? null) ? $token['scopes'] : [];
    $_SESSION = [];
} else {
    $user = null;
    foreach (panel_users() as $candidate) {
        if ((int) ($candidate['id'] ?? 0) === (int) ($job['uid'] ?? -1)) { $user = $candidate; break; }
    }
    if ($user === null || empty($user['enabled'])) {
        job_log_event(['type' => 'result', 'result' => ['ok' => false, 'error' => 'The account that started this job is disabled or gone.']]);
        exit(1);
    }
    $_SESSION = ['uid' => (int) $user['id'], 'username' => (string) $user['username'], 'role' => (string) $user['role']];
}
if (!in_array($endpoint, JOB_ENDPOINTS, true) || !can_access_api($endpoint, 'POST')
    || (is_api_token_authenticated() && !api_token_scope_allows($endpoint, 'POST'))) {
    job_log_event(['type' => 'result', 'result' => ['ok' => false, 'error' => 'Your role does not have permission for this action.']]);
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_GET = ['r' => 'api/' . $endpoint, 'stream' => '1'];
$GLOBALS['NEBULA_JOB_BODY'] = $body;
request_id();
job_log_event(['type' => 'start']);

try {
    require APP_ROOT . '/api/' . $endpoint . '.php';
} catch (Throwable $error) {
    $message = redact_secrets($error->getMessage());
    error_log('job ' . $jobId . ' (' . $endpoint . ') failed: ' . $message . ' in ' . basename($error->getFile()) . ':' . $error->getLine());
    job_log_event(['type' => 'result', 'result' => ['ok' => false, 'error' => $message !== '' ? $message : 'Internal error.']]);
}
exit(0);
