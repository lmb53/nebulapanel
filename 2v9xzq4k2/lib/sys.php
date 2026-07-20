<?php
/**
 * System introspection + service control.
 * Linux-first (reads /proc, uses systemctl). Every function degrades
 * gracefully to null / 'n/a' on non-Linux so the UI never fatals.
 */

/** Run a shell command, return [exitCode, stdout, stderr]. */
function run_cmd(string $cmd, int $timeout = 15): array
{
    if (!function_exists('proc_open')) {
        return [127, '', 'proc_open disabled'];
    }
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        return [127, '', 'could not start process'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $start = time();
    do {
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }
        if (time() - $start > $timeout) {
            proc_terminate($proc);
            break;
        }
        usleep(50000);
    } while (true);
    $out .= stream_get_contents($pipes[1]);
    $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$status['exitcode'] ?? $code, trim($out), trim($err)];
}

function is_linux(): bool
{
    return PHP_OS_FAMILY === 'Linux' && is_dir('/proc');
}

/** CPU usage percent, sampled over ~200ms from /proc/stat. */
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
    $a = $read();
    if (!$a) {
        return null;
    }
    usleep(200000);
    $b = $read();
    if (!$b) {
        return null;
    }
    $dTotal = $b[1] - $a[1];
    $dIdle = $b[0] - $a[0];
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
        $rows[] = ['name' => $svc, 'status' => service_status($svc)];
    }
    return $rows;
}

/**
 * Perform a service action. $action in start|stop|restart.
 * Requires the web user to have a sudoers rule for systemctl (see README).
 */
function service_action(string $name, string $action, array $whitelist): array
{
    if (!in_array($name, $whitelist, true)) {
        return ['ok' => false, 'error' => 'Service not allowed.'];
    }
    if (!in_array($action, ['start', 'stop', 'restart'], true)) {
        return ['ok' => false, 'error' => 'Invalid action.'];
    }
    $cmd = sprintf('sudo -n systemctl %s %s 2>&1', escapeshellarg($action), escapeshellarg($name));
    [$code, $out, $err] = run_cmd($cmd, 30);
    audit('service.' . $action, $name . ' (exit ' . $code . ')');
    if ($code !== 0) {
        $msg = trim($out . ' ' . $err);
        if (stripos($msg, 'a password is required') !== false || stripos($msg, 'sudo') !== false) {
            $msg = 'Permission denied. The web user needs a sudoers rule for systemctl (see README).';
        }
        return ['ok' => false, 'error' => $msg ?: 'Command failed (exit ' . $code . ').'];
    }
    return ['ok' => true, 'status' => service_status($name)];
}
