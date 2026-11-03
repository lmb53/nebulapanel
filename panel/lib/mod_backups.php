<?php
/**
 * Backups module — creates, lists, verifies, restores and deletes .tar.gz
 * archives stored under data/backups, and runs the nightly/weekly schedule
 * with per-site retention.
 *
 * An archive holds the site's public/ directory and, when the site has linked
 * databases, a mysqldump of each one under databases/<name>.sql. Site
 * selection uses an immutable ID and the helper derives every path from
 * root-owned site state.
 */

const BACKUP_NAME_PATTERN = '/^[A-Za-z0-9_.-]{1,100}-[0-9]{8}-[0-9]{6}\.tar\.gz$/';

/** Absolute path to the backups storage directory (created on demand). */
function backup_store(): string
{
    $dir = DATA_DIR . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/** The manifest written next to an archive (empty for legacy archives). */
function backup_manifest(string $file): array
{
    $name = basename($file);
    if (!preg_match(BACKUP_NAME_PATTERN, $name)) { return []; }
    $manifest = @json_decode((string) @file_get_contents(backup_store() . '/' . $name . '.manifest.json'), true);
    return is_array($manifest) ? $manifest : [];
}

/** List archives, newest first, with what each one contains. */
function backup_list(?string $siteId = null): array
{
    $rows = [];
    foreach (glob(backup_store() . '/*.tar.gz') ?: [] as $path) {
        $name = basename($path);
        if (!preg_match(BACKUP_NAME_PATTERN, $name)) { continue; }
        $manifest = backup_manifest($name);
        $databases = array_values(array_filter(explode(',', (string) ($manifest['databases'] ?? ''))));
        $row = [
            'file'  => $name,
            'size'  => @filesize($path) ?: 0,
            'mtime' => @filemtime($path) ?: 0,
            'site_id' => (string) ($manifest['site_id'] ?? ''),
            'domain' => (string) ($manifest['domain'] ?? ''),
            'databases' => $databases,
            'scheduled' => !empty($manifest['scheduled']),
            'kind' => (string) ($manifest['kind'] ?? (!empty($manifest['scheduled']) ? 'scheduled' : 'manual')),
        ];
        if ($siteId !== null && $row['site_id'] !== $siteId) { continue; }
        $rows[] = $row;
    }
    usort($rows, fn($a, $b) => [$b['mtime'], $b['file']] <=> [$a['mtime'], $a['file']]);
    return $rows;
}

/**
 * Resolve a user-supplied filename to an absolute path inside the store.
 * basename() strips any directory components, preventing path traversal.
 * Returns null if the file does not exist.
 */
function backup_resolve(string $file): ?string
{
    $name = basename($file);
    if (!preg_match(BACKUP_NAME_PATTERN, $name)) {
        return null;
    }
    $abs = backup_store() . '/' . $name;
    return is_file($abs) && !is_link($abs) ? $abs : null;
}

/** Find a managed site record by immutable ID. */
function backup_find_site(string $siteId): ?array
{
    require_once APP_ROOT . '/lib/mod_sites.php';
    if (!preg_match('/^[a-f0-9]{32}$/', $siteId)) { return null; }
    foreach (sites_list() as $candidate) {
        if (($candidate['id'] ?? '') === $siteId) { return $candidate; }
    }
    return null;
}

/** Databases linked to a website on the Databases page. */
function backup_site_databases(string $domain): array
{
    require_once APP_ROOT . '/lib/mod_db.php';
    $names = [];
    foreach (db_links() as $database => $website) {
        if ($website === $domain && db_ident_ok((string) $database) && !in_array($database, SYSTEM_DBS, true)) {
            $names[] = (string) $database;
        }
    }
    sort($names);
    return $names;
}

/** A filename-safe label derived from a domain. */
function backup_default_label(string $domain, string $prefix = ''): string
{
    $label = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $domain), '.-');
    $label = substr($prefix . ($label !== '' ? $label : 'site'), 0, 60);
    return rtrim($label, '.-') ?: 'site';
}

/**
 * Create a .tar.gz archive of a managed site, labelled $label. Linked
 * databases are dumped into the archive unless $includeDatabases is false.
 * $kind is manual, scheduled or pre-restore (stored in the manifest).
 */
