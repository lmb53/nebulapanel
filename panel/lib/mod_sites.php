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
    if ($changed) {
        // Re-read under the lock so a concurrent change is merged, not lost.
        with_sites_lock(static function () use ($sites): void {
            $current = [];
            foreach (sites_stored() as $site) { $current[(string) ($site['domain'] ?? '')] = $site; }
            foreach ($sites as $site) { $current[(string) $site['domain']] = array_merge($current[(string) $site['domain']] ?? [], $site); }
            sites_save(array_values(array_filter($current, static fn($site) => !empty($site['domain']))));
        });
    }
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
            // du + find over a whole site is slow on big sites; ten minutes
            // of staleness is fine for a usage figure.
            $stats = cache_remember('site-stats-' . $id, 600, static function () use ($id): ?array {
                [$c, $o] = helper_cmd('site-stats ' . escapeshellarg($id), 60);
                if ($c !== 0) { return null; }
                [$bytes, $files] = array_pad(explode("\t", trim($o), 2), 2, 0);
                return ['bytes' => max(0, (int) $bytes), 'files' => max(0, (int) $files)];
            });
            if (is_array($stats)) {
                $site['disk_used'] = $stats['bytes'];
                $site['file_count'] = $stats['files'];
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

/**
 * Run $callback holding the sites lock, so two requests that read, change
 * and save data/sites.json (create, delete, SSL, PHP switch, discovery)
 * cannot overwrite each other's change. Re-entrant within one request.
 */
function with_sites_lock(callable $callback)
{
    static $depth = 0;
    static $handle = null;
    if ($depth === 0) {
        $handle = @fopen(DATA_DIR . '/sites.lock', 'c');
        if ($handle === false || !@flock($handle, LOCK_EX)) {
            if (is_resource($handle)) { fclose($handle); }
            $handle = null;
            return ['ok' => false, 'error' => 'Could not lock the website list. Check data/ permissions.'];
        }
    }
    $depth++;
    try {
        return $callback();
    } finally {
        $depth--;
        if ($depth === 0 && is_resource($handle)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            $handle = null;
        }
    }
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
    return with_sites_lock(static fn() => site_create_locked($domain, $php));
}

function site_create_locked(string $domain, string $php): array
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

/**
 * Delete a managed website. A final backup (files and linked databases) is
 * taken first, the folder is archived (restorable until trash retention
 * runs out), and everything else that belonged to the site is cleaned up:
 * certificate, DNS zone, database links, backup schedule entry, Git state.
 * $dropDatabases also drops the linked databases (they are in the backup).
 */
function site_delete(string $domain, bool $dropDatabases = false, ?callable $onOutput = null): array
{
    return with_sites_lock(static fn() => site_delete_locked($domain, $dropDatabases, $onOutput));
}

function site_delete_locked(string $domain, bool $dropDatabases, ?callable $onOutput): array
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
    $step = static function (string $text) use ($onOutput): void { if ($onOutput) { $onOutput('==> ' . $text . "\n", 'stdout'); } };
    require_once APP_ROOT . '/lib/mod_backups.php';
    require_once APP_ROOT . '/lib/mod_db.php';
    $databases = backup_site_databases($domain);
    $step('Taking a final backup of the website' . ($databases ? ' and its databases' : ''));
    $final = backup_create($id, backup_default_label($domain, 'deleted-'), true, $onOutput, 'pre-delete');
    if (empty($final['ok'])) {
        return ['ok' => false, 'error' => 'The final backup failed, so nothing was deleted: ' . ($final['error'] ?? 'unknown error')];
    }
    // Deletion is deliberately recoverable. The helper owns the archive
    // location, so callers can never turn this into an arbitrary path delete.
    $step('Removing the website configuration and archiving its folder');
    $args = 'site-delete ' . escapeshellarg($id) . ' ' . escapeshellarg($domain) . ' archive';
    [$c, $o] = helper_cmd($args);
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'site-delete failed'];
    }
    $sites = array_values(array_filter(sites_stored(), fn($s) => ($s['domain'] ?? '') !== $domain));
    if (!sites_save($sites)) {
        return ['ok' => false, 'error' => 'The site was archived, but panel state could not be updated. Repair data/sites.json before retrying.'];
    }
    $step('Cleaning up the certificate, DNS zone and links');
    helper_cmd('cert-delete ' . escapeshellarg($domain), 120); // no lineage is fine
    cache_forget('ssl-expiry');
    require_once APP_ROOT . '/lib/mod_dns.php';
    dns_forget_zone($domain);
    $links = db_links();
    foreach ($databases as $database) {
        if ($dropDatabases) {
            $step('Dropping database ' . $database);
            db_drop($database);
        }
        unset($links[$database]);
    }
    write_json_file(db_links_file(), $links);
    $schedule = backup_schedule();
    if (in_array($id, (array) $schedule['sites'], true)) {
        $schedule['sites'] = array_values(array_diff((array) $schedule['sites'], [$id]));
        write_json_file(backup_schedule_file(), $schedule);
    }
    audit('site.delete', $domain . ($dropDatabases && $databases ? ' + dropped ' . implode(',', $databases) : '') . ' (backup ' . $final['file'] . ')');
    return ['ok' => true, 'backup' => $final['file'], 'databases' => $databases, 'dropped' => $dropDatabases ? $databases : []];
}

/** Issue a Let's Encrypt certificate for a site (certbot --nginx via helper). */
function site_ssl(string $domain, string $email = '', ?callable $onOutput = null): array
{
    return with_sites_lock(static fn() => site_ssl_locked($domain, $email, $onOutput));
}

function site_ssl_locked(string $domain, string $email, ?callable $onOutput): array
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
    cache_forget('ssl-expiry');
    return ['ok' => true];
}

/** Switch an existing managed Nginx website to another installed PHP-FPM version. */
function site_set_php(string $domain, string $version): array
{
    return with_sites_lock(static fn() => site_set_php_locked($domain, $version));
}

function site_set_php_locked(string $domain, string $version): array
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

/** Archived (deleted) websites still on disk, newest first. */
function sites_trash_list(): array
{
    if (!helper_available()) { return []; }
    [$code, $out] = helper_cmd('site-trash-list', 120);
    if ($code !== 0) { return []; }
    $rows = [];
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        [$name, $domain, $deleted, $bytes] = array_pad(explode("\t", $line, 4), 4, '');
        if (!preg_match('/^[a-f0-9]{32}-[0-9]{8}-[0-9]{6}$/', $name)) { continue; }
        $rows[] = ['name' => $name, 'domain' => $domain, 'deleted_at' => (int) $deleted, 'size' => (int) $bytes];
    }
    usort($rows, static fn($a, $b) => $b['deleted_at'] <=> $a['deleted_at']);
    return $rows;
}

function sites_trash_purge(string $name): array
{
    if (!preg_match('/^[a-f0-9]{32}-[0-9]{8}-[0-9]{6}$/', $name)) {
        return ['ok' => false, 'error' => 'Invalid archive.'];
    }
    [$code, $out] = helper_cmd('site-trash-purge ' . escapeshellarg($name), 600);
    if ($code !== 0) { return ['ok' => false, 'error' => trim($out) ?: 'Could not remove the archived website.']; }
    audit('site.trash.purge', $name);
    return ['ok' => true];
}
