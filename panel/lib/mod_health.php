<?php
/** Consolidated operational health checks for the dashboard. */

function health_summary(): array
{
    global $config;
    require_once APP_ROOT . '/lib/mod_apps.php';
    require_once APP_ROOT . '/lib/mod_backups.php';
    require_once APP_ROOT . '/lib/mod_updates.php';

    $warn = max(1, min(99, (int) ($config['health_warn_percent'] ?? 80)));
    $critical = max($warn + 1, min(100, (int) ($config['health_critical_percent'] ?? 90)));
    $items = [];
    $add = static function (string $level, string $title, string $detail, string $route, string $icon) use (&$items): void {
        $items[] = compact('level', 'title', 'detail', 'route', 'icon');
    };

    $disk = disk_info('/');
    if ($disk) {
        $pct = round($disk['used'] / max(1, $disk['total']) * 100, 1);
        if ($pct >= $critical) {
            $add('critical', 'Disk space critically low', $pct . '% used on / (' . human_bytes($disk['free']) . ' free)', 'files', 'hard-drive');
        } elseif ($pct >= $warn) {
            $add('warning', 'Disk space running low', $pct . '% used on / (' . human_bytes($disk['free']) . ' free)', 'files', 'hard-drive');
        }
    }

    $mem = mem_info();
    if ($mem) {
        $pct = round($mem['used'] / max(1, $mem['total']) * 100, 1);
        if ($pct >= $critical) {
            $add('critical', 'Memory pressure is critical', $pct . '% of RAM is in use', 'dashboard', 'memory-stick');
        } elseif ($pct >= $warn) {
            $add('warning', 'Memory pressure is elevated', $pct . '% of RAM is in use', 'dashboard', 'memory-stick');
        }
    }

    $cores = max(1, (int) (system_facts()['cpu_cores'] ?? 1));
    $load = load_avg();
    if (($load[0] ?? 0) >= $cores * 1.5) {
        $add('warning', 'System load is high', '1-minute load ' . round($load[0], 2) . ' across ' . $cores . ' vCPU', 'dashboard', 'activity');
    }

    $allowed = array_values(array_unique(array_merge((array) ($config['services'] ?? []), manageable_units())));
    $failed = [];
    foreach ($allowed as $service) {
        if (service_status($service) === 'failed') {
            $failed[] = $service;
        }
    }
    if ($failed) {
        $add('critical', count($failed) . ' failed service' . (count($failed) === 1 ? '' : 's'), implode(', ', $failed), 'services', 'server-off');
    }

    if (is_file('/var/run/reboot-required')) {
        $add('warning', 'Reboot required', 'A package update requires a server reboot', 'updates', 'power');
    }

    $updateCount = upd_count();
    if ($updateCount > 0) {
        $add($updateCount >= 20 ? 'warning' : 'info', $updateCount . ' package update' . ($updateCount === 1 ? '' : 's') . ' available', 'Review and install operating-system updates', 'updates', 'download-cloud');
    }

    $backups = backup_list();
    if (!$backups) {
        $add('info', 'No panel backups yet', 'Create an archive of each important website or configuration', 'backups', 'archive');
    } elseif (time() - (int) $backups[0]['mtime'] > 7 * 86400) {
        $add('warning', 'Latest backup is over 7 days old', 'Most recent: ' . date('Y-m-d H:i', (int) $backups[0]['mtime']), 'backups', 'archive');
    }

    if (!is_writable(DATA_DIR)) {
        $add('critical', 'Panel data directory is not writable', DATA_DIR, 'diagnostics', 'database-zap');
    }
    if (!helper_available()) {
        $add('warning', 'Privileged helper is missing', 'Re-run install.sh to restore hosting, SSL, and PHP actions', 'diagnostics', 'wrench');
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