function backup_create(string $siteId, string $label, bool $includeDatabases = true, ?callable $onOutput = null, string $kind = 'manual'): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $siteId)) return ['ok' => false, 'error' => 'Invalid site ID.'];
    $site = backup_find_site($siteId);
    if (!$site) return ['ok' => false, 'error' => 'Managed site not found.'];
    $domain = (string) ($site['domain'] ?? '');
    $label = trim($label);
    if ($label === '') {
        // Domains can exceed the 60-character label limit; derive a safe default.
        $label = backup_default_label($domain);
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $label)) {
        return ['ok' => false, 'error' => 'Label must be 1–60 letters, numbers, _ . or -'];
    }
    $databases = $includeDatabases ? backup_site_databases($domain) : [];
    $fname = $label . '-' . date('Ymd-His') . '.tar.gz';
    if (file_exists(backup_store() . '/' . $fname)) {
        return ['ok' => false, 'error' => 'A backup with that name was just created; wait a second and retry.'];
    }
    $args = 'site-backup ' . escapeshellarg($siteId) . ' ' . escapeshellarg($fname)
        . ($databases ? ' ' . implode(' ', array_map('escapeshellarg', $databases)) : '');
    [$c, $o] = $onOutput ? helper_cmd_stream($args, $onOutput, 3600) : helper_cmd($args, 3600);
    if ($c !== 0) {
        audit('backup.create', $fname . ' <= site ' . $siteId . ' FAILED');
        return ['ok' => false, 'error' => trim(preg_replace('/^==> .*$/m', '', (string) $o) ?? '') ?: 'Backup failed.'];
    }
    $meta = ['site_id' => $siteId, 'domain' => $domain, 'file' => $fname, 'created_at' => date('c'),
        'kind' => $kind, 'scheduled' => $kind === 'scheduled'];
    foreach (preg_split('/\r?\n/', trim((string) $o)) as $line) {
        if (preg_match('/^(sha256|bytes|entries|databases)=(.*)$/', trim($line), $m)) { $meta[$m[1]] = $m[2]; }
    }
    if (!write_json_file(backup_store() . '/' . $fname . '.manifest.json', $meta)) {
        return ['ok' => false, 'error' => 'Archive created, but its integrity manifest could not be saved.'];
    }
    audit('backup.create', $fname . ' <= site ' . $siteId . ($databases ? ' + db ' . implode(',', $databases) : ''));
    return ['ok' => true, 'file' => $fname, 'databases' => $databases];
}

/** Delete an archive by filename. */
function backup_delete(string $file): array
{
    $abs = backup_resolve($file);
    if ($abs === null) {
        return ['ok' => false, 'error' => 'Not found.'];
    }
    $ok = @unlink($abs);
    if ($ok) @unlink($abs . '.manifest.json');
    audit('backup.delete', basename($abs) . ($ok ? '' : ' FAILED'));
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Delete failed.'];
}

/** Test gzip/tar integrity and return the number of archived entries. */
function backup_verify(string $file): array
{
    $abs = backup_resolve($file);
    if ($abs === null) {
        return ['ok' => false, 'error' => 'Not found.'];
    }
    // Inspect the listing as it streams. Buffered output is capped at 2 MB,
    // so a large archive's later entries would otherwise go unchecked.
    $entries = 0;
    $unsafe = false;
    $pending = '';
    $inspect = static function (string $entry) use (&$entries, &$unsafe): void {
        $entry = rtrim($entry, "\r");
        if ($entry === '') { return; }
        $entries++;
        if (str_starts_with($entry, '/') || preg_match('#(^|/)\.\.(/|$)#', $entry)) { $unsafe = true; }
    };
    [$code, , $err] = run_cmd_stream('tar -tzf ' . escapeshellarg($abs), static function (string $chunk, string $channel) use (&$pending, $inspect): void {
        if ($channel !== 'stdout') { return; }
        $pending .= $chunk;
        while (($newline = strpos($pending, "\n")) !== false) {
            $inspect(substr($pending, 0, $newline));
            $pending = substr($pending, $newline + 1);
        }
    }, 300);
    $inspect($pending);
    audit('backup.verify', basename($abs) . ' (exit ' . $code . ', entries ' . $entries . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => trim($err) ?: 'Archive integrity check failed.'];
    }
    if ($entries > 100000) return ['ok'=>false,'error'=>'Archive exceeds the 100,000-entry safety limit.'];
    if ($unsafe) {
        return ['ok'=>false,'error'=>'Archive contains an unsafe path.'];
    }
    $manifest = backup_manifest(basename($abs));
    $actualHash = hash_file('sha256', $abs);
    if (!preg_match('/^[a-f0-9]{64}$/', (string)($manifest['sha256']??''))
        || $actualHash === false || !hash_equals((string)$manifest['sha256'], $actualHash)) {
        return ['ok'=>false,'error'=>'Backup checksum manifest is missing or does not match.'];
    }
    return ['ok' => true, 'entries' => $entries, 'size' => @filesize($abs) ?: 0, 'sha256'=>$actualHash];
}

