<?php
/**
 * Websites module — manages Nginx virtual hosts via the privileged helper.
 * Nginx is the source of truth; data/sites.json tracks panel-side metadata
 * for listing. All shell tokens are escapeshellarg()'d and inputs are
 * validated with strict regexes before any helper_cmd() call.
 */

/** Is the privileged helper (which performs nginx/certbot work) installed? */
function sites_available(): bool
{
    return helper_available();
}

/** Path to the panel-side sites record. */
function sites_file(): string
{
    return APP_ROOT . '/data/sites.json';
}

/** Load the tracked sites (always an array). */
function sites_stored(): array
{
    $file = sites_file();
    if (!is_file($file)) {
        return [];
    }
    $j = json_decode((string) file_get_contents($file), true);
    return is_array($j) ? $j : [];
}

/** Read existing Nebula-style Nginx vhosts from the privileged helper. */
function sites_discover(): array
{
    if (!helper_available()) { return []; }
    [$code, $output] = helper_cmd('site-list', 30);
    if ($code !== 0) { return []; }
    $found = [];
    foreach (preg_split('/\r?\n/', trim($output)) as $line) {
        if ($line === '') { continue; }
        [$domain, $docroot, $php, $ssl] = array_pad(explode("\t", $line, 4), 4, '');
        if (!sv_domain_ok($domain) || !sv_path_ok($docroot)) { continue; }
        $found[] = [
            'domain' => $domain,
            'docroot' => $docroot,
            'php' => php_valid_version_for_site($php) ? $php : '',
            'ssl' => $ssl === 'yes',
            'server' => 'nginx',
            'created' => null,
            'discovered' => true,
        ];
    }
    return $found;
}

function php_valid_version_for_site(string $version): bool
{
    return $version === '' || (bool) preg_match('/^[0-9]+\.[0-9]+$/', $version);
}

/** Load tracked sites and reconcile any surviving Nginx vhosts. */
function sites_list(): array
{
    $stored = sites_stored();
    $byDomain = [];
    foreach ($stored as $site) {
        if (!empty($site['domain'])) { $byDomain[(string) $site['domain']] = $site; }
    }
    $changed = false;
    foreach (sites_discover() as $site) {
        $domain = (string) $site['domain'];
        if (!isset($byDomain[$domain])) {
            $byDomain[$domain] = $site;
            $changed = true;
        } else {
            // Nginx is authoritative for runtime fields after manual changes.
            foreach (['docroot', 'php', 'ssl', 'server'] as $field) {
                if (($byDomain[$domain][$field] ?? null) !== $site[$field]) {
                    $byDomain[$domain][$field] = $site[$field];
                    $changed = true;
                }
            }
        }
    }
    $sites = array_values($byDomain);
    if ($changed) { sites_save($sites); }
    return $sites;
}

/** Enrich tracked sites with their underlying web service and disk usage. */
function sites_with_runtime(): array
{
    $sites = sites_list();
    $serviceCache = [];
    foreach ($sites as &$site) {
        $server = strtolower((string) ($site['server'] ?? 'nginx'));
        if (!in_array($server, ['nginx', 'apache2'], true)) { $server = 'nginx'; }
        if (!isset($serviceCache[$server])) {
            $serviceCache[$server] = service_status($server);
        }
        $site['server'] = $server;
        $site['service'] = $serviceCache[$server];
        $site['disk_used'] = 0;
        $site['file_count'] = 0;
        $docroot = (string) ($site['docroot'] ?? '');
        if (sv_path_ok($docroot)) {
            [$c, $o] = helper_cmd('site-stats ' . escapeshellarg($docroot), 60);
            if ($c === 0) {
                [$bytes, $files] = array_pad(explode("\t", trim($o), 2), 2, 0);
                $site['disk_used'] = max(0, (int) $bytes);
                $site['file_count'] = max(0, (int) $files);
            }
            $site['disk_total'] = is_dir($docroot) ? (int) (@disk_total_space($docroot) ?: 0) : 0;
            $site['disk_free'] = is_dir($docroot) ? (int) (@disk_free_space($docroot) ?: 0) : 0;
        }
    }
    unset($site);
    return $sites;
}

/** Persist the tracked sites (pretty JSON, private perms). */
function sites_save(array $s): void
{
    file_put_contents(sites_file(), json_encode(array_values($s), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod(sites_file(), 0600);
}

/** Validate a domain name. */
function sv_domain_ok($d): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$/', (string) $d);
}

/** Validate a filesystem path (absolute, safe charset, no traversal). */
function sv_path_ok($p): bool
{
    return (bool) preg_match('#^/[A-Za-z0-9._/-]+$#', (string) $p) && strpos((string) $p, '..') === false;
}

/** Installed PHP-FPM versions reported by the helper (e.g. ["8.1","8.2"]). */
function php_versions(): array
{
    [$c, $o] = helper_cmd('php-versions', 20);
    if ($c !== 0) {
        return [];
    }
    return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $o))));
}

