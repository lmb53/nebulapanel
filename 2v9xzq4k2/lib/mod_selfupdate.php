<?php
/**
 * Self-update module — checks GitHub for a newer version of the panel and
 * applies it in place. Compares the deployed commit SHA (recorded in
 * data/version.json) against the latest commit on the configured repo/ref.
 *
 * On apply: snapshots the current install, then rsyncs the downloaded files
 * over APP_ROOT while PRESERVING data/ and config.php (your settings survive).
 */

function su_version_file(): string
{
    return APP_ROOT . '/data/version.json';
}

/** Currently-applied version info, or null if unknown. */
function su_current(): ?array
{
    $j = @json_decode((string) @file_get_contents(su_version_file()), true);
    return is_array($j) && !empty($j['sha']) ? $j : null;
}

function su_write_version(string $sha, string $ref): void
{
    @file_put_contents(su_version_file(), json_encode([
        'sha'        => $sha,
        'ref'        => $ref,
        'applied_at' => date('c'),
    ], JSON_PRETTY_PRINT), LOCK_EX);
    @chmod(su_version_file(), 0600);
}

/** Latest commit on the configured repo/ref via the GitHub API. */
function su_remote_latest(): array
{
    global $config;
    $repo = $config['repo'] ?? '';
    $ref = $config['repo_ref'] ?? 'main';
    if ($repo === '') {
        return ['ok' => false, 'error' => 'No repo configured.'];
    }
    $url = 'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode($ref);
    [$ok, $body] = http_get($url, 30);
    if (!$ok || $body === '') {
        return ['ok' => false, 'error' => 'Could not reach GitHub (rate limit or network?).'];
    }
    $j = json_decode($body, true);
    if (!is_array($j) || empty($j['sha'])) {
        return ['ok' => false, 'error' => 'Unexpected response from GitHub.'];
    }
    return [
        'ok'      => true,
        'sha'     => $j['sha'],
        'message' => $j['commit']['message'] ?? '',
        'date'    => $j['commit']['committer']['date'] ?? ($j['commit']['author']['date'] ?? ''),
        'author'  => $j['commit']['author']['name'] ?? '',
    ];
}

/** Compare current vs remote. */
function su_check(): array
{
    global $config;
    $cur = su_current();
    $remote = su_remote_latest();
    if (!$remote['ok']) {
        return $remote;
    }
    $curSha = $cur['sha'] ?? null;
    return [
        'ok'               => true,
        'repo'             => $config['repo'] ?? '',
        'ref'              => $config['repo_ref'] ?? 'main',
        'current_sha'      => $curSha,
        'latest_sha'       => $remote['sha'],
        'update_available' => $curSha === null ? true : ($curSha !== $remote['sha']),
        'known'            => $curSha !== null,
        'message'          => $remote['message'],
        'date'             => $remote['date'],
        'author'           => $remote['author'],
    ];
}

/**
 * Download and apply the latest version. Returns ['ok'=>bool,'log'=>[...],...].
 * Preserves data/ and config.php. Snapshots the current install first.
 */
function su_apply(): array
{
    global $config;
    $log = [];
    $add = function ($m) use (&$log) { $log[] = $m; };

    if (!has_cmd('rsync')) {
        return ['ok' => false, 'error' => 'rsync is required to apply updates.', 'log' => $log];
    }
    $repo = $config['repo'] ?? '';
    $ref  = $config['repo_ref'] ?? 'main';
    if ($repo === '') {
        return ['ok' => false, 'error' => 'No repo configured.', 'log' => $log];
    }

    // Work area under data/ (web-denied, writable).
    $work = APP_ROOT . '/data/_update';
    run_cmd('rm -rf ' . escapeshellarg($work));
    if (!@mkdir($work, 0700, true)) {
        return ['ok' => false, 'error' => 'Could not create work dir.', 'log' => $log];
    }

    // 1. Download the tarball.
    $tar = $work . '/src.tar.gz';
    $url = 'https://codeload.github.com/' . $repo . '/tar.gz/' . rawurlencode($ref);
    $add('Downloading ' . $repo . '@' . $ref . '…');
    if (!http_download($url, $tar, 300)) {
        run_cmd('rm -rf ' . escapeshellarg($work));
        return ['ok' => false, 'error' => 'Download failed.', 'log' => $log];
    }

    // 2. Extract.
    $add('Extracting…');
    [$c, $o] = run_cmd('tar -xzf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($work) . ' 2>&1');
    if ($c !== 0) {
        run_cmd('rm -rf ' . escapeshellarg($work));
        return ['ok' => false, 'error' => 'Extract failed: ' . trim($o), 'log' => $log];
    }

    // 3. Locate the panel source dir inside the archive (has index.php + lib/bootstrap.php).
    $src = su_find_panel_dir($work);
    if ($src === null) {
        run_cmd('rm -rf ' . escapeshellarg($work));
        return ['ok' => false, 'error' => 'Downloaded archive has no panel directory.', 'log' => $log];
    }
    $add('Found source: ' . basename($src));

    // 4. Snapshot the current install (excluding the big/volatile data subdirs).
    $snapDir = APP_ROOT . '/data/backups';
    @mkdir($snapDir, 0700, true);
    $snap = $snapDir . '/pre-update-' . date('Ymd-His') . '.tar.gz';
    $add('Snapshotting current install…');
    [$sc, $so] = run_cmd(
        'tar -czf ' . escapeshellarg($snap)
        . ' --exclude=' . escapeshellarg('data/backups')
        . ' --exclude=' . escapeshellarg('data/_update')
        . ' -C ' . escapeshellarg(dirname(APP_ROOT)) . ' ' . escapeshellarg(basename(APP_ROOT))
        . ' 2>&1', 300
    );
    if ($sc === 0) {
        @chmod($snap, 0600);
        $add('Snapshot: ' . basename($snap));
    } else {
        $add('WARNING: snapshot failed (' . trim($so) . ') — continuing.');
    }

    // 5. Sync new files over the install, preserving data/ and config.php.
    $add('Applying update…');
    [$rc, $ro] = run_cmd(
        'rsync -a'
        . ' --exclude=' . escapeshellarg('data')
        . ' --exclude=' . escapeshellarg('config.php')
        . ' ' . escapeshellarg($src . '/') . ' ' . escapeshellarg(APP_ROOT . '/')
        . ' 2>&1', 120
    );
    if ($rc !== 0) {
        run_cmd('rm -rf ' . escapeshellarg($work));
        return ['ok' => false, 'error' => 'rsync failed: ' . trim($ro), 'log' => $log,
                'rollback' => 'Restore from ' . ($snap ?? 'the snapshot') . ' if needed.'];
    }

    // 6. Record the new version.
    $remote = su_remote_latest();
    $newSha = $remote['ok'] ? $remote['sha'] : ('applied-' . date('Ymd-His'));
    su_write_version($newSha, $ref);
    $add('Updated to ' . substr($newSha, 0, 12) . '.');

    run_cmd('rm -rf ' . escapeshellarg($work));
    audit('selfupdate.apply', $repo . '@' . $ref . ' -> ' . substr($newSha, 0, 12));
    return ['ok' => true, 'log' => $log, 'new_sha' => $newSha, 'snapshot' => isset($snap) ? basename($snap) : null];
}

/** Find the dir containing index.php + lib/bootstrap.php within $root. */
function su_find_panel_dir(string $root): ?string
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->getFilename() === 'bootstrap.php' && basename(dirname($f->getPathname())) === 'lib') {
            $dir = dirname(dirname($f->getPathname()));
            if (is_file($dir . '/index.php')) {
                return $dir;
            }
        }
    }
    return null;
}