/**
 * Restore an archive into a managed site. $targetSiteId defaults to the site
 * the archive was taken from; choosing another site recovers a deleted
 * website after it has been re-created. A safety backup of the target is
 * taken first unless $safetyBackup is false.
 */
function backup_restore(string $file, bool $restoreFiles, array $databases, ?string $targetSiteId = null, bool $safetyBackup = true, ?callable $onOutput = null): array
{
    $abs = backup_resolve($file);
    if ($abs === null) { return ['ok' => false, 'error' => 'Backup not found.']; }
    $name = basename($abs);
    $manifest = backup_manifest($name);
    $targetSiteId = $targetSiteId !== null && $targetSiteId !== '' ? $targetSiteId : (string) ($manifest['site_id'] ?? '');
    $site = backup_find_site($targetSiteId);
    if (!$site) {
        return ['ok' => false, 'error' => 'The website this backup belongs to no longer exists. Re-create it on the Websites page, then restore into it.'];
    }
    $available = array_values(array_filter(explode(',', (string) ($manifest['databases'] ?? ''))));
    $databases = array_values(array_unique(array_map('strval', $databases)));
    foreach ($databases as $database) {
        if (!in_array($database, $available, true)) {
            return ['ok' => false, 'error' => 'This backup does not contain database ' . $database . '.'];
        }
    }
    if (!$restoreFiles && !$databases) { return ['ok' => false, 'error' => 'Choose the files, a database, or both to restore.']; }
    $step = static function (string $text) use ($onOutput): void { if ($onOutput) { $onOutput('==> ' . $text . "\n", 'stdout'); } };

    $step('Verifying the backup checksum');
    $verified = backup_verify($name);
    if (empty($verified['ok'])) { return ['ok' => false, 'error' => 'The backup failed verification: ' . ($verified['error'] ?? 'unknown error')]; }

    $safety = null;
    if ($safetyBackup) {
        $step('Taking a safety backup of the current site');
        $safety = backup_create((string) $site['id'], backup_default_label((string) $site['domain'], 'pre-restore-'), (bool) $databases, $onOutput, 'pre-restore');
        if (empty($safety['ok'])) {
            return ['ok' => false, 'error' => 'The safety backup failed, so nothing was restored: ' . ($safety['error'] ?? '')];
        }
    }
    $args = 'site-restore ' . escapeshellarg((string) $site['id']) . ' ' . escapeshellarg($name) . ' ' . ($restoreFiles ? 'yes' : 'no')
        . ($databases ? ' ' . implode(' ', array_map('escapeshellarg', $databases)) : '');
    [$c, $o] = $onOutput ? helper_cmd_stream($args, $onOutput, 3600) : helper_cmd($args, 3600);
    audit('backup.restore', $name . ' -> site ' . $site['id'] . ' (files ' . ($restoreFiles ? 'yes' : 'no')
        . ($databases ? ', db ' . implode(',', $databases) : '') . ', exit ' . $c . ')');
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim(preg_replace('/^==> .*$/m', '', (string) $o) ?? '') ?: 'Restore failed.',
            'safety_backup' => $safety['file'] ?? null];
    }
    if ($databases) {
        // A restored database belongs to the site it was restored into.
        require_once APP_ROOT . '/lib/mod_db.php';
        $links = db_links();
        foreach ($databases as $database) { $links[$database] = (string) $site['domain']; }
        write_json_file(db_links_file(), $links);
    }
    return ['ok' => true, 'safety_backup' => $safety['file'] ?? null, 'domain' => $site['domain']];
}

// ---------------------------------------------------------------------------
// Schedule and retention
// ---------------------------------------------------------------------------

