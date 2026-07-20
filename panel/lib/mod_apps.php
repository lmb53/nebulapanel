<?php
/**
 * App catalog + PHP version management + dynamic service discovery.
 * apt operations use the (SETENV) apt-get sudo rule; PHP version installs go
 * through the privileged helper (needs the ondrej PPA on Ubuntu).
 */

/** Installable server software. unit '' = no systemd service. */
function app_catalog(): array
{
    return [
        'apache2'   => ['label' => 'Apache',    'pkg' => 'apache2',        'unit' => 'apache2',       'icon' => 'server',        'desc' => 'Apache HTTP server'],
        'mariadb'   => ['label' => 'MariaDB',   'pkg' => 'mariadb-server', 'unit' => 'mariadb',       'icon' => 'database-zap',  'desc' => 'MariaDB database server'],
        'redis'     => ['label' => 'Redis',     'pkg' => 'redis-server',   'unit' => 'redis-server',  'icon' => 'zap',           'desc' => 'In-memory data store & cache'],
        'memcached' => ['label' => 'Memcached', 'pkg' => 'memcached',      'unit' => 'memcached',     'icon' => 'zap',           'desc' => 'Distributed memory cache'],
        'docker'    => ['label' => 'Docker',    'pkg' => 'docker.io',      'unit' => 'docker',        'icon' => 'container',     'desc' => 'Container runtime'],
        'fail2ban'  => ['label' => 'Fail2Ban',  'pkg' => 'fail2ban',       'unit' => 'fail2ban',      'icon' => 'shield-ban',    'desc' => 'Brute-force / intrusion prevention'],
        'certbot'   => ['label' => 'Certbot',   'pkg' => 'certbot',        'unit' => '',              'icon' => 'shield-check',  'desc' => "Let's Encrypt SSL client"],
        'git'       => ['label' => 'Git',       'pkg' => 'git',            'unit' => '',              'icon' => 'git-branch',    'desc' => 'Distributed version control'],
    ];
}

/** Fast systemd unit-file presence check (used for nav; avoids dpkg per page). */
function unit_exists(string $unit): bool
{
    foreach (['/lib/systemd/system', '/etc/systemd/system', '/usr/lib/systemd/system'] as $d) {
        if (is_file("$d/$unit.service")) {
            return true;
        }
    }
    return false;
}

/** Authoritative install check via dpkg (used on the catalog page). */
function app_installed(string $key): bool
{
    $c = app_catalog()[$key] ?? null;
    if (!$c) {
        return false;
    }
    [$code] = run_cmd('dpkg -s ' . escapeshellarg($c['pkg']) . ' 2>/dev/null | grep -q "Status: install ok installed"');
    return $code === 0;
}

function app_install(string $key, ?callable $onOutput = null): array
{
    $c = app_catalog()[$key] ?? null;
    if (!$c) {
        return ['ok' => false, 'error' => 'Unknown app.'];
    }
    $cmd = 'DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Use-Pty=0 -o APT::Color=0 install -y ' . escapeshellarg($c['pkg']);
    [$code, $out] = $onOutput ? sudo_cmd_stream($cmd, $onOutput, 600) : sudo_cmd($cmd, 600);
    audit('app.install', $c['pkg'] . ' (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    if (!empty($c['unit'])) {
        sudo_cmd('systemctl enable --now ' . escapeshellarg($c['unit']));
    }
    return ['ok' => true, 'output' => $out];
}

function app_uninstall(string $key, ?callable $onOutput = null): array
{
    $c = app_catalog()[$key] ?? null;
    if (!$c) {
        return ['ok' => false, 'error' => 'Unknown app.'];
    }
    $cmd = 'DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Use-Pty=0 -o APT::Color=0 remove -y ' . escapeshellarg($c['pkg']);
    [$code, $out] = $onOutput ? sudo_cmd_stream($cmd, $onOutput, 600) : sudo_cmd($cmd, 600);
    audit('app.uninstall', $c['pkg'] . ' (exit ' . $code . ')');
    return $code === 0 ? ['ok' => true, 'output' => $out] : ['ok' => false, 'error' => sudo_error($out, $code)];
}

// --- PHP versions ---------------------------------------------------------

function php_installed_versions(): array
{
    $v = [];
    foreach (glob('/etc/php/*', GLOB_ONLYDIR) ?: [] as $d) {
        $b = basename($d);
        if (preg_match('/^\d+\.\d+$/', $b)) {
            $v[] = $b;
        }
    }
    if (!$v) {
        foreach (glob('/run/php/php*-fpm.sock') ?: [] as $s) {
            if (preg_match('/php([\d.]+)-fpm/', $s, $m)) {
                $v[] = $m[1];
            }
        }
    }
    $v = array_values(array_unique($v));
    sort($v);
    return $v;
}

function php_installable_versions(): array
{
    $all = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];
    return array_values(array_diff($all, php_installed_versions()));
}

function php_install(string $ver, ?callable $onOutput = null): array
{
    if (!preg_match('/^\d+\.\d+$/', $ver)) {
        return ['ok' => false, 'error' => 'Invalid version.'];
    }
    $args = 'php-install ' . escapeshellarg($ver);
    [$code, $out] = $onOutput ? helper_cmd_stream($args, $onOutput, 900) : helper_cmd($args, 900);
    audit('php.install', $ver . ' (exit ' . $code . ')');
    return $code === 0 ? ['ok' => true, 'output' => $out] : ['ok' => false, 'error' => trim($out) ?: 'install failed'];
}

// --- Dynamic service discovery (for the sidebar + generic manager) ---------

/**
 * Installed, panel-manageable services: [ ['unit','label','icon'], ... ].
 * Uses fast unit-file checks so it's cheap to call on every page render.
 */
function manageable_services(): array
{
    $out = [];
    if (unit_exists('nginx')) {
        $out[] = ['unit' => 'nginx', 'label' => 'Nginx', 'icon' => 'server-cog'];
    }
    foreach (app_catalog() as $c) {
        if (!empty($c['unit']) && unit_exists($c['unit'])) {
            $out[] = ['unit' => $c['unit'], 'label' => $c['label'], 'icon' => $c['icon']];
        }
    }
    foreach (php_installed_versions() as $v) {
        if (unit_exists("php$v-fpm") || is_file("/run/php/php$v-fpm.sock")) {
            $out[] = ['unit' => "php$v-fpm", 'label' => "PHP $v FPM", 'icon' => 'code-2'];
        }
    }
    // De-dup by unit.
    $seen = [];
    return array_values(array_filter($out, function ($s) use (&$seen) {
        if (isset($seen[$s['unit']])) {
            return false;
        }
        $seen[$s['unit']] = true;
        return true;
    }));
}

/** Just the unit names — used to authorise service actions. */
function manageable_units(): array
{
    return array_map(fn($s) => $s['unit'], manageable_services());
}
