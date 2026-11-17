<?php
/**
 * Git deployment module — connect a website document root to a Git repository
 * and keep it in sync.
 *
 * Git runs through the helper as the assigned site account. Repository URLs,
 * branches, and immutable site IDs are validated at both trust boundaries.
 */

require_once APP_ROOT . '/lib/mod_sites.php';

function git_available(): bool
{
    return has_cmd('git');
}

/** Credential-free HTTPS URLs, or SSH URLs (git@host:owner/repo.git) used with the site's deploy key. */
function git_url_ok(string $url): bool
{
    if ($url === '' || strlen($url) > 512) { return false; }
    if (strpbrk($url, "\n\r \t") !== false) { return false; }
    if (preg_match('#^(ssh://)?git@[A-Za-z0-9.-]+(:[0-9]{1,5})?[:/][A-Za-z0-9._~/-]+(\.git)?$#', $url)) { return true; }
    $parts = parse_url($url);
    return is_array($parts)
        && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
        && empty($parts['user']) && empty($parts['pass'])
        && !empty($parts['host'])
        && (bool) preg_match('#^/[A-Za-z0-9._~/-]+(?:\.git)?$#', (string) ($parts['path'] ?? ''));
}

function git_redact_url(string $url): string
{
    return preg_replace('#^(https?://)[^/@]+@#i', '$1[redacted]@', $url) ?? $url;
}

function git_site_record(string $domain): ?array
{
    if (!sv_domain_ok($domain)) { return null; }
    foreach (sites_list() as $site) {
        $id = (string) ($site['id'] ?? '');
        $docroot = (string) ($site['docroot'] ?? '');
        if (($site['domain'] ?? '') === $domain && sv_managed_path_ok($docroot, $id)) {
            return $site;
        }
    }
    return null;
}

/** A safe git branch/ref name. */
function git_branch_ok(string $branch): bool
{
    return $branch !== ''
        && strlen($branch) <= 100
        && (bool) preg_match('#^[A-Za-z0-9._/-]+$#', $branch)
        && strpos($branch, '..') === false
        && $branch[0] !== '-';
}

/** Resolve a managed site's document root, or null if unknown/unsafe. */
function git_site_docroot(string $domain): ?string
{
    $site = git_site_record($domain);
    return $site ? (string) $site['docroot'] : null;
}

/** Persist git metadata onto the matching site record. */
function git_set_meta(string $domain, ?array $meta): bool
{
    return (bool) with_sites_lock(static fn() => git_set_meta_locked($domain, $meta));
}

function git_set_meta_locked(string $domain, ?array $meta): bool
{
    $sites = sites_list();
    foreach ($sites as &$site) {
        if (($site['domain'] ?? '') === $domain) {
            if ($meta === null) { unset($site['git']); }
            else { $site['git'] = $meta; }
        }
    }
    unset($site);
    return sites_save($sites);
}

/** Read stored git metadata for a site. */
function git_get_meta(string $domain): array
{
    foreach (sites_list() as $site) {
        if (($site['domain'] ?? '') === $domain && is_array($site['git'] ?? null)) {
            return $site['git'];
        }
    }
    return [];
}

/** Live status of a site's checkout: connected?, branch, remote, last commit. */
function git_status(string $domain): array
{
    if (!git_available()) {
        return ['ok' => true, 'available' => false, 'connected' => false];
    }
    $site = git_site_record($domain);
    if ($site === null) {
        return ['ok' => false, 'error' => 'Website document root is not accessible.'];
    }
    $docroot = (string) $site['docroot'];
    $meta = git_get_meta($domain);
    unset($meta['hook_hash']); // never sent to the browser
    [$code, $raw] = helper_cmd('site-git-status ' . escapeshellarg((string) $site['id']), 30);
    if ($code !== 0) {
        return ['ok' => false, 'error' => trim($raw) ?: 'Could not inspect repository.'];
    }
    $status = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $status[$key] = $value;
        }
    }
    if (($status['connected'] ?? 'no') !== 'yes') {
        return ['ok' => true, 'available' => true, 'connected' => false, 'docroot' => $docroot, 'meta' => $meta];
    }
    return [
        'ok' => true,
        'available' => true,
        'connected' => true,
        'docroot' => $docroot,
        'branch' => (string) ($status['branch'] ?? ''),
        'remote' => git_redact_url((string) ($status['remote'] ?? '')),
        'commit' => (string) ($status['commit'] ?? ''),
        'subject' => '',
        'commit_date' => '',
        'dirty' => ($status['dirty'] ?? 'no') === 'yes',
        'meta' => $meta,
    ];
}

