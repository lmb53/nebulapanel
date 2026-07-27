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
        [$id, $domain, $docroot, $php, $ssl] = array_pad(explode("\t", $line, 5), 5, '');
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !sv_domain_ok($domain) || !sv_managed_path_ok($docroot, $id)) { continue; }
        $found[] = [
            'id' => $id,
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
            foreach (['id', 'docroot', 'php', 'ssl', 'server'] as $field) {
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
        $id = (string) ($site['id'] ?? '');
        if (sv_managed_path_ok($docroot, $id)) {
            [$c, $o] = helper_cmd('site-stats ' . escapeshellarg($id), 60);
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

/** Persist the tracked sites (atomic JSON, private perms). */
function sites_save(array $s): bool
{
    return write_json_file(sites_file(), array_values($s));
}

/** Validate a domain name. */
function sv_domain_ok($d): bool
{
    return domain_name_ok((string) $d);
}

/** Validate a filesystem path (absolute, safe charset, no traversal). */
function sv_path_ok($p): bool
{
    return (bool) preg_match('#^/[A-Za-z0-9._/-]+$#', (string) $p) && strpos((string) $p, '..') === false;
}

function sv_managed_path_ok(string $path, string $id): bool
{
    global $config;
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) { return false; }
    $root = rtrim((string) ($config['sites_root'] ?? '/srv/nebula/sites'), '/');
    return $path === $root . '/' . $id . '/public';
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
function site_create(string $domain, string $php): array
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    if (!sv_domain_ok($domain)) {
        return ['ok' => false, 'error' => 'Invalid domain.'];
    }
    if (!in_array($php, php_versions(), true)) {
        return ['ok' => false, 'error' => 'PHP version not installed.'];
    }
    foreach (sites_list() as $s) {
        if (($s['domain'] ?? '') === $domain) {
            return ['ok' => false, 'error' => 'A site with that domain already exists.'];
        }
    }
    $id = bin2hex(random_bytes(16));
    global $config;
    $docroot = rtrim((string) ($config['sites_root'] ?? '/srv/nebula/sites'), '/') . '/' . $id . '/public';
    [$c, $o] = helper_cmd(
        'site-create ' . escapeshellarg($id) . ' ' . escapeshellarg($domain) . ' ' . escapeshellarg($php)
    );
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'site-create failed'];
    }
    // sites_list() may have already rediscovered the vhost the helper just
    // created. Enrich that record instead of adding a duplicate row.
    $sites = sites_list();
    $record = [
        'id' => $id, 'domain' => $domain, 'docroot' => $docroot, 'php' => $php,
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
    if (!sites_save($sites)) {
        // The host mutation succeeded, so make it inactive and recoverable
        // rather than leaving an untracked live vhost.
        helper_cmd('site-delete ' . escapeshellarg($id) . ' ' . escapeshellarg($domain) . ' archive');
        return ['ok' => false, 'error' => 'Website provisioning was rolled back because panel state could not be saved.'];
    }
    audit('site.create', $domain);
    return ['ok' => true];
}

/** Archive a managed website and remove its active configuration. */
function site_delete(string $domain, bool $purge = false): array
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    if (!sv_domain_ok($domain)) {
        return ['ok' => false, 'error' => 'Invalid domain.'];
    }
    $sites = sites_list();
    $id = '';
    foreach ($sites as $s) {
        if (($s['domain'] ?? '') === $domain) {
            $id = (string) ($s['id'] ?? '');
            break;
        }
    }
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        return ['ok' => false, 'error' => 'This legacy site has no immutable ID and cannot be deleted by the panel. Migrate it first.'];
    }
    // Deletion is deliberately recoverable. The helper owns the archive
    // location, so callers can never turn this into an arbitrary path delete.
    $args = 'site-delete ' . escapeshellarg($id) . ' ' . escapeshellarg($domain) . ' archive';
    [$c, $o] = helper_cmd($args);
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'site-delete failed'];
    }
    $sites = array_values(array_filter($sites, fn($s) => ($s['domain'] ?? '') !== $domain));
    if (!sites_save($sites)) {
        return ['ok' => false, 'error' => 'The site was archived, but panel state could not be updated. Repair data/sites.json before retrying.'];
    }
    require_once APP_ROOT . '/lib/mod_dns.php';
    dns_forget_zone($domain);
    audit('site.delete', $domain);
    return ['ok' => true];
}

/** Issue a Let's Encrypt certificate for a site (certbot --nginx via helper). */
function site_ssl(string $domain, string $email = '', ?callable $onOutput = null): array
{
    $domain = strtolower(rtrim(trim($domain), '.'));
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
    [$c, $o] = $onOutput
        ? helper_cmd_stream($args, $onOutput, 300)
        : helper_cmd($args, 300);
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
    if (!sites_save($sites)) {
        return ['ok' => false, 'error' => 'TLS was issued, but panel state could not be updated.'];
    }
    audit('site.ssl', $domain);
    return ['ok' => true];
}

/** Switch an existing managed Nginx website to another installed PHP-FPM version. */
function site_set_php(string $domain, string $version): array
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    if (!sv_domain_ok($domain)) { return ['ok' => false, 'error' => 'Invalid domain.']; }
    if (!in_array($version, php_versions(), true)) { return ['ok' => false, 'error' => 'PHP version is not installed.']; }
    $found = false;
    $id = '';
    foreach (sites_list() as $site) {
        if (($site['domain'] ?? '') === $domain) { $found = true; $id = (string) ($site['id'] ?? ''); break; }
    }
    if (!$found) { return ['ok' => false, 'error' => 'Website not found.']; }
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) { return ['ok' => false, 'error' => 'Legacy site must be migrated before changing PHP.']; }
    [$code, $output] = helper_cmd('site-php ' . escapeshellarg($id) . ' ' . escapeshellarg($domain) . ' ' . escapeshellarg($version));
    if ($code !== 0) { return ['ok' => false, 'error' => trim($output) ?: 'Could not switch PHP version.']; }
    $sites = sites_list();
    foreach ($sites as &$site) { if (($site['domain'] ?? '') === $domain) { $site['php'] = $version; } }
    unset($site);
    if (!sites_save($sites)) {
        return ['ok' => false, 'error' => 'PHP was switched, but panel state could not be updated.'];
    }
    audit('site.php', $domain . ' -> ' . $version);
    return ['ok' => true];
}
