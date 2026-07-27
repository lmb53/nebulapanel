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

/** Accept only credential-free HTTPS repository URLs. */
function git_url_ok(string $url): bool
{
    if ($url === '' || strlen($url) > 512) { return false; }
    if (strpbrk($url, "\n\r \t") !== false) { return false; }
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
    if (!git_url_ok($url)) { return ['ok' => false, 'error' => 'Use a credential-free HTTPS repository URL. Embedded credentials and SSH URLs are not accepted.']; }
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
