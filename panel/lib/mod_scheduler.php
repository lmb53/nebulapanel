<?php
/**
 * Background scheduler: the work the panel does when nobody is looking.
 * bin/scheduler.php runs this every five minutes from a systemd timer
 * (nebula-panel-scheduler.timer, installed by install.sh) as the panel user.
 *
 *   - records a resource-history sample;
 *   - runs scheduled backups that are due and applies retention;
 *   - evaluates alert conditions and sends email/webhook notifications.
 */

const SCHEDULER_STALE_AFTER = 20 * 60;
const SCHEDULER_TIMER = 'nebula-panel-scheduler.timer';

function scheduler_state_file(): string
{
    return DATA_DIR . '/scheduler.json';
}

function scheduler_state(): array
{
    $state = @json_decode((string) @file_get_contents(scheduler_state_file()), true);
    return is_array($state) ? $state : [];
}

function scheduler_last_run(): int
{
    return (int) (scheduler_state()['last_run'] ?? 0);
}

/** Run every scheduled job once. Each job is isolated from the others' failures. */
function scheduler_run(?int $now = null): array
{
    $now = $now ?? time();
    require_once APP_ROOT . '/lib/mod_history.php';
    require_once APP_ROOT . '/lib/mod_backups.php';
    require_once APP_ROOT . '/lib/mod_alerts.php';
    $jobs = [
        'history' => static fn() => ['recorded' => history_record(true)],
        'backups' => static fn() => scheduler_start_backups($now),
        'alerts' => static fn() => alerts_evaluate($now),
        'housekeeping' => static fn() => scheduler_housekeeping($now),
    ];
    $report = [];
    foreach ($jobs as $name => $job) {
        $started = microtime(true);
        try {
            $result = $job();
            $report[$name] = ['ok' => !isset($result['ok']) || (bool) $result['ok']] + (is_array($result) ? $result : []);
        } catch (Throwable $error) {
            $report[$name] = ['ok' => false, 'error' => redact_secrets($error->getMessage())];
            error_log('scheduler job ' . $name . ' failed: ' . $error->getMessage());
        }
        $report[$name]['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
    }
    $state = scheduler_state();
    if (!empty($report['housekeeping']['ran'])) { $state['housekeeping_at'] = $now; }
    write_json_file(scheduler_state_file(), array_merge($state, ['last_run' => $now, 'report' => $report]));
    return $report;
}

/**
 * Scheduled backups can take hours; run them in their own process so alerts
 * and resource history keep their five-minute rhythm meanwhile. A lock in
 * the backup process stops a second run from starting while one is going.
 */
function scheduler_start_backups(int $now): array
{
    if (!backup_schedule_due(backup_schedule(), $now)) {
        return ['ok' => true, 'ran' => false, 'next_run' => backup_schedule_next(backup_schedule(), $now)];
    }
    if (PHP_SAPI !== 'cli' || !function_exists('proc_open')) {
        return backup_schedule_run_if_due($now);
    }
    require_once APP_ROOT . '/lib/mod_jobs.php';
    $script = 'exec </dev/null; ' . (is_executable('/usr/bin/setsid') ? 'setsid ' : '') . '"$0" "$1" --backups --quiet >/dev/null 2>&1 &';
    $proc = @proc_open(['/bin/sh', '-c', $script, php_cli_binary(), APP_ROOT . '/bin/scheduler.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, APP_ROOT);
    if (!is_resource($proc)) { return backup_schedule_run_if_due($now); }
    proc_close($proc);
    return ['ok' => true, 'ran' => 'started'];
}

/** Days deleted files, archived sites and old mailboxes stay recoverable. */
function trash_retention_days(): int
{
    global $config;
    return max(1, min(3650, (int) ($config['trash_retention_days'] ?? 30)));
}

/**
 * Once a day: drop finished background jobs, purge trash past its retention
 * period (File Manager trash, root recovery trash, archived websites and
 * mailboxes) and thin out safety backups taken before restores.
 */
function scheduler_housekeeping(int $now): array
{
    if ($now - (int) (scheduler_state()['housekeeping_at'] ?? 0) < 86400) {
        return ['ok' => true, 'ran' => false];
    }
    require_once APP_ROOT . '/lib/mod_jobs.php';
    require_once APP_ROOT . '/lib/files.php';
    $days = trash_retention_days();
    $result = ['ok' => true, 'ran' => true, 'jobs_pruned' => jobs_prune($now), 'uploads_pruned' => fm_upload_prune(), 'trash_purged' => 0, 'pre_restore_pruned' => 0];
    foreach (fm_trash_list() as $item) {
        if ($now - (int) $item['deleted_at'] > $days * 86400 && !empty(fm_trash_purge((string) $item['id'])['ok'])) {
            $result['trash_purged']++;
        }
    }
    if (helper_available()) {
        [$code, $out] = helper_cmd('file-trash-purge ' . $days, 600);
        if ($code !== 0) { $result['ok'] = false; $result['error'] = 'Recovery trash purge failed: ' . trim($out); }
    }
    // Keep the two newest pre-restore safety backups per site, and any from the last 14 days.
    $bySite = [];
    foreach (backup_list() as $backup) {
        if (($backup['kind'] ?? '') === 'pre-restore') { $bySite[$backup['site_id']][] = $backup; }
    }
    foreach ($bySite as $rows) {
        foreach (array_slice($rows, 2) as $old) {
            if ($now - (int) $old['mtime'] > 14 * 86400 && !empty(backup_delete($old['file'])['ok'])) {
                $result['pre_restore_pruned']++;
            }
        }
    }
    return $result;
}

/** Health item when the timer stopped running on a real (helper-equipped) install. */
function scheduler_health_item(): ?array
{
    if (!is_linux() || !helper_available()) { return null; }
    $last = scheduler_last_run();
    if ($last > 0 && time() - $last < SCHEDULER_STALE_AFTER) { return null; }
    return [
        'level' => 'warning',
        'key' => 'scheduler',
        'title' => $last ? 'Background scheduler stopped' : 'Background scheduler has never run',
        'detail' => ($last ? 'Last run ' . fmt_datetime($last) . '. ' : '')
            . 'Scheduled backups, alerts and resource history need it. Fix: '
            . (is_file('/etc/systemd/system/' . SCHEDULER_TIMER)
                ? 'sudo systemctl enable --now ' . SCHEDULER_TIMER
                : 're-run install.sh once to install ' . SCHEDULER_TIMER),
        'route' => 'diagnostics',
        'icon' => 'timer-off',
    ];
}
