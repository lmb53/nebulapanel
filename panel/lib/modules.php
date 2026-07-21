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

        'websites'   => ['globe',            'Websites',     'Hosting'],
        'files'      => ['folder-tree',      'File Manager', 'Hosting'],
        'domains'    => ['earth',            'Domains',      'Hosting'],
        'dns'        => ['network',          'DNS',          'Hosting'],
        'ssl'        => ['shield-check',     'SSL',          'Hosting'],
        'php'        => ['code-2',           'PHP',          'Hosting'],
        'databases'  => ['database',         'Databases',    'Hosting'],
        'phpmyadmin' => ['table-properties', 'phpMyAdmin',   'Hosting'],

        'services'   => ['server-cog',       'Services',     'System'],
        'apps'       => ['package-plus',     'Install Apps', 'System'],
        'updates'    => ['download-cloud',   'Updates',      'System'],
        'users'      => ['users',            'Users',        'System'],
        'sshkeys'    => ['key-round',        'SSH Keys',     'System'],
        'cron'       => ['clock',            'Cron Jobs',    'System'],
        'firewall'   => ['shield',           'Firewall',     'System'],
        'logs'       => ['scroll-text',      'Logs',         'System'],

        'docker'     => ['container',        'Docker',       'Services'],

        'terminal'   => ['square-terminal',  'Terminal',     'Tools'],
        'backups'    => ['archive-restore',  'Backups',      'Tools'],
        'sysinfo'    => ['cpu',              'System Info',  'Tools'],
        'diagnostics'=> ['stethoscope',      'Diagnostics',  'Tools'],
        'notifications'=>['bell',            'Notifications','Tools'],
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
    return ['file-view', 'file-edit', 'service'];
}

/** Is this a valid HTML page route? */
function is_page_route(string $route): bool
{
    return isset(nebula_modules()[$route]) || in_array($route, nebula_extra_routes(), true);
}
