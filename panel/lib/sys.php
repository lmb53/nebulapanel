<?php
/**
 * System introspection + service control.
 * Linux-first (reads /proc, uses systemctl). Every function degrades
 * gracefully to null / 'n/a' on non-Linux so the UI never fatals.
 */

/** Run a shell command, return [exitCode, stdout, stderr]. */
function command_launch_spec(string $cmd)
{
    if (PHP_OS_FAMILY === 'Linux' && is_executable('/usr/bin/setsid')) {
        return ['/usr/bin/setsid', '/bin/sh', '-c', $cmd];
    }
    return $cmd;
}

function terminate_command_process($proc, array $status): void
{
    $pid = (int) ($status['pid'] ?? 0);
    if ($pid > 1 && function_exists('posix_kill') && defined('SIGTERM')) {
        @posix_kill(-$pid, SIGTERM);
        usleep(200000);
        if (defined('SIGKILL')) { @posix_kill(-$pid, SIGKILL); }
        return;
    }
    @proc_terminate($proc);
    usleep(200000);
    @proc_terminate($proc, 9);
}

function run_cmd(string $cmd, int $timeout = 15): array
{
    if (!function_exists('proc_open')) {
        return [127, '', 'proc_open disabled'];
    }
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open(command_launch_spec($cmd), $desc, $pipes);
    if (!is_resource($proc)) {
        return [127, '', 'could not start process'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $maxBytes = 2 * 1024 * 1024;
    $truncated = false;
    $timedOut = false;
    $start = microtime(true);
    $capturedBytes = 0;
    $append = static function (string &$target, string $chunk) use ($maxBytes, &$capturedBytes, &$truncated): void {
        $left = $maxBytes - $capturedBytes;
        if ($left <= 0) {
            $truncated = true;
            return;
        }
        if (strlen($chunk) > $left) {
            $target .= substr($chunk, 0, $left);
            $capturedBytes += $left;
            $truncated = true;
            return;
        }
        $target .= $chunk;
        $capturedBytes += strlen($chunk);
    };
    $status = ['running' => true, 'exitcode' => -1];
    do {
        $append($out, (string) stream_get_contents($pipes[1]));
        $append($err, (string) stream_get_contents($pipes[2]));
        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) - $start >= max(1, $timeout)) {
            $timedOut = true;
            terminate_command_process($proc, $status);
            break;
        }
        $readable = [$pipes[1], $pipes[2]];
        $write = null; $except = null;
        @stream_select($readable, $write, $except, 0, 200000);
    } while (true);
    $append($out, (string) stream_get_contents($pipes[1]));
    $append($err, (string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($proc);
    $code = $timedOut ? 124 : (($status['exitcode'] ?? -1) >= 0 ? $status['exitcode'] : $closeCode);
    if ($timedOut) {
        $err .= ($err === '' ? '' : "\n") . 'Command timed out after ' . max(1, $timeout) . ' seconds.';
    }
    if ($truncated) {
        $err .= ($err === '' ? '' : "\n") . 'Command output was truncated at 2 MB.';
    }
    return [$code, $out, $err];
}

/**
 * Run a command while forwarding output as it arrives.
 *
 * The callback receives ($chunk, $channel), where channel is stdout or
 * stderr. A bounded copy is still returned for auditing/error messages.
 */
function run_cmd_stream(string $cmd, callable $onChunk, int $timeout = 15): array
{
    if (!function_exists('proc_open')) {
        return [127, '', 'proc_open disabled'];
    }
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open(command_launch_spec($cmd), $desc, $pipes);
    if (!is_resource($proc)) {
        return [127, '', 'could not start process'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $maxBytes = 2 * 1024 * 1024;
    $truncated = false;
    $timedOut = false;
    $start = microtime(true);
    $capturedBytes = 0;
    $append = static function (string &$target, string $chunk) use ($maxBytes, &$capturedBytes, &$truncated): void {
        $left = $maxBytes - $capturedBytes;
        if ($left <= 0) { $truncated = true; return; }
        $target .= strlen($chunk) > $left ? substr($chunk, 0, $left) : $chunk;
        $capturedBytes += min(strlen($chunk), $left);
        if (strlen($chunk) > $left) { $truncated = true; }
    };
    $drain = static function ($pipe, string $channel, string &$capture) use ($onChunk, $append): void {
        $chunk = (string) stream_get_contents($pipe);
        if ($chunk === '') { return; }
        $append($capture, $chunk);
        $onChunk($chunk, $channel);
    };
    $status = ['running' => true, 'exitcode' => -1];
    do {
        $drain($pipes[1], 'stdout', $out);
        $drain($pipes[2], 'stderr', $err);
        $status = proc_get_status($proc);
        if (!$status['running']) { break; }
        if (microtime(true) - $start >= max(1, $timeout)) {
            $timedOut = true;
            terminate_command_process($proc, $status);
            break;
        }
        $readable = [$pipes[1], $pipes[2]];
        $write = null; $except = null;
        @stream_select($readable, $write, $except, 0, 200000);
    } while (true);
    $drain($pipes[1], 'stdout', $out);
    $drain($pipes[2], 'stderr', $err);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($proc);
    $code = $timedOut ? 124 : (($status['exitcode'] ?? -1) >= 0 ? $status['exitcode'] : $closeCode);
    if ($timedOut) {
        $message = 'Command timed out after ' . max(1, $timeout) . " seconds.\n";
        $err .= ($err === '' ? '' : "\n") . trim($message);
        $onChunk($message, 'stderr');
    }
    if ($truncated) {
        $err .= ($err === '' ? '' : "\n") . 'Captured command output was truncated at 2 MB.';
    }
    return [$code, $out, $err];
}

function is_linux(): bool
{
    return PHP_OS_FAMILY === 'Linux' && is_dir('/proc');
}

/** Is a binary available on PATH? */
function has_cmd(string $bin): bool
{
    [$code] = run_cmd('command -v ' . escapeshellarg($bin));
    return $code === 0;
}

/** Simple HTTP GET. Returns [ok(bool), body]. Uses curl if present. */
function http_get(string $url, int $timeout = 60): array
{
    if (has_cmd('curl')) {
        [$code, $out] = run_cmd(
            'curl -fsSL --max-time ' . (int) $timeout
            . ' -H ' . escapeshellarg('User-Agent: NebulaPanel')
            . ' ' . escapeshellarg($url),
            $timeout + 5
        );
        return [$code === 0, $out];
    }
    $ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: NebulaPanel\r\n",
        'timeout' => $timeout,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return [$body !== false, (string) $body];
}

/** Download a URL to a local file. Returns true on success. */
function http_download(string $url, string $dest, int $timeout = 300): bool
{
    if (has_cmd('curl')) {
        [$code] = run_cmd(
            'curl -fsSL --max-time ' . (int) $timeout
            . ' -H ' . escapeshellarg('User-Agent: NebulaPanel')
            . ' -o ' . escapeshellarg($dest)
            . ' ' . escapeshellarg($url),
            $timeout + 5
        );
        return $code === 0;
    }
    $data = http_get($url, $timeout);
    return $data[0] && @file_put_contents($dest, $data[1]) !== false;
}

/** Run a command as root via passwordless sudo. Returns [code, out, err]. */
function sudo_cmd(string $cmd, int $timeout = 30): array
{
    return helper_cmd('broker-command ' . escapeshellarg(base64_encode($cmd)), $timeout);
}

function sudo_cmd_stream(string $cmd, callable $onChunk, int $timeout = 30): array
{
    return helper_cmd_stream('broker-command ' . escapeshellarg(base64_encode($cmd)), $onChunk, $timeout);
}

/** Absolute path to the privileged helper (installed by install.sh). */
const NEBULA_HELPER = '/usr/local/bin/nebula-helper';

/** Is the privileged helper installed? */
function helper_available(): bool
{
    return is_file(NEBULA_HELPER);
}

/**
 * Invoke the privileged helper via sudo. $args must already be
 * escapeshellarg()'d by the caller. Returns [code, out, err].
 */
function helper_cmd(string $args, int $timeout = 180): array
{
    if (!helper_available()) {
        return [127, '', 'nebula-helper is not installed (re-run install.sh).'];
    }
    $started = microtime(true);
    $result = run_cmd('sudo -n ' . NEBULA_HELPER . ' ' . $args . ' 2>&1', $timeout);
    $action = preg_split('/\s+/', trim($args), 2)[0] ?? 'unknown';
    audit('helper.command', 'action=' . $action . ' outcome=' . ((int)$result[0] === 0 ? 'success' : 'failure')
        . ' exit=' . (int)$result[0] . ' duration_ms=' . (int) round((microtime(true) - $started) * 1000));
    return $result;
}

function helper_cmd_stream(string $args, callable $onChunk, int $timeout = 180): array
{
    if (!helper_available()) {
        return [127, '', 'nebula-helper is not installed (re-run install.sh).'];
    }
    $started = microtime(true);
    $result = run_cmd_stream('sudo -n ' . NEBULA_HELPER . ' ' . $args . ' 2>&1', $onChunk, $timeout);
    $action = preg_split('/\s+/', trim($args), 2)[0] ?? 'unknown';
    audit('helper.command', 'action=' . $action . ' outcome=' . ((int)$result[0] === 0 ? 'success' : 'failure')
        . ' exit=' . (int)$result[0] . ' duration_ms=' . (int) round((microtime(true) - $started) * 1000));
    return $result;
}

/** Normalise a sudo/permission failure into a friendly message. */
function sudo_error(string $out, int $code): string
{
    if (stripos($out, 'a password is required') !== false || stripos($out, 'may not run sudo') !== false) {
        return 'Permission denied — verify nebula-helper and its single sudoers rule.';
    }
    return trim($out) ?: ('Command failed (exit ' . $code . ').');
}

/** CPU usage percent derived from the previous cached /proc/stat sample. */
function cpu_usage(): ?float
{
    if (!is_readable('/proc/stat')) {
        return null;
    }
    $read = function () {
        $line = @file('/proc/stat')[0] ?? '';
        $p = preg_split('/\s+/', trim($line));
        if (($p[0] ?? '') !== 'cpu') {
            return null;
        }
        $vals = array_map('intval', array_slice($p, 1));
        $idle = ($vals[3] ?? 0) + ($vals[4] ?? 0); // idle + iowait
        $total = array_sum($vals);
        return [$idle, $total];
    };
    $current = $read();
    if (!$current) { return null; }
    $path = DATA_DIR . '/cache/cpu-sample.json';
    if (!is_dir(dirname($path))) { @mkdir(dirname($path), 0700, true); }
    $previous = @json_decode((string) @file_get_contents($path), true);
    write_json_file($path, ['idle' => $current[0], 'total' => $current[1], 'time' => microtime(true)]);
    if (!is_array($previous) || !isset($previous['idle'], $previous['total'])) { return null; }
    $dTotal = $current[1] - (int) $previous['total'];
    $dIdle = $current[0] - (int) $previous['idle'];
    if ($dTotal <= 0) {
        return null;
    }
    return round((1 - $dIdle / $dTotal) * 100, 1);
}

/** Memory info in bytes: [total, used, available]. */
function mem_info(): ?array
{
    if (!is_readable('/proc/meminfo')) {
        return null;
    }
    $info = [];
    foreach (file('/proc/meminfo') as $line) {
        if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
            $info[$m[1]] = (int) $m[2] * 1024;
        }
    }
    if (!isset($info['MemTotal'])) {
        return null;
    }
    $total = $info['MemTotal'];
    $avail = $info['MemAvailable'] ?? ($info['MemFree'] ?? 0);
    return ['total' => $total, 'available' => $avail, 'used' => $total - $avail];
}

/** Disk info for a mount point in bytes: [total, used, free]. */
function disk_info(string $path = '/'): ?array
{
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if ($total === false || $free === false) {
        return null;
    }
    return ['total' => $total, 'free' => $free, 'used' => $total - $free];
}

/** System uptime in seconds. */
function uptime_seconds(): ?int
{
    if (is_readable('/proc/uptime')) {
        $c = file_get_contents('/proc/uptime');
        return (int) floatval(explode(' ', trim($c))[0]);
    }
    return null;
}

function format_uptime(?int $s): string
{
    if ($s === null) {
        return 'n/a';
    }
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    return sprintf('%dd %dh %dm', $d, $h, $m);
}

/** Load averages [1m, 5m, 15m]. */
function load_avg(): array
{
    if (!function_exists('sys_getloadavg')) {
        return [0, 0, 0];
    }
    $l = @sys_getloadavg();
    return $l ?: [0, 0, 0];
}

/** Broad server facts for the System Info page. */
function system_facts(): array
{
    $os = php_uname('s') . ' ' . php_uname('r');
    if (is_readable('/etc/os-release')) {
        $osr = parse_ini_file('/etc/os-release');
        if (!empty($osr['PRETTY_NAME'])) {
            $os = $osr['PRETTY_NAME'];
        }
    }
    $cpuModel = 'n/a';
    $cpuCores = 0;
    if (is_readable('/proc/cpuinfo')) {
        foreach (file('/proc/cpuinfo') as $line) {
            if (strpos($line, 'model name') === 0 && $cpuModel === 'n/a') {
                $cpuModel = trim(explode(':', $line, 2)[1] ?? 'n/a');
            }
            if (strpos($line, 'processor') === 0) {
                $cpuCores++;
            }
        }
    }
    return [
        'hostname'    => gethostname() ?: 'n/a',
        'os'          => $os,
        'kernel'      => php_uname('r'),
        'arch'        => php_uname('m'),
        'cpu_model'   => $cpuModel,
        'cpu_cores'   => $cpuCores ?: (int) (shell_exec('nproc 2>/dev/null') ?: 0),
        'php_version' => PHP_VERSION,
        'uptime'      => format_uptime(uptime_seconds()),
        'server_time' => date('Y-m-d H:i:s T'),
    ];
}

/** Network interfaces with addresses (Linux). */
function net_interfaces(): array
{
    [$code, $out] = run_cmd('ip -o -4 addr show 2>/dev/null');
    $ifaces = [];
    if ($code === 0 && $out !== '') {
        foreach (explode("\n", $out) as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (isset($p[1], $p[3])) {
                $ifaces[] = ['name' => $p[1], 'addr' => $p[3]];
            }
        }
    }
    return $ifaces;
}

// --- Service control --------------------------------------------------------

/** Status of one systemd unit: active|inactive|failed|not-installed|unknown. */
function service_status(string $name): string
{
    if (!is_linux()) {
        return 'unknown';
    }
    [$code, $out] = run_cmd('systemctl is-active ' . escapeshellarg($name) . ' 2>/dev/null');
    $out = trim($out);
    if ($out === 'active') {
        return 'active';
    }
    if ($out === 'failed') {
        return 'failed';
    }
    // Distinguish "not installed" from merely stopped.
    [$c2, $load] = run_cmd('systemctl show -p LoadState --value ' . escapeshellarg($name) . ' 2>/dev/null');
    if (trim($load) === 'not-found') {
        return 'not-installed';
    }
    return 'inactive';
}

/** Statuses for the whitelist. */
function services_overview(array $whitelist): array
{
    $rows = [];
    foreach ($whitelist as $svc) {
        $rows[] = ['name' => $svc, 'status' => service_status($svc), 'enabled' => service_enabled($svc)];
    }
    return $rows;
}

/** Whether a unit is enabled at boot. Null for missing/unsupported units. */
function service_enabled(string $name): ?bool
{
    if (!is_linux()) {
        return null;
    }
    [$code, $out] = run_cmd('systemctl is-enabled ' . escapeshellarg($name) . ' 2>/dev/null');
    $state = trim($out);
    if ($code === 0 || in_array($state, ['enabled', 'enabled-runtime', 'static', 'indirect', 'alias'], true)) {
        return in_array($state, ['enabled', 'enabled-runtime'], true);
    }
    if (in_array($state, ['disabled', 'masked'], true)) {
        return false;
    }
    return null;
}

/**
 * Perform a service action. $action in start|stop|restart|enable|disable.
 * The requested action is validated by the privileged helper.
 */
function service_action(string $name, string $action, array $whitelist): array
{
    if (!in_array($name, $whitelist, true)) {
        return ['ok' => false, 'error' => 'Service not allowed.'];
    }
    if (!in_array($action, ['start', 'stop', 'restart', 'enable', 'disable'], true)) {
        return ['ok' => false, 'error' => 'Invalid action.'];
    }
    [$code, $out, $err] = helper_cmd(
        'service-action ' . escapeshellarg($action) . ' ' . escapeshellarg($name),
        30
    );
    audit('service.' . $action, $name . ' (exit ' . $code . ')');
    if ($code !== 0) {
        $msg = trim($out . ' ' . $err);
        return ['ok' => false, 'error' => $msg ?: 'Command failed (exit ' . $code . ').'];
    }
    return ['ok' => true, 'status' => service_status($name), 'enabled' => service_enabled($name)];
}
