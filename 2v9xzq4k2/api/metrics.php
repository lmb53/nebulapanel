<?php
/** GET api/metrics — live CPU / memory / disk / load / uptime. */
$mem = mem_info();
$disk = disk_info('/');
json_out([
    'ok'   => true,
    'ts'   => time(),
    'cpu'  => cpu_usage(),
    'load' => load_avg(),
    'mem'  => $mem ? [
        'total' => $mem['total'],
        'used'  => $mem['used'],
        'pct'   => round($mem['used'] / max(1, $mem['total']) * 100, 1),
    ] : null,
    'disk' => $disk ? [
        'total' => $disk['total'],
        'used'  => $disk['used'],
        'pct'   => round($disk['used'] / max(1, $disk['total']) * 100, 1),
    ] : null,
    'uptime' => format_uptime(uptime_seconds()),
]);
