<?php
/**
 * App catalog + PHP version management + dynamic service discovery.
 * Package and PHP operations are validated by the privileged helper.
 */

/**
 * Installable server software. unit '' = no systemd service.
 *
 * 'logo' is an official brand mark under assets/ (the lucide 'icon' stays as
 * the fallback for the marks we don't ship). 'helper' routes install/uninstall
 * through a privileged helper command instead of a plain apt-get, for packages
 * that also need configuration wiring.
 */
function app_catalog(): array
{
    return [
        'apache2'     => ['label' => 'Apache',      'pkg' => 'apache2',        'unit' => 'apache2',      'icon' => 'server',       'logo' => 'logos/apache.svg',      'desc' => 'Apache HTTP server'],
        'mariadb'     => ['label' => 'MariaDB',     'pkg' => 'mariadb-server', 'unit' => 'mariadb',      'icon' => 'database-zap', 'logo' => 'logos/mariadb.svg',     'desc' => 'MariaDB database server'],
        'redis'       => ['label' => 'Redis',       'pkg' => 'redis-server',   'unit' => 'redis-server', 'icon' => 'zap',          'logo' => 'logos/redis.svg',       'desc' => 'In-memory data store & cache'],
        'memcached'   => ['label' => 'Memcached',   'pkg' => 'memcached',      'unit' => 'memcached',    'icon' => 'zap',          'logo' => '',                      'desc' => 'Distributed memory cache'],
        'docker'      => ['label' => 'Docker',      'pkg' => 'docker.io',      'unit' => 'docker',       'icon' => 'container',    'logo' => 'logos/docker.svg',      'desc' => 'Container runtime'],
        'fail2ban'    => ['label' => 'Fail2Ban',    'pkg' => 'fail2ban',       'unit' => 'fail2ban',     'icon' => 'shield-ban',   'logo' => '',                      'desc' => 'Brute-force / intrusion prevention'],
        'modsecurity' => ['label' => 'ModSecurity', 'pkg' => 'libnginx-mod-http-modsecurity', 'unit' => '', 'icon' => 'shield-alert', 'logo' => 'logos/modsecurity.svg', 'desc' => 'Nginx web application firewall (OWASP CRS)', 'helper' => 'modsec'],
        'certbot'     => ['label' => 'Certbot',     'pkg' => 'certbot',        'unit' => '',             'icon' => 'shield-check', 'logo' => 'logos/certbot.svg',     'desc' => "Let's Encrypt SSL client"],
        'git'         => ['label' => 'Git',         'pkg' => 'git',            'unit' => '',             'icon' => 'git-branch',   'logo' => 'logos/git.svg',         'desc' => 'Distributed version control'],
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
    if (!empty($c['helper'])) {
        return app_helper_run($c['helper'] . '-install', 'app.install', $c['pkg'], $onOutput);
    }
    $cmd = 'DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Use-Pty=0 -o APT::Color=0 install -y ' . escapeshellarg($c['pkg']);
    [$code, $out] = $onOutput ? sudo_cmd_stream($cmd, $onOutput, 600) : sudo_cmd($cmd, 600);
    audit('app.install', $c['pkg'] . ' (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    if (!empty($c['unit'])) {
        [$enableCode, $enableOut] = helper_cmd('service-action enable ' . escapeshellarg($c['unit']));
        [$startCode, $startOut] = helper_cmd('service-action start ' . escapeshellarg($c['unit']));
        if ($enableCode !== 0 || $startCode !== 0) {
            return [
                'ok'=>false,
                'partial'=>true,
                'error'=>'Package installed, but its service did not reach the requested state: '
                    . sudo_error(trim($enableOut . "\n" . $startOut), $startCode ?: $enableCode),
                'output'=>$out,
            ];
        }
    }
    return ['ok' => true, 'output' => $out];
}

function app_uninstall(string $key, ?callable $onOutput = null): array
{
    $c = app_catalog()[$key] ?? null;
    if (!$c) {
        return ['ok' => false, 'error' => 'Unknown app.'];
    }
    if (!empty($c['helper'])) {
        return app_helper_run($c['helper'] . '-uninstall', 'app.uninstall', $c['pkg'], $onOutput);
    }
    $cmd = 'DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Use-Pty=0 -o APT::Color=0 remove -y ' . escapeshellarg($c['pkg']);
    [$code, $out] = $onOutput ? sudo_cmd_stream($cmd, $onOutput, 600) : sudo_cmd($cmd, 600);
    audit('app.uninstall', $c['pkg'] . ' (exit ' . $code . ')');
    return $code === 0 ? ['ok' => true, 'output' => $out] : ['ok' => false, 'error' => sudo_error($out, $code)];
}

/** Run a helper-backed catalog action (install/uninstall) with the same shape. */
function app_helper_run(string $command, string $event, string $pkg, ?callable $onOutput): array
{
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed — re-run install.sh.'];
    }
    [$code, $out] = $onOutput
        ? helper_cmd_stream($command, $onOutput, 900)
        : helper_cmd($command, 900);
    audit($event, $pkg . ' (exit ' . $code . ')');
    if ($code !== 0) {
        $err = trim($out);
        if (stripos($err, 'unknown command') !== false || stripos($err, 'usage') !== false) {
            $err = 'The privileged helper on this server is out of date. Update the panel (Panel Updates) or re-run install.sh.';
        }
        return ['ok' => false, 'error' => $err ?: 'Command failed (exit ' . $code . ').'];
    }
    return ['ok' => true, 'output' => $out];
}

// --- PHP versions ---------------------------------------------------------

function php_installed_versions(): array
{
    $v = [];
    foreach (glob('/etc/php/*', GLOB_ONLYDIR) ?: [] as $d) {
        $b = basename($d);
        // Only count a version whose runtime binary is actually present. `apt
        // remove` (without purge) leaves /etc/php/<ver> config behind, which
        // would otherwise be reported as an installed version.
        if (preg_match('/^\d+\.\d+$/', $b)
            && (is_executable("/usr/sbin/php-fpm$b") || is_executable("/usr/bin/php$b"))) {
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
    $all = ['8.2', '8.3', '8.4', '8.5'];
    return array_values(array_diff($all, php_installed_versions()));
}

function php_install(string $ver, ?callable $onOutput = null): array
{
    if (!in_array($ver, php_installable_versions(), true)) {
        return ['ok' => false, 'error' => 'This PHP version is unavailable or already installed.'];
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
