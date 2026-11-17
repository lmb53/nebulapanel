<?php
/**
 * Background jobs: long actions (backups, restores, installs, certificates,
 * Git deploys, upgrades) run in a detached PHP CLI worker instead of inside
 * the web request, so closing the tab, a dropped connection or the PHP-FPM
 * request timeout can no longer kill them half way. The browser follows a
 * job by polling its event log, and can re-attach from any page.
 *
 * A job re-runs the same API endpoint the browser would have streamed from,
 * with the same body, as the same panel user, so every permission check,
 * validation and audit entry is unchanged.
 */

/** Endpoints whose POST actions may run as jobs (all of them stream progress). */
const JOB_ENDPOINTS = ['apps', 'backups', 'compose', 'file-extract', 'git', 'mail', 'php', 'pma',
    'provision', 'sites', 'ssl', 'updates', 'databases'];
/** Keep finished jobs this long. */
const JOB_RETENTION = 7 * 86400;
/** Request bodies larger than this are refused (they are stored until the worker reads them). */
const JOB_MAX_BODY = 262144;

function jobs_dir(): string
{
    $dir = DATA_DIR . '/jobs';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    return $dir;
}

function job_id_ok(string $id): bool
{
    return (bool) preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $id);
}

function job_path(string $id, string $suffix): string
{
    return jobs_dir() . '/' . $id . $suffix;
}

function job_load(string $id): ?array
{
    if (!job_id_ok($id)) { return null; }
    $job = @json_decode((string) @file_get_contents(job_path($id, '.json')), true);
    return is_array($job) ? $job : null;
}

function job_save(array $job): bool
{
    return write_json_file(job_path((string) $job['id'], '.json'), $job);
}

/** Merge fields into a job record under a lock (the worker and readers race). */
function job_update(string $id, array $fields): ?array
{
    $lock = @fopen(job_path($id, '.lock'), 'c');
    if ($lock === false || !@flock($lock, LOCK_EX)) { if (is_resource($lock)) { fclose($lock); } return null; }
    try {
        $job = job_load($id);
        if ($job === null) { return null; }
        $job = array_merge($job, $fields);
        job_save($job);
        return $job;
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** A short human title for the jobs list: "Backups · restore". */
function job_title(string $endpoint, array $body): string
{
    $labels = ['apps' => 'Install Apps', 'backups' => 'Backups', 'compose' => 'Docker stack', 'file-extract' => 'Extract archive',
        'git' => 'Git deploy', 'mail' => 'Email', 'php' => 'PHP', 'pma' => 'phpMyAdmin', 'provision' => 'Server setup',
        'sites' => 'Websites', 'ssl' => 'SSL certificate', 'updates' => 'Updates', 'databases' => 'Databases'];
    $action = preg_replace('/[^a-z0-9 _-]/i', '', (string) ($body['action'] ?? ''));
    $subject = '';
    foreach (['domain', 'name', 'key', 'version', 'file', 'package', 'database'] as $field) {
        if (isset($body[$field]) && is_scalar($body[$field]) && (string) $body[$field] !== '') {
            $subject = substr(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $body[$field]) ?? '', 0, 80);
            break;
        }
    }
    return trim(($labels[$endpoint] ?? $endpoint) . ($action !== '' ? ' · ' . str_replace(['-', '_'], ' ', $action) : '') . ($subject !== '' ? ' · ' . $subject : ''));
}

/** Absolute path of a PHP CLI binary matching the running PHP version when possible. */
function php_cli_binary(): string
{
    $candidates = ['php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'php', 'php8.5', 'php8.4', 'php8.3', 'php8.2'];
    foreach ($candidates as $candidate) {
        foreach (['/usr/bin/', '/usr/local/bin/'] as $dir) {
            if (is_executable($dir . $candidate)) { return $dir . $candidate; }
        }
    }
    if (PHP_SAPI === 'cli' && PHP_BINARY !== '') { return PHP_BINARY; }
    return 'php';
}

/**
 * Start a job for the current user. Permission checks for the target
 * endpoint happen here (with the caller's role and token scopes) and again
 * inside the worker.
 */
function job_start(string $endpoint, array $body): array
{
    if (!in_array($endpoint, JOB_ENDPOINTS, true)) {
        return ['ok' => false, 'error' => 'That action cannot run in the background.'];
    }
    if (!can_access_api($endpoint, 'POST')
        || (function_exists('is_api_token_authenticated') && is_api_token_authenticated() && !api_token_scope_allows($endpoint, 'POST'))) {
        return ['ok' => false, 'error' => 'Your role does not have permission for this action.', 'forbidden' => true];
    }
    $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false || strlen($encoded) > JOB_MAX_BODY) {
        return ['ok' => false, 'error' => 'The request is too large to run in the background.'];
    }
    $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
    $job = [
        'id' => $id,
        'endpoint' => $endpoint,
        'title' => job_title($endpoint, $body),
        'action' => (string) ($body['action'] ?? ''),
        'uid' => (int) ($_SESSION['uid'] ?? 0),
        'username' => (string) (current_user() ?? ''),
        'role' => current_role(),
        'ip' => client_ip(),
        'token' => function_exists('is_api_token_authenticated') && is_api_token_authenticated() ? ($GLOBALS['apiAuthId'] ?? '') : '',
        'scopes' => function_exists('is_api_token_authenticated') && is_api_token_authenticated() ? ($GLOBALS['apiAuthScopes'] ?? []) : null,
        'status' => 'queued',
        'created_at' => time(),
        'started_at' => null,
        'finished_at' => null,
        'pid' => null,
        'result' => null,
    ];
    if (!job_save($job) || @file_put_contents(job_path($id, '.body'), $encoded, LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'Could not record the job (is data/ writable?).'];
    }
    @chmod(job_path($id, '.body'), 0600);
    @touch(job_path($id, '.log'));
    @chmod(job_path($id, '.log'), 0600);
    if (!job_spawn($id)) {
        job_update($id, ['status' => 'failed', 'finished_at' => time(),
            'result' => ['ok' => false, 'error' => 'Could not start the background worker (proc_open is unavailable).']]);
        @unlink(job_path($id, '.body'));
        return ['ok' => false, 'error' => 'Could not start the background worker.'];
    }
    return ['ok' => true, 'job' => job_public(job_load($id) ?? $job)];
}

/** Launch the detached worker (its own session, so it outlives this request). */
function job_spawn(string $id): bool
{
    if (!function_exists('proc_open')) { return false; }
    $env = ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'NEBULA_DATA_DIR' => DATA_DIR];
    foreach (['NEBULA_FM_ROOT', 'NEBULA_SITES_ROOT', 'NEBULA_FORCE_HTTPS', 'NEBULA_JOB_ALLOW_ROOT', 'HOME'] as $name) {
        $value = getenv($name);
        if ($value !== false) { $env[$name] = $value; }
    }
    $inline = defined('JOB_SPAWN_INLINE') && JOB_SPAWN_INLINE; // test hook: wait for the worker
    $detach = $inline ? '' : (is_executable('/usr/bin/setsid') ? 'setsid ' : '');
    $script = 'exec </dev/null; ' . $detach . '"$0" "$1" "$2" >/dev/null 2>>"$3"' . ($inline ? '' : ' &');
    $proc = @proc_open(['/bin/sh', '-c', $script, php_cli_binary(), APP_ROOT . '/bin/job.php', $id, job_path($id, '.stderr')],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, APP_ROOT, $env);
    if (!is_resource($proc)) { return false; }
    proc_close($proc); // the shell returns as soon as the worker is in the background
    return true;
}

