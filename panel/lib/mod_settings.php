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
        if ($panelName === '' || strlen($panelName) > 60) {
            return ['ok' => false, 'error' => 'Panel name must be 1–60 characters.'];
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
    if (strlen($new) < 12 || strlen($new) > 1024) {
        return ['ok' => false, 'error' => 'New password must be between 12 and 1024 characters.'];
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
        $users[$index]['hash'] = password_hash($new, PASSWORD_DEFAULT);
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
    $_SESSION['session_version'] = (int) $changedUser['session_version'];
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
    $data = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$data) {
        return '';
    }
    return implode("\n", array_slice($data, -$lines));
}
