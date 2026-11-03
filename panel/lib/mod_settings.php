<?php
/** Settings module — panel prefs, admin password change, audit log. */

function settings_overrides(): array
{
    $j = @json_decode((string) @file_get_contents(APP_ROOT . '/data/settings.json'), true);
    return is_array($j) ? $j : [];
}

function settings_save_overrides(array $ov): array
{
    if (!write_json_file(APP_ROOT . '/data/settings.json', $ov)) {
        return ['ok' => false, 'error' => 'Could not write data/settings.json (check permissions).'];
    }
    return ['ok' => true];
}

function settings_update_general(?string $panelName, $sessionTimeout, $healthWarn = null, $healthCritical = null): array
{
    $ov = settings_overrides();
    if ($panelName !== null) {
        $panelName = trim($panelName);
        if ($panelName === '' || mb_strlen($panelName, 'UTF-8') > 60 || preg_match('/[\x00-\x1F\x7F]/', $panelName)) {
            return ['ok' => false, 'error' => 'Panel name must be 1–60 characters with no control characters.'];
        }
        $ov['panel_name'] = $panelName;
    }
    if ($sessionTimeout !== null && $sessionTimeout !== '') {
        $t = (int) $sessionTimeout;
        if ($t < 60 || $t > 86400) {
            return ['ok' => false, 'error' => 'Session timeout must be between 60 and 86400 seconds.'];
        }
        $ov['session_timeout'] = $t;
    }
    if ($healthWarn !== null || $healthCritical !== null) {
        $warn = (int) ($healthWarn ?? ($ov['health_warn_percent'] ?? 80));
        $critical = (int) ($healthCritical ?? ($ov['health_critical_percent'] ?? 90));
        if ($warn < 50 || $warn > 95 || $critical < 60 || $critical > 100 || $critical <= $warn) {
            return ['ok' => false, 'error' => 'Health thresholds must be 50–95%, with critical higher than warning.'];
        }
        $ov['health_warn_percent'] = $warn;
        $ov['health_critical_percent'] = $critical;
    }
    $res = settings_save_overrides($ov);
    if ($res['ok']) {
        audit('settings.general');
    }
    return $res;
}

function change_admin_password(string $current, string $new): array
{
    if (!is_setup_complete()) {
        return ['ok' => false, 'error' => 'No admin account exists.'];
    }
    if (($passwordError = panel_password_error($new, (string) ($_SESSION['username'] ?? ''))) !== null) {
        return ['ok' => false, 'error' => $passwordError];
    }
    $uid = (int) ($_SESSION['uid'] ?? 0);
    $result = with_panel_users_lock(function () use ($uid, $current, $new): array {
        $users = panel_users();
        $index = null;
        foreach ($users as $i => $user) {
            if ((int) ($user['id'] ?? 0) === $uid) { $index = $i; break; }
        }
        if ($index === null || empty($users[$index]['hash']) || !password_verify($current, (string) $users[$index]['hash'])) {
            return ['ok' => false, 'invalid_password' => true, 'error' => 'Current password is incorrect.'];
        }
        $users[$index]['hash'] = panel_password_hash($new);
        if ($users[$index]['hash'] === false) {
            return ['ok' => false, 'error' => 'Could not hash the new password.'];
        }
        $users[$index]['updated'] = date('c');
        $users[$index]['session_version'] = max(1, (int) ($users[$index]['session_version'] ?? 1)) + 1;
        if (!save_panel_users($users)) {
            return ['ok' => false, 'error' => 'Could not update the panel user store.'];
        }
        return ['ok' => true, 'user' => $users[$index]];
    });
    if (empty($result['ok'])) {
        if (!empty($result['invalid_password'])) { usleep(300000); }
        return ['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not update the panel user store.')];
    }
    $changedUser = $result['user'];
    // Keep this session alive while invalidating every other session for the account.
    session_persist(['session_version' => (int) $changedUser['session_version']]);
    if ($uid === 1 && is_file(admin_file())) {
        $legacy = json_decode((string) @file_get_contents(admin_file()), true);
        if (is_array($legacy)) { $legacy['hash']=$changedUser['hash'];$legacy['updated']=$changedUser['updated'];write_json_file(admin_file(),$legacy); }
    }
    audit('settings.password_changed');
    return ['ok' => true];
}