/**
 * Connect a document root to a repository and check out $branch.
 * Works whether or not the docroot already contains files: it initialises a
 * repo in place, points origin at $url, fetches and force-checks-out the
 * branch (tracked files are overwritten, untracked files are left alone).
 */
function git_connect(string $domain, string $url, string $branch, ?callable $onOutput = null): array
{
    if (!git_available()) { return ['ok' => false, 'error' => 'Git is not installed on the server.']; }
    if (!git_url_ok($url)) { return ['ok' => false, 'error' => 'Use a credential-free HTTPS URL, or an SSH URL (git@github.com:owner/repo.git) with the deploy key shown below. Embedded credentials are not accepted.']; }
    if (!git_branch_ok($branch)) { return ['ok' => false, 'error' => 'Invalid branch name.']; }
    $site = git_site_record($domain);
    if ($site === null) {
        return ['ok' => false, 'error' => 'Website document root is not accessible.']; }

    [$code, $output] = helper_cmd(
        'site-git-connect ' . escapeshellarg((string) $site['id']) . ' '
        . escapeshellarg($url) . ' ' . escapeshellarg($branch),
        300
    );
    $res = $code === 0
        ? ['ok' => true, 'output' => $output]
        : ['ok' => false, 'error' => trim($output) ?: 'Git deployment failed.', 'output' => $output];
    if (!$res['ok']) { return $res; }
    if (!git_record_sync($domain, git_redact_url($url), $branch)) {
        return ['ok' => false, 'error' => 'Repository connected, but deployment metadata could not be saved.'];
    }
    audit('git.connect', $domain . ' -> ' . git_redact_url($url) . '#' . $branch);
    return ['ok' => true, 'output' => $res['output']];
}

/** Fetch and hard-reset a connected checkout to the latest remote commit. */
function git_pull(string $domain, ?callable $onOutput = null): array
{
    if (!git_available()) { return ['ok' => false, 'error' => 'Git is not installed on the server.']; }
    $site = git_site_record($domain);
    if ($site === null) {
        return ['ok' => false, 'error' => 'This website is not connected to a repository.']; }
    $meta = git_get_meta($domain);
    $branch = (string) ($meta['branch'] ?? '');
    if (!git_branch_ok($branch)) {
        $branch = (string) (git_status($domain)['branch'] ?? '');
    }
    if (!git_branch_ok($branch)) { return ['ok' => false, 'error' => 'Could not determine the branch to pull.']; }
    [$code, $output] = helper_cmd(
        'site-git-pull ' . escapeshellarg((string) $site['id']) . ' ' . escapeshellarg($branch),
        300
    );
    $res = $code === 0
        ? ['ok' => true, 'output' => $output]
        : ['ok' => false, 'error' => trim($output) ?: 'Git pull failed.', 'output' => $output];
    if (!$res['ok']) { return $res; }
    $url = (string) ($meta['url'] ?? '');
    if (!git_record_sync($domain, git_redact_url($url), $branch)) {
        return ['ok' => false, 'error' => 'Repository updated, but deployment metadata could not be saved.'];
    }
    audit('git.pull', $domain . ' (' . $branch . ')');
    return ['ok' => true, 'output' => $res['output']];
}

/** Forget a repository. Optionally delete the .git directory (keeps the files). */
function git_disconnect(string $domain, bool $removeGit = false): array
{
    $site = git_site_record($domain);
    if ($site === null) { return ['ok' => false, 'error' => 'Website document root is not accessible.']; }
    if ($removeGit) {
        [$code, $out] = helper_cmd('site-git-disconnect ' . escapeshellarg((string) $site['id']), 60);
        if ($code !== 0) { return ['ok' => false, 'error' => trim($out) ?: 'Could not remove repository metadata.']; }
    }
    if (!git_set_meta($domain, null)) {
        return ['ok' => false, 'error' => 'Repository metadata could not be updated.'];
    }
    audit('git.disconnect', $domain);
    return ['ok' => true];
}