/** Create a new website (docroot + nginx vhost via the helper). */
function site_create(string $domain, string $docroot, string $php): array
{
    if (!sv_domain_ok($domain)) {
        return ['ok' => false, 'error' => 'Invalid domain.'];
    }
    if ($docroot === '') {
        $docroot = '/var/www/' . $domain;
    }
    if (!sv_path_ok($docroot)) {
        return ['ok' => false, 'error' => 'Invalid document root.'];
    }
    if (!in_array($php, php_versions(), true)) {
        return ['ok' => false, 'error' => 'PHP version not installed.'];
    }
    foreach (sites_list() as $s) {
        if (($s['domain'] ?? '') === $domain) {
            return ['ok' => false, 'error' => 'A site with that domain already exists.'];
        }
    }
    [$c, $o] = helper_cmd(
        'site-create ' . escapeshellarg($domain) . ' ' . escapeshellarg($docroot) . ' ' . escapeshellarg($php)
    );
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'site-create failed'];
    }
    // sites_list() may have already rediscovered the vhost the helper just
    // created. Enrich that record instead of adding a duplicate row.
    $sites = sites_list();
    $record = [
        'domain' => $domain, 'docroot' => $docroot, 'php' => $php,
        'ssl' => false, 'server' => 'nginx', 'created' => date('c'),
    ];
    $recorded = false;
    foreach ($sites as &$site) {
        if (($site['domain'] ?? '') === $domain) {
            $site = array_merge($site, $record);
            unset($site['discovered']);
            $recorded = true;
            break;
        }
    }
    unset($site);
    if (!$recorded) { $sites[] = $record; }
    sites_save($sites);
    audit('site.create', $domain);
    return ['ok' => true];
}

/** Delete a website vhost (and its docroot when $purge). */
function site_delete(string $domain, bool $purge = false): array
{
    if (!sv_domain_ok($domain)) {
        return ['ok' => false, 'error' => 'Invalid domain.'];
    }
    $sites = sites_list();
    $docroot = '';
    foreach ($sites as $s) {
        if (($s['domain'] ?? '') === $domain) {
            $docroot = (string) ($s['docroot'] ?? '');
            break;
        }
    }
    $args = 'site-delete ' . escapeshellarg($domain) . ' ' . ($purge ? 'purge' : 'no');
    if ($purge && $docroot !== '') {
        $args .= ' ' . escapeshellarg($docroot);
    }
    [$c, $o] = helper_cmd($args);
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'site-delete failed'];
    }
    $sites = array_values(array_filter($sites, fn($s) => ($s['domain'] ?? '') !== $domain));
    sites_save($sites);
    audit('site.delete', $domain);
    return ['ok' => true];
}

/** Issue a Let's Encrypt certificate for a site (certbot --nginx via helper). */
function site_ssl(string $domain, string $email = ''): array
{
    if (!sv_domain_ok($domain)) {
        return ['ok' => false, 'error' => 'Invalid domain.'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email.'];
    }
    $args = 'site-ssl ' . escapeshellarg($domain);
    if ($email !== '') {
        $args .= ' ' . escapeshellarg($email);
    }
    [$c, $o] = helper_cmd($args, 300);
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'site-ssl failed'];
    }
    $sites = sites_list();
    foreach ($sites as &$s) {
        if (($s['domain'] ?? '') === $domain) {
            $s['ssl'] = true;
            break;
        }
    }
    unset($s);
    sites_save($sites);
    audit('site.ssl', $domain);
    return ['ok' => true];
}

/** Switch an existing managed Nginx website to another installed PHP-FPM version. */
function site_set_php(string $domain, string $version): array
{
    if (!sv_domain_ok($domain)) { return ['ok' => false, 'error' => 'Invalid domain.']; }
    if (!in_array($version, php_versions(), true)) { return ['ok' => false, 'error' => 'PHP version is not installed.']; }
    $found = false;
    foreach (sites_list() as $site) {
        if (($site['domain'] ?? '') === $domain) { $found = true; break; }
    }
    if (!$found) { return ['ok' => false, 'error' => 'Website not found.']; }
    [$code, $output] = helper_cmd('site-php ' . escapeshellarg($domain) . ' ' . escapeshellarg($version));
    if ($code !== 0) { return ['ok' => false, 'error' => trim($output) ?: 'Could not switch PHP version.']; }
    $sites = sites_list();
    foreach ($sites as &$site) { if (($site['domain'] ?? '') === $domain) { $site['php'] = $version; } }
    unset($site); sites_save($sites);
    audit('site.php', $domain . ' -> ' . $version);
    return ['ok' => true];
}
