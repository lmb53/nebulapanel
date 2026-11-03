<?php
/**
 * Cron module — manages the web user's crontab (no sudo needed).
 * To manage another user's crontab you'd use `sudo crontab -u <user>` and a
 * matching sudoers rule; kept to the panel user here for safety.
 */

function cron_available(): bool
{
    return has_cmd('crontab');
}

/**
 * Crontab lines, [] when the account has no crontab yet, or null when it
 * could not be read. Callers that write must treat null as an error: saving
 * after a failed read would replace every existing job.
 */
function cron_read_lines(): ?array
{
    [$code, $out] = run_cmd('crontab -l 2>&1');
    if ($code !== 0) {
        return stripos($out, 'no crontab for') !== false ? [] : null;
    }
    $raw = rtrim($out, "\n");
    return $raw === '' ? [] : preg_split('/\r?\n/', $raw);
}

/** Raw crontab text (empty string if none / not available). */
function cron_raw(): string
{
    $lines = cron_read_lines();
    return $lines ? implode("\n", $lines) . "\n" : '';
}

/** Current crontab as an array of lines (no trailing blank). Read-only use. */
function cron_current_lines(): array
{
    return cron_read_lines() ?? [];
}

/**
 * Serialise read-modify-write cycles so two concurrent edits cannot undo
 * each other. $mutator receives the current lines and returns
 * [newLines|null, result]; null lines means "do not save".
 */
function cron_mutate(callable $mutator): array
{
    $handle = @fopen(DATA_DIR . '/cron.lock', 'c');
    if ($handle === false || !@flock($handle, LOCK_EX)) {
        if (is_resource($handle)) { fclose($handle); }
        return ['ok' => false, 'error' => 'Could not lock the crontab for editing.'];
    }
    try {
        $lines = cron_read_lines();
        if ($lines === null) {
            return ['ok' => false, 'error' => 'The current crontab could not be read, so it was not changed.'];
        }
        [$next, $result] = $mutator($lines);
        if ($next === null) { return $result; }
        $saved = cron_save($next);
        if (empty($saved['ok'])) { return $saved; }
        // Audit only what was actually written.
        if (isset($result['_audit'])) { audit($result['_audit'][0], $result['_audit'][1]); }
        unset($result['_audit']);
        return $result;
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Parse the crontab into structured entries keyed by line index. */
function cron_parse_job_line(string $line, int $index, bool $enabled = true): ?array
{
    $t = trim($line);
    if ($t === '') { return null; }
    if ($t[0] === '@') {
        $parts = preg_split('/\s+/', $t, 2);
        return [
            'index' => $index, 'type' => 'job', 'schedule' => $parts[0],
            'command' => $parts[1] ?? '', 'raw' => $line, 'enabled' => $enabled,
        ];
    }
    $parts = preg_split('/\s+/', $t, 6);
    if (count($parts) < 6) { return null; }
    return [
        'index' => $index, 'type' => 'job',
        'schedule' => implode(' ', array_slice($parts, 0, 5)),
        'command' => $parts[5], 'raw' => $line, 'enabled' => $enabled,
    ];
}

function cron_list(): array
{
    $jobs = [];
    foreach (cron_current_lines() as $i => $line) {
        $t = trim($line);
        if ($t === '') {
            continue;
        }
        if (str_starts_with($t, '# NEBULA_DISABLED ')) {
            $disabled = cron_parse_job_line(substr($t, strlen('# NEBULA_DISABLED ')), $i, false);
            $jobs[] = $disabled ?: ['index' => $i, 'type' => 'comment', 'raw' => $line];
            continue;
        }
        if ($t[0] === '#') {
            $jobs[] = ['index' => $i, 'type' => 'comment', 'raw' => $line];
            continue;
        }
        // Environment assignment (VAR=value) — no schedule.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $t)) {
            $jobs[] = ['index' => $i, 'type' => 'env', 'raw' => $line];
            continue;
        }
        $jobs[] = cron_parse_job_line($line, $i, true)
            ?: ['index' => $i, 'type' => 'other', 'raw' => $line];
    }
    return $jobs;
}

/** Write a full set of lines back to the crontab. */
function cron_save(array $lines): array
{
    $content = implode("\n", $lines);
    if ($content !== '') {
        $content .= "\n";
    }
    $tmp = tempnam(sys_get_temp_dir(), 'nebula_cron');
    if ($tmp === false || file_put_contents($tmp, $content) === false) {
        return ['ok' => false, 'error' => 'Could not write temp file.'];
    }
    [$code, $out] = run_cmd('crontab ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    if ($code !== 0) {
        return ['ok' => false, 'error' => trim($out) ?: ('crontab write failed (exit ' . $code . ').')];
    }
    return ['ok' => true];
}

const CRON_KEYWORDS = ['@reboot', '@yearly', '@annually', '@monthly', '@weekly', '@daily', '@midnight', '@hourly'];

/** Why a schedule/command pair cannot be written to the crontab, or null. */
function cron_validate(string $schedule, string $command): ?string
{
    if ($schedule === '' || $command === '') {
        return 'Schedule and command are both required.';
    }
    // A line break would smuggle extra entries into the crontab (and shift
    // every index the UI uses to address jobs).
    if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $schedule . $command)) {
        return 'Schedule and command must each be a single line.';
    }
    // cron turns an unescaped % into a newline, silently cutting the command
    // short (e.g. `date +%F`), while "Run now" in a shell would still work.
    if (preg_match('/(?<!\\\\)%/', $command)) {
        return 'Escape every % in the command as \\% — cron treats a bare % as a line break.';
    }
    if ($schedule[0] === '@') {
        return in_array(strtolower($schedule), CRON_KEYWORDS, true)
            ? null : 'Unknown schedule keyword. Use one of ' . implode(', ', CRON_KEYWORDS) . '.';
    }
    $fields = preg_split('/\s+/', $schedule);
    if (count($fields) !== 5) {
        return 'Schedule must be 5 fields (e.g. "0 2 * * *") or an @keyword.';
    }
    foreach ($fields as $field) {
        if (!preg_match('/^[A-Za-z0-9*\/,-]+$/', $field)) {
            return 'Invalid schedule field "' . $field . '".';
        }
    }
    return null;
}

