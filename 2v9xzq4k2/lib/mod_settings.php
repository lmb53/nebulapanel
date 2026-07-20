<?php
/** Settings module — panel prefs, admin password change, audit log. */

function settings_overrides(): array
{
    $j = @json_decode((string) @file_get_contents(APP_ROOT . '/data/settings.json'), true);
    return is_array($j) ? $j : [];
}

function settings_save_overrides(array $ov): array
{
    $ok = @file_put_contents(APP_ROOT . '/data/settings.json', json_encode($ov, JSON_PRETTY_PRINT), LOCK_EX);
    if ($ok === false) {
        return ['ok' => false, 'error' => 'Could not write data/settings.json (check permissions).'];
    }
    @chmod(APP_ROOT . '/data/settings.json', 0600);
    return ['ok' => true];
}

function settings_update_general(?string $panelName, $sessionTimeout): array
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
    $admin = json_decode((string) @file_get_contents(admin_file()), true);
    if (!is_array($admin) || empty($admin['hash']) || !password_verify($current, $admin['hash'])) {
        usleep(300000);
        return ['ok' => false, 'error' => 'Current password is incorrect.'];
    }
    if (strlen($new) < 8) {
        return ['ok' => false, 'error' => 'New password must be at least 8 characters.'];
    }
    $admin['hash'] = password_hash($new, PASSWORD_DEFAULT);
    $admin['updated'] = date('c');
    $ok = @file_put_contents(admin_file(), json_encode($admin, JSON_PRETTY_PRINT), LOCK_EX);
    if ($ok === false) {
        return ['ok' => false, 'error' => 'Could not update the admin file.'];
    }
    @chmod(admin_file(), 0600);
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