/** Fields the browser may see. */
function job_public(array $job): array
{
    return array_intersect_key($job, array_flip(['id', 'endpoint', 'title', 'action', 'status', 'created_at', 'started_at', 'finished_at', 'result', 'username']));
}

/** True when the caller may follow this job (its owner, or an administrator). */
function job_visible(array $job): bool
{
    return current_role() === 'admin' || ((int) ($job['uid'] ?? -1) === (int) ($_SESSION['uid'] ?? -2) && (int) ($_SESSION['uid'] ?? 0) > 0);
}

/** Mark a job whose worker died without recording a result. */
function job_reap(array $job): array
{
    if (!in_array($job['status'] ?? '', ['queued', 'running'], true)) { return $job; }
    $pid = (int) ($job['pid'] ?? 0);
    $alive = $pid > 1 && (function_exists('posix_kill') ? @posix_kill($pid, 0) : is_dir('/proc/' . $pid));
    $stale = ($job['status'] === 'queued' && time() - (int) $job['created_at'] > 60)
        || ($job['status'] === 'running' && $pid > 1 && !$alive);
    if (!$stale) { return $job; }
    $error = trim((string) @file_get_contents(job_path((string) $job['id'], '.stderr'), false, null, 0, 4000));
    return job_update((string) $job['id'], ['status' => 'failed', 'finished_at' => time(), 'result' => ['ok' => false,
        'error' => 'The background worker stopped before it finished' . ($error !== '' ? ': ' . redact_secrets($error) : '.')]]) ?? $job;
}

/** Events from byte $offset of a job's log (complete lines only). */
function job_events(string $id, int $offset): array
{
    $path = job_path($id, '.log');
    $size = (int) @filesize($path);
    $offset = max(0, min($offset, $size));
    $chunk = $size > $offset ? (string) @file_get_contents($path, false, null, $offset, min(1048576, $size - $offset)) : '';
    $end = strrpos($chunk, "\n");
    $chunk = $end === false ? '' : substr($chunk, 0, $end + 1);
    $events = [];
    foreach (explode("\n", rtrim($chunk, "\n")) as $line) {
        if ($line === '') { continue; }
        $event = json_decode($line, true);
        if (is_array($event)) { $events[] = $event; }
    }
    return ['events' => $events, 'offset' => $offset + strlen($chunk)];
}

/** Recent jobs the caller may see, newest first. */
function jobs_list(int $limit = 20): array
{
    $rows = [];
    foreach (glob(jobs_dir() . '/*.json') ?: [] as $file) {
        $job = @json_decode((string) @file_get_contents($file), true);
        if (!is_array($job) || !job_id_ok((string) ($job['id'] ?? '')) || !job_visible($job)) { continue; }
        $rows[] = job_public(job_reap($job));
    }
    usort($rows, static fn($a, $b) => strcmp((string) $b['id'], (string) $a['id']));
    return array_slice($rows, 0, $limit);
}

/** Remove finished jobs older than the retention period. */
function jobs_prune(?int $now = null): int
{
    $now = $now ?? time();
    $removed = 0;
    foreach (glob(jobs_dir() . '/*.json') ?: [] as $file) {
        $job = @json_decode((string) @file_get_contents($file), true);
        $id = (string) ($job['id'] ?? '');
        if (!job_id_ok($id) || in_array($job['status'] ?? '', ['queued', 'running'], true)) { continue; }
        if ($now - (int) ($job['finished_at'] ?? $job['created_at'] ?? $now) < JOB_RETENTION) { continue; }
        foreach (['.json', '.log', '.body', '.lock', '.stderr'] as $suffix) { @unlink(job_path($id, $suffix)); }
        $removed++;
    }
    return $removed;
}
