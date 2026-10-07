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

/** Raw crontab text (empty string if none / not available). */
function cron_raw(): string
{
    [$code, $out] = run_cmd('crontab -l 2>/dev/null');
    return $code === 0 ? $out : '';
}

/** Current crontab as an array of lines (no trailing blank). */
function cron_current_lines(): array
{
    $raw = rtrim(cron_raw(), "\n");
    return $raw === '' ? [] : preg_split('/\r?\n/', $raw);
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
    $lines = cron_current_lines();
    $lines[] = $schedule . ' ' . $command;
    $res = cron_save($lines);
    if ($res['ok']) {
        audit('cron.add', $schedule . ' ' . $command);
    }
    return $res;
}

function cron_delete(int $index, ?string $expect = null): array
{
    $lines = cron_current_lines();
    if (($error = cron_line_error($lines, $index, $expect)) !== null) {
        return ['ok' => false, 'error' => $error];
    }
    $removed = $lines[$index];
    array_splice($lines, $index, 1);
    $res = cron_save($lines);
    if ($res['ok']) {
        audit('cron.delete', $removed);
    }
    return $res;
}

function cron_update(int $index,string $schedule,string $command,?string $expect=null): array
{
    $schedule=trim($schedule);$command=trim($command);$lines=cron_current_lines();
    if(($error=cron_line_error($lines,$index,$expect))!==null)return ['ok'=>false,'error'=>$error];
    if(($error=cron_validate($schedule,$command))!==null)return ['ok'=>false,'error'=>$error];
    $disabled = str_starts_with(trim($lines[$index]), '# NEBULA_DISABLED ');
    $lines[$index]=($disabled?'# NEBULA_DISABLED ':'').$schedule.' '.$command;$res=cron_save($lines);if(!empty($res['ok']))audit('cron.update',$lines[$index]);return $res;
}

function cron_toggle(int $index, bool $enabled, ?string $expect = null): array
{
    $lines = cron_current_lines();
    if (($error = cron_line_error($lines, $index, $expect)) !== null) { return ['ok' => false, 'error' => $error]; }
    $trimmed = trim($lines[$index]);
    $currentlyDisabled = str_starts_with($trimmed, '# NEBULA_DISABLED ');
    $jobLine = $currentlyDisabled ? substr($trimmed, strlen('# NEBULA_DISABLED ')) : $trimmed;
    if (cron_parse_job_line($jobLine, $index) === null) {
        return ['ok' => false, 'error' => 'Only cron jobs can be enabled or disabled.'];
    }
    $lines[$index] = $enabled ? $jobLine : '# NEBULA_DISABLED ' . $jobLine;
    $res = cron_save($lines);
    if (!empty($res['ok'])) { audit('cron.' . ($enabled ? 'enable' : 'disable'), $jobLine); }
    return $res;
}

function cron_runs_file(): string { return DATA_DIR.'/cron-runs.json'; }
function cron_runs(): array { $runs=@json_decode((string)@file_get_contents(cron_runs_file()),true);return is_array($runs)?$runs:[]; }
function cron_run_now(int $index, ?string $expect = null): array
{
    $lines=cron_current_lines();if(($error=cron_line_error($lines,$index,$expect))!==null)return ['ok'=>false,'error'=>$error];$parsed=null;foreach(cron_list() as $job)if(($job['index']??-1)===$index&&($job['type']??'')==='job'){$parsed=$job;break;}if(!$parsed)return ['ok'=>false,'error'=>'Only cron jobs can be run.'];
    [$code,$out]=run_cmd((string)$parsed['command'],60);$runs=cron_runs();array_unshift($runs,['time'=>date('c'),'schedule'=>$parsed['schedule'],'command'=>$parsed['command'],'exit'=>$code,'output'=>substr($out,0,4000)]);write_json_file(cron_runs_file(),array_slice($runs,0,30));audit('cron.run',$parsed['command'].' (exit '.$code.')');return ['ok'=>$code===0,'exit'=>$code,'output'=>$out,'error'=>$code===0?'':('Command exited with code '.$code)];
}
