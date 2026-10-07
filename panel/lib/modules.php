<?php
/**
 * Module registry — single source of truth for the sidebar nav and the
 * page-route whitelist. Adding a module = add a row here + drop in the
 * matching views/<route>.php (and optionally api/<route>.php).
 *
 * Each row: 'route' => ['icon' (lucide), 'label', 'section', 'search keywords'].
 * The label is also the page's H1 and browser title, so the two never drift.
 */
function nebula_modules(): array
{
    return [
        'dashboard'  => ['layout-dashboard', 'Dashboard',    'Overview', 'home overview metrics cpu memory disk health processes'],
        'websites'   => ['globe',            'Websites',     'Hosting',  'sites vhost domain nginx git deploy'],
        'files'      => ['folder-tree',      'File Manager', 'Hosting',  'files upload editor trash permissions chmod'],
        'domains'    => ['earth',            'Domains',      'Hosting',  'dns a record ip pointing'],
        'dns'        => ['network',          'DNS',          'Hosting',  'zone records nameservers mx txt cname bind'],
        'ssl'        => ['shield-check',     'SSL Certificates', 'Hosting', 'https tls certbot letsencrypt certificate'],
        'php'        => ['code-2',           'PHP',          'Hosting',  'fpm ini opcache extensions xdebug composer version'],
        'databases'  => ['database',         'Databases',    'Hosting',  'mysql mariadb sql users'],
        'phpmyadmin' => ['table-properties', 'phpMyAdmin',   'Hosting',  'pma database admin sql'],
        'mail'       => ['mail',             'Email',        'Hosting',  'mailbox postfix dovecot dkim spf dmarc webmail roundcube'],

        'services'   => ['server-cog',       'Services',     'System',   'systemd start stop restart nginx redis'],
        'apps'       => ['package-plus',     'Install Apps', 'System',   'packages install apt php version'],
        'updates'    => ['download-cloud',   'Updates',      'System',   'apt upgrade packages panel self-update version'],
        'users'      => ['users',            'Users',        'System',   'accounts roles rbac access linux'],
        'sshkeys'    => ['key-round',        'SSH Keys',     'System',   'authorized keys ssh'],
        'cron'       => ['clock',            'Cron Jobs',    'System',   'crontab schedule tasks'],
        'firewall'   => ['shield',           'Security',     'System',   'firewall ufw fail2ban modsecurity waf ban audit log'],
        'logs'       => ['scroll-text',      'Logs',         'System',   'journal journalctl syslog errors'],
        'docker'     => ['container',        'Docker',       'System',   'containers images compose stacks app store volumes'],

        'terminal'   => ['square-terminal',  'Terminal',     'Tools',    'shell command bash console'],
        'backups'    => ['archive-restore',  'Backups',      'Tools',    'archive restore tar download'],
        'sysinfo'    => ['cpu',              'System Info',  'Tools',    'hardware kernel os network interfaces'],
        'diagnostics'=> ['stethoscope',      'Diagnostics',  'Tools',    'checks helper sudo privileges'],
        'notifications'=>['bell',            'Notifications','Tools',    'alerts inbox'],
        'settings'   => ['settings',         'Settings',     'Tools',    'preferences panel name timeout thresholds'],
        'api'        => ['braces',           'API Tokens',   'Tools',    'bearer token automation'],
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

/**
 * Routes that render a page but aren't top-level nav items, mapped to the nav
 * item that should be highlighted while they are open (null = none).
 */
function nebula_extra_route_parents(): array
{
    return ['file-edit' => 'files', 'service' => 'services', 'selfupdate' => 'updates', 'account' => null];
}

function nebula_extra_routes(): array
{
    return array_keys(nebula_extra_route_parents());
}

/** Is this a valid HTML page route? */
function is_page_route(string $route): bool
{
    return isset(nebula_modules()[$route]) || in_array($route, nebula_extra_routes(), true);
}

/** The nav item to highlight for a route ('' when none applies). */
function nav_active_route(string $route): string
{
    if (isset(nebula_modules()[$route])) {
        return $route;
    }
    return (string) (nebula_extra_route_parents()[$route] ?? '');
}

/** Human page title for a route, used for <title>. */
function page_title_for(string $route): string
{
    $modules = nebula_modules();
    if (isset($modules[$route])) {
        return $modules[$route][1];
    }
    switch ($route) {
        case 'file-edit':
            $name = basename(str_replace('\\', '/', (string) ($_GET['path'] ?? '')));
            return $name !== '' ? $name : 'Editor';
        case 'service':
            return 'Service ' . (string) ($_GET['name'] ?? '');
        case 'selfupdate':
            return 'Updates';
        case 'account':
            return 'My account';
    }
    return '';
}
