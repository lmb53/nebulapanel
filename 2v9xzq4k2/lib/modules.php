<?php
/**
 * Module registry — single source of truth for the sidebar nav and the
 * page-route whitelist. Adding a module = add a row here + drop in the
 * matching views/<route>.php (and optionally api/<route>.php).
 *
 * Each row: 'route' => ['icon' (lucide), 'label', 'section'].
 */
function nebula_modules(): array
{
    return [
        'dashboard'  => ['layout-dashboard', 'Dashboard',    'Overview'],
        'monitoring' => ['activity',         'Monitoring',   'Overview'],

        'services'   => ['server-cog',       'Services',     'System'],
        'updates'    => ['download-cloud',   'Updates',      'System'],
        'users'      => ['users',            'Users',        'System'],
        'cron'       => ['clock',            'Cron Jobs',    'System'],
        'firewall'   => ['shield',           'Firewall',     'System'],
        'logs'       => ['scroll-text',      'Logs',         'System'],

        'databases'  => ['database',         'Databases',    'Services'],
        'docker'     => ['container',        'Docker',       'Services'],

        'files'      => ['folder-tree',      'File Manager', 'Files'],
        'backups'    => ['archive-restore',  'Backups',      'Files'],

        'terminal'   => ['square-terminal',  'Terminal',     'Tools'],
        'sysinfo'    => ['cpu',              'System Info',  'Tools'],
        'selfupdate' => ['git-branch',       'Panel Updates','Tools'],
        'settings'   => ['settings',         'Settings',     'Tools'],
    ];
}

/** Sections in display order. */
function nebula_sections(): array
{
    $order = [];
    foreach (nebula_modules() as $m) {
        if (!in_array($m[2], $order, true)) {
            $order[] = $m[2];
        }
    }
    return $order;
}

/** Routes that render a page but aren't top-level nav items. */
function nebula_extra_routes(): array
{
    return ['file-view'];
}

/** Is this a valid HTML page route? */
function is_page_route(string $route): bool
{
    return isset(nebula_modules()[$route]) || in_array($route, nebula_extra_routes(), true);
}
