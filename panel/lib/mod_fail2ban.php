<?php
/**
 * Fail2Ban module — surfaces what Fail2Ban is actually doing: per-jail counters,
 * the live ban list, and recent activity from its log.
 *
 * fail2ban-client requires root, so every call goes through the privileged
 * helper (which already has a single tight sudoers rule) rather than adding a
 * broad `sudo fail2ban-client` grant.
 */

function f2b_installed(): bool
{
    return has_cmd('fail2ban-client');
}

/**
 * Full status: service state plus one entry per jail with its counters and
 * currently-banned IPs.
 */
function f2b_status(): array
{
    $status = [
        'installed' => f2b_installed(),
        'active'    => 'inactive',
        'enabled'   => 'unknown',
        'version'   => '',
        'server'    => 'down',
        'error'     => '',
        'jails'     => [],
        'totals'    => ['banned' => 0, 'total_banned' => 0, 'failed' => 0, 'total_failed' => 0],
    ];
    if (!$status['installed']) {
        return $status;
    }
    if (!helper_available()) {
        $status['error'] = 'Privileged helper not installed — re-run install.sh to read Fail2Ban status.';
        return $status;
    }
    [$code, $out] = helper_cmd('f2b-status', 30);
    if ($code !== 0) {
        $err = trim($out);
        $status['error'] = stripos($err, 'unknown command') !== false
            ? 'The privileged helper on this server is out of date. Update the panel (Panel Updates) or re-run install.sh.'
            : ($err ?: 'Could not read Fail2Ban status.');
        return $status;
    }
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        if ($line === '') {
            continue;
        }
        if (strpos($line, "jail\t") === 0) {
            $c = explode("\t", $line);
            $ips = array_values(array_filter(array_map('trim', explode(',', $c[6] ?? ''))));
            $jail = [
                'name'             => $c[1] ?? '',
                'currently_failed' => (int) ($c[2] ?? 0),
                'total_failed'     => (int) ($c[3] ?? 0),
                'currently_banned' => (int) ($c[4] ?? 0),
                'total_banned'     => (int) ($c[5] ?? 0),
                'banned_ips'       => $ips,
                'logfiles'         => trim((string) ($c[7] ?? '')),
            ];
            $status['jails'][] = $jail;
            $status['totals']['banned']       += $jail['currently_banned'];
            $status['totals']['total_banned'] += $jail['total_banned'];
            $status['totals']['failed']       += $jail['currently_failed'];
            $status['totals']['total_failed'] += $jail['total_failed'];
            continue;
        }
        $kv = explode('=', $line, 2);
        if (count($kv) !== 2) {
            continue;
        }
        [$k, $v] = $kv;
        if (in_array($k, ['active', 'enabled', 'version', 'server'], true)) {
            $status[$k] = $v;
        } elseif ($k === 'server_error' && $v !== '') {
            $status['error'] = $v;
        }
    }
    return $status;
}

/** Recent Fail2Ban activity, newest last (as the log is written). */
function f2b_log(int $lines = 200): array
{
    if (!f2b_installed()) {
        return ['ok' => false, 'error' => 'Fail2Ban is not installed.'];
    }
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed.'];
    }
    $lines = max(20, min(2000, $lines));
    [$code, $out] = helper_cmd('f2b-log ' . (int) $lines, 30);
    if ($code !== 0) {
        return ['ok' => false, 'error' => trim($out) ?: 'Could not read the Fail2Ban log.'];
    }
    return ['ok' => true, 'log' => $out];
}

/** Ban or unban an address in a jail. */
function f2b_action(string $op, string $jail, string $ip): array
{
    if (!in_array($op, ['ban', 'unban'], true)) {
        return ['ok' => false, 'error' => 'Invalid action.'];
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $jail)) {
        return ['ok' => false, 'error' => 'Invalid jail name.'];
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return ['ok' => false, 'error' => 'Enter a valid IP address.'];
    }
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed.'];
    }
    [$code, $out] = helper_cmd('f2b-' . $op . ' ' . escapeshellarg($jail) . ' ' . escapeshellarg($ip), 30);
    audit('fail2ban.' . $op, $ip . ' in ' . $jail . ' (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => trim($out) ?: 'Fail2Ban command failed.'];
    }
    return ['ok' => true, 'output' => trim($out)];
}