function backup_schedule_file(): string
{
    return DATA_DIR . '/backup-schedule.json';
}

function backup_schedule_defaults(): array
{
    return ['enabled' => false, 'frequency' => 'daily', 'hour' => 2, 'weekday' => 0, 'keep' => 7,
        'include_databases' => true, 'sites' => [], 'last_run' => 0, 'last_result' => null];
}

function backup_schedule(): array
{
    $saved = @json_decode((string) @file_get_contents(backup_schedule_file()), true);
    return array_merge(backup_schedule_defaults(), is_array($saved) ? $saved : []);
}

/** Validate and store schedule settings (the run history is preserved). */
function backup_schedule_save(array $input): array
{
    $current = backup_schedule();
    $frequency = (string) ($input['frequency'] ?? $current['frequency']);
    $hour = filter_var($input['hour'] ?? $current['hour'], FILTER_VALIDATE_INT);
    $weekday = filter_var($input['weekday'] ?? $current['weekday'], FILTER_VALIDATE_INT);
    $keep = filter_var($input['keep'] ?? $current['keep'], FILTER_VALIDATE_INT);
    if (!in_array($frequency, ['daily', 'weekly'], true)) return ['ok' => false, 'error' => 'Frequency must be daily or weekly.'];
    if ($hour === false || $hour < 0 || $hour > 23) return ['ok' => false, 'error' => 'Hour must be between 0 and 23.'];
    if ($weekday === false || $weekday < 0 || $weekday > 6) return ['ok' => false, 'error' => 'Invalid weekday.'];
    if ($keep === false || $keep < 1 || $keep > 90) return ['ok' => false, 'error' => 'Keep between 1 and 90 backups per website.'];
    $sites = [];
    foreach ((array) ($input['sites'] ?? []) as $id) {
        if (!preg_match('/^[a-f0-9]{32}$/', (string) $id)) return ['ok' => false, 'error' => 'Invalid website selection.'];
        $sites[] = (string) $id;
    }
    $enabled = (bool) ($input['enabled'] ?? false);
    $next = array_merge($current, [
        'enabled' => $enabled, 'frequency' => $frequency, 'hour' => $hour, 'weekday' => $weekday, 'keep' => $keep,
        'include_databases' => (bool) ($input['include_databases'] ?? true), 'sites' => array_values(array_unique($sites)),
    ]);
    // A schedule switched on mid-day should start at its next slot, not
    // immediately for the slot that already passed.
    if ($enabled && !$current['enabled']) { $next['last_run'] = time(); }
    if (!write_json_file(backup_schedule_file(), $next)) return ['ok' => false, 'error' => 'Could not save the backup schedule.'];
    audit('backup.schedule', ($enabled ? 'enabled ' : 'disabled ') . $frequency . ' at ' . $hour . ':00, keep ' . $keep);
    return ['ok' => true, 'schedule' => $next, 'next_run' => backup_schedule_next($next)];
}

