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

    // Set true only while debugging to surface PHP errors.
    'debug' => false,
];