/** Record the latest synced commit + timestamp onto the site record. */
function git_record_sync(string $domain, string $url, string $branch): bool
{
    $status = git_status($domain);
    $hash = (string) ($status['commit'] ?? '');
    return git_set_meta($domain, [
        'url' => git_redact_url($url),
        'branch' => $branch,
        'last_commit' => trim($hash),
        'last_sync' => date('c'),
    ]);
}

/** The site's SSH deploy key (created on first use): add it to the repository as a read-only deploy key. */
function git_deploy_key(string $domain): array
{
    $site = git_site_record($domain);
    if ($site === null) { return ['ok' => false, 'error' => 'Website not found.']; }
    [$code, $out] = helper_cmd('site-git-key ' . escapeshellarg((string) $site['id']), 60);
    $key = trim($out);
    if ($code !== 0 || !preg_match('#^ssh-ed25519 [A-Za-z0-9+/=]+ #', $key)) {
        return ['ok' => false, 'error' => $key !== '' ? $key : 'Could not create the deploy key.'];
    }
    return ['ok' => true, 'public_key' => $key];
}

/**
 * Create (or replace) the push-to-deploy webhook secret. Only its hash is
 * stored; the URL with the secret is shown once.
 */
function git_hook_rotate(string $domain): array
{
    $site = git_site_record($domain);
    if ($site === null) { return ['ok' => false, 'error' => 'Website not found.']; }
    $meta = git_get_meta($domain);
    if (empty($meta['branch'])) { return ['ok' => false, 'error' => 'Connect a repository first.']; }
    $token = bin2hex(random_bytes(24));
    $meta['hook_hash'] = hash('sha256', $token);
    $meta['hook_created'] = date('c');
    if (!git_set_meta($domain, $meta)) { return ['ok' => false, 'error' => 'Could not save the webhook.']; }
    audit('git.hook.rotate', $domain);
    return ['ok' => true, 'url' => git_hook_url((string) $site['id'], $token)];
}

function git_hook_disable(string $domain): array
{
    $meta = git_get_meta($domain);
    unset($meta['hook_hash'], $meta['hook_created']);
    if (!git_set_meta($domain, $meta)) { return ['ok' => false, 'error' => 'Could not save the webhook.']; }
    audit('git.hook.disable', $domain);
    return ['ok' => true];
}

function git_hook_url(string $siteId, string $token): string
{
    global $config;
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $trusted = in_array($remote, (array) ($config['trusted_proxies'] ?? []), true);
    $host = $trusted && !empty($_SERVER['HTTP_X_FORWARDED_HOST']) ? (string) $_SERVER['HTTP_X_FORWARDED_HOST'] : (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', $host) ?: 'localhost';
    return 'https://' . $host . url('deploy-hook', ['site' => $siteId, 'token' => $token]);
}

/**
 * Public push-to-deploy endpoint (no session): verify the site's secret and
 * start a background pull. Answers quickly; the deploy shows up under Jobs.
 */
function git_hook_receive(string $siteId, string $token): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $siteId) || !preg_match('/^[a-f0-9]{48}$/', $token)) {
        return ['ok' => false, 'status' => 404, 'error' => 'Not found'];
    }
    $site = null;
    foreach (sites_stored() as $candidate) {
        if (($candidate['id'] ?? '') === $siteId) { $site = $candidate; break; }
    }
    $hash = (string) ($site['git']['hook_hash'] ?? '');
    if ($site === null || $hash === '' || !hash_equals($hash, hash('sha256', $token))) {
        usleep(200000);
        return ['ok' => false, 'status' => 404, 'error' => 'Not found'];
    }
    // A handful of deploy starts per site per throttle window (login_window).
    if (reserve_login_attempt('deploy-hook', $siteId, false) > 0) {
        return ['ok' => false, 'status' => 429, 'error' => 'Too many deploys; try again shortly.'];
    }
    require_once APP_ROOT . '/lib/mod_jobs.php';
    $started = job_start_system('git', ['action' => 'pull', 'domain' => (string) $site['domain']], 'webhook:' . $site['domain']);
    if (empty($started['ok'])) { return ['ok' => false, 'status' => 500, 'error' => $started['error'] ?? 'Could not start the deploy.']; }
    audit('git.hook.received', (string) $site['domain']);
    return ['ok' => true, 'status' => 202, 'job' => $started['job']['id']];
}