/**
 * Jobs are addressed by crontab line index, which shifts whenever another
 * line is added or removed. When the caller says which line it saw, the line
 * at that index must still be it, or the action would hit a different job.
 */
function cron_line_error(array $lines, int $index, ?string $expect): ?string
{
    if (!isset($lines[$index])) {
        return 'Job not found (list may be stale — refresh).';
    }
    if ($expect !== null && $expect !== '' && $lines[$index] !== $expect) {
        return 'The crontab changed since this page loaded. Refresh and try again.';
    }
    return null;
}

function cron_add(string $schedule, string $command): array
{
    $schedule = trim($schedule);
    $command = trim($command);
    if (($error = cron_validate($schedule, $command)) !== null) {
        return ['ok' => false, 'error' => $error];
    }
    return cron_mutate(static function (array $lines) use ($schedule, $command): array {
        $lines[] = $schedule . ' ' . $command;
        return [$lines, ['ok' => true, '_audit' => ['cron.add', $schedule . ' ' . $command]]];
    });
}

function cron_delete(int $index, ?string $expect = null): array
{
    return cron_mutate(static function (array $lines) use ($index, $expect): array {
        if (($error = cron_line_error($lines, $index, $expect)) !== null) {
            return [null, ['ok' => false, 'error' => $error]];
        }
        $removed = $lines[$index];
        array_splice($lines, $index, 1);
        return [$lines, ['ok' => true, '_audit' => ['cron.delete', $removed]]];
    });
}

function cron_update(int $index,string $schedule,string $command,?string $expect=null): array
{
    $schedule = trim($schedule);
    $command = trim($command);
    if (($error = cron_validate($schedule, $command)) !== null) {
        return ['ok' => false, 'error' => $error];
    }
    return cron_mutate(static function (array $lines) use ($index, $schedule, $command, $expect): array {
        if (($error = cron_line_error($lines, $index, $expect)) !== null) {
            return [null, ['ok' => false, 'error' => $error]];
        }
        $disabled = str_starts_with(trim($lines[$index]), '# NEBULA_DISABLED ');
        $lines[$index] = ($disabled ? '# NEBULA_DISABLED ' : '') . $schedule . ' ' . $command;
        return [$lines, ['ok' => true, '_audit' => ['cron.update', $lines[$index]]]];
    });
}

function cron_toggle(int $index, bool $enabled, ?string $expect = null): array
{
    return cron_mutate(static function (array $lines) use ($index, $enabled, $expect): array {
        if (($error = cron_line_error($lines, $index, $expect)) !== null) { return [null, ['ok' => false, 'error' => $error]]; }
        $trimmed = trim($lines[$index]);
        $currentlyDisabled = str_starts_with($trimmed, '# NEBULA_DISABLED ');
        $jobLine = $currentlyDisabled ? substr($trimmed, strlen('# NEBULA_DISABLED ')) : $trimmed;
        if (cron_parse_job_line($jobLine, $index) === null) {
            return [null, ['ok' => false, 'error' => 'Only cron jobs can be enabled or disabled.']];
        }
        $lines[$index] = $enabled ? $jobLine : '# NEBULA_DISABLED ' . $jobLine;
        return [$lines, ['ok' => true, '_audit' => ['cron.' . ($enabled ? 'enable' : 'disable'), $jobLine]]];
    });
}

function cron_runs_file(): string { return DATA_DIR.'/cron-runs.json'; }
function cron_runs(): array { $runs=@json_decode((string)@file_get_contents(cron_runs_file()),true);return is_array($runs)?$runs:[]; }
function cron_run_now(int $index, ?string $expect = null): array
{
    $lines=cron_current_lines();if(($error=cron_line_error($lines,$index,$expect))!==null)return ['ok'=>false,'error'=>$error];$parsed=null;foreach(cron_list() as $job)if(($job['index']??-1)===$index&&($job['type']??'')==='job'){$parsed=$job;break;}if(!$parsed)return ['ok'=>false,'error'=>'Only cron jobs can be run.'];
    [$code,$out]=run_cmd(str_replace('\\%','%',(string)$parsed['command']),60);$runs=cron_runs();array_unshift($runs,['time'=>date('c'),'schedule'=>$parsed['schedule'],'command'=>$parsed['command'],'exit'=>$code,'output'=>substr($out,0,4000)]);write_json_file(cron_runs_file(),array_slice($runs,0,30));audit('cron.run',$parsed['command'].' (exit '.$code.')');return ['ok'=>$code===0,'exit'=>$code,'output'=>$out,'error'=>$code===0?'':('Command exited with code '.$code)];
}