/** Last N lines of the audit log. */
function audit_tail(int $lines = 100): string
{
    $f = DATA_DIR . '/audit.log';
    if (!is_file($f)) {
        return '';
    }
    // The audit log can be tens of megabytes; read only its tail.
    return file_tail($f, $lines);
}

/**
 * Search the audit log (current file plus its rotated generation).
 * Filters: q (free text), actor, action (exact, or a prefix such as
 * "backup" for every backup.* event), ip, from / to (YYYY-MM-DD, inclusive).
 * Returns the newest matches first, plus the distinct actors and action
 * groups seen so the UI can offer them as choices.
 */
function audit_search(array $filters, int $limit = 100, int $offset = 0): array
{
    $limit = max(1, min(50000, $limit));
    $offset = max(0, $offset);
    $q = strtolower(trim((string) ($filters['q'] ?? '')));
    $actor = trim((string) ($filters['actor'] ?? ''));
    $action = trim((string) ($filters['action'] ?? ''));
    $ip = trim((string) ($filters['ip'] ?? ''));
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['from'] ?? '')) ? strtotime($filters['from'] . ' 00:00:00') : null;
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['to'] ?? '')) ? strtotime($filters['to'] . ' 23:59:59') : null;
    $keep = $offset + $limit;
    $ring = [];
    $total = 0;
    $actors = [];
    $actions = [];
    $scanned = 0;
    foreach ([DATA_DIR . '/audit.log.1', DATA_DIR . '/audit.log'] as $file) {
        $handle = @fopen($file, 'rb');
        if ($handle === false) { continue; }
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') { continue; }
            $scanned++;
            $event = json_decode($line, true);
            if (!is_array($event)) { $event = ['detail' => $line]; }
            $eventActor = (string) ($event['actor'] ?? '');
            $eventAction = (string) ($event['action'] ?? '');
            if ($eventActor !== '' && count($actors) < 200) { $actors[$eventActor] = true; }
            $group = explode('.', $eventAction, 2)[0];
            if ($group !== '' && count($actions) < 200) { $actions[$group] = true; }
            if ($actor !== '' && $eventActor !== $actor) { continue; }
            if ($action !== '' && $eventAction !== $action && !str_starts_with($eventAction, $action . '.')) { continue; }
            if ($ip !== '' && (string) ($event['ip'] ?? '') !== $ip) { continue; }
            if ($from !== null || $to !== null) {
                $time = strtotime((string) ($event['time'] ?? ''));
                if ($time === false || ($from !== null && $time < $from) || ($to !== null && $time > $to)) { continue; }
            }
            if ($q !== '' && !str_contains(strtolower($line), $q)) { continue; }
            $total++;
            $ring[] = $event;
            if (count($ring) > $keep) { array_shift($ring); }
        }
        fclose($handle);
    }
    $events = array_slice(array_reverse($ring), $offset, $limit);
    ksort($actors);
    ksort($actions);
    return [
        'ok' => true, 'events' => $events, 'total' => $total, 'scanned' => $scanned,
        'offset' => $offset, 'limit' => $limit, 'more' => $offset + count($events) < $total,
        'actors' => array_keys($actors), 'actions' => array_keys($actions),
    ];
}

/** Stream audit search results as a CSV or JSON download. */
function audit_export(array $filters, string $format): void
{
    $result = audit_search($filters, 50000, 0);
    $name = 'nebula-audit-' . date('Ymd-His') . ($format === 'csv' ? '.csv' : '.json');
    audit('audit.export', $format . ', ' . count($result['events']) . ' events');
    header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8'));
    header('Content-Disposition: ' . attachment_header($name));
    header('Cache-Control: no-store');
    if ($format !== 'csv') {
        echo json_encode($result['events'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['time', 'actor', 'role', 'action', 'detail', 'ip', 'auth_id', 'request_id'], ',', '"', '');
    foreach ($result['events'] as $event) {
        $row = [];
        foreach (['time', 'actor', 'role', 'action', 'detail', 'ip', 'auth_id', 'request_id'] as $key) {
            $value = (string) ($event[$key] ?? '');
            // Neutralise spreadsheet formula injection.
            if ($value !== '' && strpbrk($value[0], '=+-@') !== false) { $value = "'" . $value; }
            $row[] = $value;
        }
        fputcsv($out, $row, ',', '"', '');
    }
    fclose($out);
    exit;
}
