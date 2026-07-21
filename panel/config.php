<?php
/**
 * Nebula Panel — configuration
 * Edit these values for your server. Everything here is safe to keep in git
 * EXCEPT nothing sensitive lives here — the admin password is created at
 * first-run setup and stored (hashed) under data/admin.json.
 */

return [
    // Display name shown in the sidebar / titles.
    'panel_name' => 'Nebula Panel',

    // Root directory the File Manager is allowed to browse. It can never
    // escape above this path. Override with env NEBULA_FM_ROOT.
    'fm_root' => getenv('NEBULA_FM_ROOT') ?: '/var/www',

    // Optional additional absolute paths to hide and reject. The panel's own
    // application and private data directories are always denied automatically.
    'fm_denied_paths' => [],

    // Services the panel is allowed to view / control via systemctl.
    // Only these can be started/stopped/restarted — nothing else.
    'services' => [
        'nginx',
        'apache2',
        'mariadb',
        'mysql',
        'php8.3-fpm',
        'php8.2-fpm',
        'redis-server',
        'docker',
        'ssh',
        'cron',
        'ufw',
    ],

    // Session idle timeout in seconds (default 30 min).
    'session_timeout' => 1800,

    // Login throttling. Failed attempts are tracked per remote IP in data/.
    'login_max_attempts' => 5,
    'login_window'       => 600,

    // Only trust X-Forwarded-Proto / X-Forwarded-For from these proxy IPs.
    // Leave empty when Nginx talks directly to PHP-FPM (the normal install).
    'trusted_proxies' => [],

    // Authoritative hostnames customers delegate their domains to.
    'nameservers' => array_values(array_filter([
        getenv('NEBULA_NS1') ?: ('ns1.' . (gethostname() ?: 'nebula.local')),
        getenv('NEBULA_NS2') ?: ('ns2.' . (gethostname() ?: 'nebula.local')),
    ])),

    // Resource thresholds used by the dashboard health summary.
    'health_warn_percent'     => 80,
    'health_critical_percent' => 90,

    // Self-update source: the GitHub repo + ref the panel updates itself from.
    'repo'     => getenv('NEBULA_REPO') ?: 'lmb53/nebulapanel',
    'repo_ref' => getenv('NEBULA_REPO_REF') ?: 'main',

    // Set true only while debugging to surface PHP errors.
    'debug' => false,
];
