<?php
/** GET api/metrics — live CPU / memory / disk / load / uptime. */
require_once APP_ROOT . '/lib/mod_history.php';
$mem = mem_info();
$disk = disk_info('/');
$cpu = cpu_usage();
$load = load_avg();
$memPct = $mem ? round($mem['used'] / max(1, $mem['total']) * 100, 1) : null;
$diskPct = $disk ? round($disk['used'] / max(1, $disk['total']) * 100, 1) : null;
// Fallback history source for installs without the scheduler timer; a no-op
// when a sample was recorded within the last five minutes.
history_record(false, ['cpu' => $cpu, 'mem' => $memPct, 'disk' => $diskPct, 'load' => isset($load[0]) ? round((float) $load[0], 2) : null]);
json_out([
    'ok'   => true,
    'ts'   => time(),
    'cpu'  => $cpu,
    'load' => $load,
    'mem'  => $mem ? [
        'total' => $mem['total'],
        'used'  => $mem['used'],
        'pct'   => $memPct,
    ] : null,
    'disk' => $disk ? [
        'total' => $disk['total'],
        'used'  => $disk['used'],
        'pct'   => $diskPct,
    ] : null,
    'uptime' => format_uptime(uptime_seconds()),
]);
