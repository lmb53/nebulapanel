<?php
/** Validated, snapshot-first panel self-update support. */

function su_version_file(): string { return APP_ROOT . '/data/version.json'; }

function su_current(): ?array
{
    $value = @json_decode((string) @file_get_contents(su_version_file()), true);
    return is_array($value) && !empty($value['sha']) ? $value : null;
}

function su_write_version(string $sha, string $ref, string $archiveHash = ''): void
{
    write_json_file(su_version_file(), [
        'sha'=>$sha, 'ref'=>$ref, 'applied_at'=>date('c'), 'archive_sha256'=>$archiveHash,
    ]);
}

function su_remote_latest(): array
{
    global $config;
    $repo = (string) ($config['repo'] ?? '');
    $ref = (string) ($config['repo_ref'] ?? 'main');
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)
        || !preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $ref)
        || str_contains($ref, '..')) {
        return ['ok'=>false,'error'=>'The update repository is invalid.'];
    }
    $sha = preg_match('/^[a-f0-9]{40}$/i', $ref) ? strtolower($ref) : '';
    if ($sha === '' && has_cmd('git')) {
        $remote = 'https://github.com/' . $repo . '.git';
        foreach (['refs/heads/' . $ref, 'refs/tags/' . $ref . '^{}', 'refs/tags/' . $ref] as $remoteRef) {
            [$code, $output] = run_cmd('git ls-remote ' . escapeshellarg($remote) . ' ' . escapeshellarg($remoteRef), 30);
            if ($code === 0 && preg_match('/^([a-f0-9]{40})\s/im', $output, $match)) {
                $sha = strtolower($match[1]);
                break;
            }
        }
    }
    // API fallback supports minimal hosts without Git. A timestamp avoids the
    // stale branch-head cache that can point codeload at an orphaned commit.
    if ($sha === '') {
        $url = 'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode($ref) . '?nebula=' . time();
        [$ok, $body] = http_get($url, 30);
        $json = $ok ? json_decode($body, true) : null;
        $sha = is_array($json) ? strtolower((string) ($json['sha'] ?? '')) : '';
    }
    if (!preg_match('/^[a-f0-9]{40}$/', $sha)) {
        return ['ok'=>false,'error'=>'Could not resolve the latest GitHub commit. Check outbound HTTPS and Git access.'];
    }
    $message = $date = $author = '';
    [$metaOk, $metaBody] = http_get('https://api.github.com/repos/' . $repo . '/commits/' . $sha, 20);
    $meta = $metaOk ? json_decode($metaBody, true) : null;
    if (is_array($meta)) {
        $message = (string) ($meta['commit']['message'] ?? '');
        $date = (string) ($meta['commit']['committer']['date'] ?? ($meta['commit']['author']['date'] ?? ''));
        $author = (string) ($meta['commit']['author']['name'] ?? '');
    }
    return ['ok'=>true,'sha'=>$sha,'message'=>$message,'date'=>$date,'author'=>$author];
}

function su_check(): array
{
    global $config;
    $current = su_current();
    $remote = su_remote_latest();
    if (empty($remote['ok'])) { return $remote; }
    $currentSha = $current['sha'] ?? null;
    return [
        'ok' => true,
        'repo' => $config['repo'] ?? '',
        'ref' => $config['repo_ref'] ?? 'main',
        'current_sha' => $currentSha,
        'latest_sha' => $remote['sha'],
        'update_available' => $currentSha === null || $currentSha !== $remote['sha'],
        'known' => $currentSha !== null,
        'message' => $remote['message'],
        'date' => $remote['date'],
        'author' => $remote['author'],
    ];
}

/**
 * Resolve the latest commit of the configured branch, then ask the root
 * helper to install exactly that commit. The helper fetches it itself from
 * its root-configured repository, checks the tree (no unsafe paths, links or
 * special files, required files present, every PHP file lints, a valid
 * helper) and, when /etc/nebula-panel/update-signers exists, its signature,
 * then snapshots the current install and swaps the release in. The panel no
 * longer stages code for root to install.
 */
function su_apply(): array
{
    global $config;
    $log = [];
    $add = static function (string $message) use (&$log): void { $log[] = $message; };
    $fail = static function (string $error) use (&$log): array { return ['ok' => false, 'error' => $error, 'log' => $log]; };
    $repo = (string) ($config['repo'] ?? '');
    $ref = (string) ($config['repo_ref'] ?? 'main');
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) { return $fail('The update repository is invalid.'); }
    $remote = su_remote_latest();
    $sha = strtolower((string) ($remote['sha'] ?? ''));
    if (empty($remote['ok']) || !preg_match('/^[a-f0-9]{40}$/', $sha)) { return $fail($remote['error'] ?? 'Could not resolve the update commit.'); }

    $add('Installing commit ' . substr($sha, 0, 12) . ' (fetched and verified by the privileged helper)…');
    [$applyCode, $applyOutput] = helper_cmd('panel-update ' . escapeshellarg($sha), 900);
    if ($applyCode !== 0) {
        $error = trim($applyOutput);
        if (stripos($error, 'update source') !== false) {
            $error = 'The privileged helper on this server predates verified updates. Re-run install.sh once to refresh it.';
        }
        return $fail($error ?: 'Privileged update apply failed. Re-run install.sh to refresh the helper.');
    }
    $archiveHash = preg_match('/archive_sha256=([a-f0-9]{64})/', $applyOutput, $m) ? $m[1] : '';
    $signed = preg_match('/signed=yes/', $applyOutput) === 1;
    su_write_version($sha, $ref, $archiveHash);
    audit('selfupdate.apply', $repo . '@' . $ref . ' -> ' . substr($sha, 0, 12) . ' archive ' . substr($archiveHash, 0, 12) . ($signed ? ' (signature verified)' : ' (unsigned)'));
    $snapshot = preg_match('/snapshot=([^\s]+)/', $applyOutput, $match) ? $match[1] : null;
    $add($signed ? 'Release signature verified.' : 'Release is not signature-checked (add trusted keys to /etc/nebula-panel/update-signers to require it).');
    $add('Updated to ' . substr($sha, 0, 12) . '.');
    return ['ok' => true, 'log' => $log, 'new_sha' => $sha, 'snapshot' => $snapshot, 'archive_sha256' => $archiveHash, 'signed' => $signed];
}
