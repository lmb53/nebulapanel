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
        'backups' => static fn() => backup_schedule_run_if_due($now),
        'alerts' => static fn() => alerts_evaluate($now),
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
    write_json_file(scheduler_state_file(), ['last_run' => $now, 'report' => $report]);
    return $report;
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