/** The most recent scheduled slot at or before $now (server local time). */
function backup_schedule_last_slot(array $schedule, int $now): int
{
    $slot = mktime((int) $schedule['hour'], 0, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
    if ($slot > $now) { $slot = strtotime('-1 day', $slot); }
    if ($schedule['frequency'] === 'weekly') {
        while ((int) date('w', $slot) !== (int) $schedule['weekday']) { $slot = strtotime('-1 day', $slot); }
    }
    return $slot;
}

function backup_schedule_next(array $schedule, ?int $now = null): ?int
{
    if (empty($schedule['enabled'])) { return null; }
    $now = $now ?? time();
    $step = $schedule['frequency'] === 'weekly' ? '+7 days' : '+1 day';
    return strtotime($step, backup_schedule_last_slot($schedule, $now));
}

function backup_schedule_due(array $schedule, int $now): bool
{
    return !empty($schedule['enabled']) && (int) $schedule['last_run'] < backup_schedule_last_slot($schedule, $now);
}

/** Called by the scheduler: run the schedule when a slot has passed. */
function backup_schedule_run_if_due(?int $now = null): array
{
    $now = $now ?? time();
    $schedule = backup_schedule();
    if (!backup_schedule_due($schedule, $now)) {
        return ['ok' => true, 'ran' => false, 'next_run' => backup_schedule_next($schedule, $now)];
    }
    return backup_schedule_run(null, $now);
}

/** Back up every scheduled site now, then apply retention. */
function backup_schedule_run(?callable $onOutput = null, ?int $now = null): array
{
    require_once APP_ROOT . '/lib/mod_sites.php';
    $now = $now ?? time();
    $schedule = backup_schedule();
    $selected = $schedule['sites'];
    $created = [];
    $failed = [];
    $sites = array_values(array_filter(sites_list(), static fn($site) => preg_match('/^[a-f0-9]{32}$/', (string) ($site['id'] ?? ''))
        && (!$selected || in_array($site['id'], $selected, true))));
    foreach ($sites as $site) {
        if ($onOutput) { $onOutput('==> Backing up ' . $site['domain'] . "\n", 'stdout'); }
        $res = backup_create((string) $site['id'], backup_default_label((string) $site['domain'], 'auto-'), (bool) $schedule['include_databases'], $onOutput, 'scheduled');
        if (!empty($res['ok'])) { $created[] = $res['file']; }
        else { $failed[] = ['domain' => (string) $site['domain'], 'error' => (string) ($res['error'] ?? 'failed')]; }
    }
    if ($onOutput) { $onOutput("==> Applying retention\n", 'stdout'); }
    $pruned = backup_apply_retention((int) $schedule['keep']);
    $result = ['at' => $now, 'ok' => !$failed, 'created' => count($created), 'failed' => $failed, 'pruned' => count($pruned)];
    $latest = backup_schedule();
    $latest['last_run'] = $now;
    $latest['last_result'] = $result;
    write_json_file(backup_schedule_file(), $latest);
    audit('backup.schedule_run', count($created) . ' created, ' . count($failed) . ' failed, ' . count($pruned) . ' pruned');
    $res = ['ok' => !$failed, 'ran' => true, 'created' => $created, 'failed' => $failed, 'pruned' => $pruned];
    if ($failed) {
        $res['error'] = 'Backups failed for ' . implode(', ', array_column($failed, 'domain')) . ': ' . $failed[0]['error'];
    }
    return $res;
}

/** Keep the newest $keep scheduled archives per site; manual ones are never pruned. */
function backup_apply_retention(int $keep): array
{
    $keep = max(1, $keep);
    $bySite = [];
    foreach (backup_list() as $backup) {
        if ($backup['kind'] === 'scheduled' && $backup['site_id'] !== '') { $bySite[$backup['site_id']][] = $backup; }
    }
    $deleted = [];
    foreach ($bySite as $rows) {
        foreach (array_slice($rows, $keep) as $old) {
            if (!empty(backup_delete($old['file'])['ok'])) { $deleted[] = $old['file']; }
        }
    }
    return $deleted;
}

/** Health checks for the dashboard and alerts. */
function backup_health_items(): array
{
    $items = [];
    $schedule = backup_schedule();
    $backups = backup_list();
    $maxAge = !empty($schedule['enabled']) ? ($schedule['frequency'] === 'weekly' ? 8 : 2) * 86400 : 7 * 86400;
    if (!$backups) {
        $items[] = ['level' => 'info', 'key' => 'backups', 'title' => 'No panel backups yet',
            'detail' => 'Create an archive of each important website, or turn on scheduled backups', 'route' => 'backups', 'icon' => 'archive'];
    } elseif (time() - (int) $backups[0]['mtime'] > $maxAge) {
        $items[] = ['level' => 'warning', 'key' => 'backups', 'title' => 'Latest backup is over ' . (int) ($maxAge / 86400) . ' days old',
            'detail' => 'Most recent: ' . fmt_datetime((int) $backups[0]['mtime']), 'route' => 'backups', 'icon' => 'archive'];
    }
    $last = $schedule['last_result'] ?? null;
    if (!empty($schedule['enabled']) && is_array($last) && empty($last['ok'])) {
        $domains = implode(', ', array_column((array) ($last['failed'] ?? []), 'domain'));
        $items[] = ['level' => 'critical', 'key' => 'backup_failed', 'title' => 'Scheduled backup failed',
            'detail' => 'Run at ' . fmt_datetime((int) $last['at']) . ($domains !== '' ? ' — ' . $domains : ''), 'route' => 'backups', 'icon' => 'archive-x'];
    }
    return $items;
}
