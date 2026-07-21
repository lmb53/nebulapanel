<?php
/**
 * Git deployment module — connect a website document root to a Git repository
 * and keep it in sync.
 *
 * Git runs as the panel/web user (the same user that owns Nebula-provisioned
 * document roots), so no extra sudo grant is required. Every repository URL,
 * branch and path is validated before use and all shell tokens are
 * escapeshellarg()'d. Per-site metadata (url, branch, last commit, last sync)
 * is stored alongside the site record in data/sites.json under a "git" key.
 */

require_once APP_ROOT . '/lib/mod_sites.php';

function git_available(): bool
{
    return has_cmd('git');
}

/** Non-interactive git environment: never prompt for credentials or host keys. */
function git_env_prefix(): string
{
    return 'env GIT_TERMINAL_PROMPT=0 '
        . 'GIT_SSH_COMMAND=' . escapeshellarg('ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new') . ' '
        . 'HOME=' . escapeshellarg(sys_get_temp_dir()) . ' ';
}

/** Accept https(s), git:// and scp-style git@host:path URLs; reject the rest. */
function git_url_ok(string $url): bool
{
    if ($url === '' || strlen($url) > 512) { return false; }
    if (strpbrk($url, "\n\r \t") !== false) { return false; }
    if (preg_match('#^https?://[A-Za-z0-9._~:/?\#\[\]@!$&\'()*+,;=%-]+$#', $url)) { return true; }
    if (preg_match('#^git://[A-Za-z0-9._~:/@%-]+$#', $url)) { return true; }
    if (preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+:[A-Za-z0-9._~/-]+$#', $url)) { return true; }
    return false;
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
    if (!sv_domain_ok($domain)) { return null; }
    foreach (sites_list() as $site) {
        if (($site['domain'] ?? '') === $domain) {
            $docroot = (string) ($site['docroot'] ?? '');
            return sv_path_ok($docroot) ? $docroot : null;
        }
    }
    return null;
}

/** Persist git metadata onto the matching site record. */
function git_set_meta(string $domain, ?array $meta): void
{
    $sites = sites_list();
    foreach ($sites as &$site) {
        if (($site['domain'] ?? '') === $domain) {
            if ($meta === null) { unset($site['git']); }
            else { $site['git'] = $meta; }
        }
    }
    unset($site);
    sites_save($sites);
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
    $docroot = git_site_docroot($domain);
    if ($docroot === null) {
        return ['ok' => false, 'error' => 'Website document root is not accessible.'];
    }
    $meta = git_get_meta($domain);
    if (!is_dir($docroot . '/.git')) {
        return ['ok' => true, 'available' => true, 'connected' => false, 'docroot' => $docroot, 'meta' => $meta];
    }
    $g = 'git -C ' . escapeshellarg($docroot) . ' ';
    [, $branch] = run_cmd($g . 'rev-parse --abbrev-ref HEAD 2>/dev/null', 15);
    [, $remote] = run_cmd($g . 'config --get remote.origin.url 2>/dev/null', 15);
    [, $log] = run_cmd($g . 'log -1 --format=' . escapeshellarg('%h%x09%s%x09%ci') . ' 2>/dev/null', 15);
    [$dirtyCode, $dirty] = run_cmd($g . 'status --porcelain 2>/dev/null', 15);
    [$hash, $subject, $date] = array_pad(explode("\t", trim($log), 3), 3, '');
    return [
        'ok' => true,
        'available' => true,
        'connected' => true,
        'docroot' => $docroot,
        'branch' => trim($branch),
        'remote' => trim($remote),
        'commit' => $hash,
        'subject' => $subject,
        'commit_date' => $date,
        'dirty' => $dirtyCode === 0 && trim($dirty) !== '',
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
    if (!git_url_ok($url)) { return ['ok' => false, 'error' => 'Invalid repository URL. Use https://… or git@host:path.']; }
    if (!git_branch_ok($branch)) { return ['ok' => false, 'error' => 'Invalid branch name.']; }
    $docroot = git_site_docroot($domain);
    if ($docroot === null || !is_dir($docroot)) {
        return ['ok' => false, 'error' => 'Website document root is not accessible.']; }

    $b = escapeshellarg($branch);
    $inner = 'cd ' . escapeshellarg($docroot) . ' && '
        . '{ [ -d .git ] || git init -q; } && '
        . '{ git remote remove origin 2>/dev/null || true; } && '
        . 'git remote add origin ' . escapeshellarg($url) . ' && '
        . 'git fetch --depth 1 origin ' . $b . ' && '
        . 'git checkout -f -B ' . $b . ' ' . escapeshellarg('origin/' . $branch);
    $res = git_run($inner, $onOutput, 300);
    if (!$res['ok']) { return $res; }
    git_record_sync($domain, $docroot, $url, $branch);
    audit('git.connect', $domain . ' -> ' . $url . '#' . $branch);
    return ['ok' => true, 'output' => $res['output']];
}

/** Fetch and hard-reset a connected checkout to the latest remote commit. */
function git_pull(string $domain, ?callable $onOutput = null): array
{
    if (!git_available()) { return ['ok' => false, 'error' => 'Git is not installed on the server.']; }
    $docroot = git_site_docroot($domain);
    if ($docroot === null || !is_dir($docroot . '/.git')) {
        return ['ok' => false, 'error' => 'This website is not connected to a repository.']; }
    $meta = git_get_meta($domain);
    $branch = (string) ($meta['branch'] ?? '');
    if (!git_branch_ok($branch)) {
        // Fall back to the current checked-out branch.
        [, $current] = run_cmd('git -C ' . escapeshellarg($docroot) . ' rev-parse --abbrev-ref HEAD 2>/dev/null', 15);
        $branch = trim($current);
    }
    if (!git_branch_ok($branch)) { return ['ok' => false, 'error' => 'Could not determine the branch to pull.']; }
    $b = escapeshellarg($branch);
    $inner = 'cd ' . escapeshellarg($docroot) . ' && '
        . 'git fetch --depth 1 origin ' . $b . ' && '
        . 'git reset --hard ' . escapeshellarg('origin/' . $branch);
    $res = git_run($inner, $onOutput, 300);
    if (!$res['ok']) { return $res; }
    $url = (string) ($meta['url'] ?? '');
    git_record_sync($domain, $docroot, $url, $branch);
    audit('git.pull', $domain . ' (' . $branch . ')');
    return ['ok' => true, 'output' => $res['output']];
}

/** Forget a repository. Optionally delete the .git directory (keeps the files). */
function git_disconnect(string $domain, bool $removeGit = false): array
{
    $docroot = git_site_docroot($domain);
    if ($docroot === null) { return ['ok' => false, 'error' => 'Website document root is not accessible.']; }
    if ($removeGit && is_dir($docroot . '/.git')) {
        // Confined to the site's own docroot; only ever removes the .git folder.
        run_cmd('rm -rf ' . escapeshellarg($docroot . '/.git'), 60);
    }
    git_set_meta($domain, null);
    audit('git.disconnect', $domain);
    return ['ok' => true];
}

/** Run a git shell pipeline non-interactively, streaming output when asked. */
function git_run(string $inner, ?callable $onOutput, int $timeout): array
{
    $cmd = git_env_prefix() . 'sh -c ' . escapeshellarg($inner) . ' 2>&1';
    if ($onOutput) {
        [$code, $out] = run_cmd_stream($cmd, $onOutput, $timeout);
    } else {
        [$code, $out] = run_cmd($cmd, $timeout);
    }
    if ($code !== 0) {
        $msg = trim($out);
        if (stripos($msg, 'Authentication failed') !== false || stripos($msg, 'could not read Username') !== false) {
            $msg = 'Authentication failed. For private repositories embed a token in the URL (https://user:token@host/repo.git).';
        } elseif ($msg === '') {
            $msg = 'Git command failed (exit ' . $code . ').';
        }
        return ['ok' => false, 'error' => $msg, 'output' => $out];
    }
    return ['ok' => true, 'output' => $out];
}

/** Record the latest synced commit + timestamp onto the site record. */
function git_record_sync(string $domain, string $docroot, string $url, string $branch): void
{
    [, $hash] = run_cmd('git -C ' . escapeshellarg($docroot) . ' rev-parse --short HEAD 2>/dev/null', 15);
    git_set_meta($domain, [
        'url' => $url,
        'branch' => $branch,
        'last_commit' => trim($hash),
        'last_sync' => date('c'),
    ]);
}
