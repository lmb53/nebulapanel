<?php
/** Consolidated operational health checks for the dashboard. */

function health_summary(): array
{
    global $config;
    require_once APP_ROOT . '/lib/mod_apps.php';
    require_once APP_ROOT . '/lib/mod_backups.php';
    require_once APP_ROOT . '/lib/mod_updates.php';
    require_once APP_ROOT . '/lib/mod_ssl.php';

    $warn = max(1, min(99, (int) ($config['health_warn_percent'] ?? 80)));
    $critical = max($warn + 1, min(100, (int) ($config['health_critical_percent'] ?? 90)));
    $items = [];
    $add = static function (string $level, string $title, string $detail, string $route, string $icon, string $key = '') use (&$items): void {
        $items[] = compact('level', 'title', 'detail', 'route', 'icon', 'key');
    };

    $disk = disk_info('/');
    if ($disk) {
        $pct = round($disk['used'] / max(1, $disk['total']) * 100, 1);
        if ($pct >= $critical) {
            $add('critical', 'Disk space critically low', $pct . '% used on / (' . human_bytes($disk['free']) . ' free)', 'files', 'hard-drive', 'disk');
        } elseif ($pct >= $warn) {
            $add('warning', 'Disk space running low', $pct . '% used on / (' . human_bytes($disk['free']) . ' free)', 'files', 'hard-drive', 'disk');
        }
    }

    $mem = mem_info();
    if ($mem) {
        $pct = round($mem['used'] / max(1, $mem['total']) * 100, 1);
        if ($pct >= $critical) {
            $add('critical', 'Memory pressure is critical', $pct . '% of RAM is in use', 'dashboard', 'memory-stick', 'memory');
        } elseif ($pct >= $warn) {
            $add('warning', 'Memory pressure is elevated', $pct . '% of RAM is in use', 'dashboard', 'memory-stick', 'memory');
        }
    }

    $cores = max(1, (int) (system_facts()['cpu_cores'] ?? 1));
    $load = load_avg();
    if (($load[0] ?? 0) >= $cores * 1.5) {
        $add('warning', 'System load is high', '1-minute load ' . round($load[0], 2) . ' across ' . $cores . ' vCPU', 'dashboard', 'activity', 'load');
    }

    $allowed = array_values(array_unique(array_merge((array) ($config['services'] ?? []), manageable_units())));
    $failed = [];
    foreach ($allowed as $service) {
        if (service_status($service) === 'failed') {
            $failed[] = $service;
        }
    }
    if ($failed) {
        $add('critical', count($failed) . ' failed service' . (count($failed) === 1 ? '' : 's'), implode(', ', $failed), 'services', 'server-off', 'services');
    }

    if (is_file('/var/run/reboot-required')) {
        $add('warning', 'Reboot required', 'A package update requires a server reboot', 'updates', 'power', 'reboot');
    }

    $updateCount = upd_count();
    if ($updateCount > 0) {
        $add($updateCount >= 20 ? 'warning' : 'info', $updateCount . ' package update' . ($updateCount === 1 ? '' : 's') . ' available', 'Review and install operating-system updates', 'updates', 'download-cloud', 'updates');
    }

    foreach (backup_health_items() as $item) {
        $items[] = $item;
    }

    foreach (ssl_health_items() as $item) {
        $items[] = $item;
    }

    require_once APP_ROOT . '/lib/mod_scheduler.php';
    if (($scheduler = scheduler_health_item()) !== null) {
        $items[] = $scheduler;
    }

    if (!is_writable(DATA_DIR)) {
        $add('critical', 'Panel data directory is not writable', DATA_DIR, 'diagnostics', 'database-zap', 'data_dir');
    }
    if (!helper_available()) {
        $add('warning', 'Privileged helper is missing', 'Re-run install.sh to restore hosting, SSL, and PHP actions', 'diagnostics', 'wrench', 'helper');
    }

    $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
    usort($items, fn($a, $b) => ($rank[$a['level']] ?? 9) <=> ($rank[$b['level']] ?? 9));
    $criticalCount = count(array_filter($items, fn($item) => $item['level'] === 'critical'));
    $warningCount = count(array_filter($items, fn($item) => $item['level'] === 'warning'));

    return [
        'ok' => true,
        'status' => $criticalCount ? 'critical' : ($warningCount ? 'warning' : 'healthy'),
        'counts' => ['critical' => $criticalCount, 'warning' => $warningCount, 'total' => count($items)],
        'items' => $items,
        'checked_at' => time(),
    ];
}

/**
 * Certificates that are expiring or already invalid. certbot renews 30 days
 * before expiry, so a certificate inside 21 days means renewal is failing.
 * The certbot listing is slow, so it is cached for an hour.
 */
function ssl_health_items(): array
{
    if (!helper_available() && !is_file(ssl_custom_file())) { return []; }
    $certs = cache_remember('ssl-expiry', 3600, static function (): array {
        $res = ssl_available() ? ssl_list() : ['ok' => true, 'certs' => ssl_custom_list()];
        return array_map(static fn($c) => ['name' => (string) ($c['name'] ?? ''), 'days' => $c['days'] ?? null,
            'valid' => (bool) ($c['valid'] ?? true)], (array) ($res['certs'] ?? []));
    });
    $critical = [];
    $warning = [];
    foreach ((array) $certs as $cert) {
        $days = $cert['days'];
        if (!$cert['valid'] || ($days !== null && (int) $days <= 7)) {
            $critical[] = $cert['name'] . ($cert['valid'] && $days !== null ? ' (' . (int) $days . ' day' . ((int) $days === 1 ? '' : 's') . ')' : ' (expired)');
        } elseif ($days !== null && (int) $days <= 21) {
            $warning[] = $cert['name'] . ' (' . (int) $days . ' days)';
        }
    }
    $items = [];
    if ($critical) {
        $items[] = ['level' => 'critical', 'key' => 'ssl', 'title' => count($critical) . ' SSL certificate' . (count($critical) === 1 ? '' : 's') . ' expired or expiring within 7 days',
            'detail' => implode(', ', $critical), 'route' => 'ssl', 'icon' => 'shield-x'];
    }
    if ($warning) {
        $items[] = ['level' => 'warning', 'key' => 'ssl', 'title' => count($warning) . ' SSL certificate' . (count($warning) === 1 ? '' : 's') . ' expiring soon — auto-renewal may be failing',
            'detail' => implode(', ', $warning), 'route' => 'ssl', 'icon' => 'shield-alert'];
    }
    return $items;
}
