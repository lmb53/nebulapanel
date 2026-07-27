<?php
/**
 * ModSecurity module — the nginx web application firewall.
 *
 * Everything privileged (package install, nginx config, reload) lives in
 * nebula-helper; the panel only reads its key=value status output and asks for
 * a rule-engine mode change.
 */

/** Is the nginx ModSecurity connector present on this server? */
function modsec_installed(): bool
{
    return (bool) glob('/etc/nginx/modules-enabled/*modsecurity*.conf');
}

/** Full status. 'installed' is false when the connector module is absent. */
function modsec_status(): array
{
    $status = [
        'installed'     => false,
        'package'       => false,
        'crs'           => false,
        'enabled'       => false,
        'mode'          => 'unknown',
        'nginx'         => 'inactive',
        'rules_file'    => '',
        'config_file'   => '',
        'audit_log'     => '',
        'audit_events'  => 0,
        'denied_recent' => 0,
        'error'         => '',
    ];
    if (!helper_available()) {
        $status['installed'] = modsec_installed();
        $status['error'] = 'Privileged helper not installed — re-run install.sh to manage ModSecurity.';
        return $status;
    }
    [$code, $out] = helper_cmd('modsec-status', 30);
    if ($code !== 0) {
        $err = trim($out);
        $status['installed'] = modsec_installed();
        $status['error'] = stripos($err, 'unknown command') !== false
            ? 'The privileged helper on this server is out of date. Update the panel (Panel Updates) or re-run install.sh.'
            : ($err ?: 'Could not read ModSecurity status.');
        return $status;
    }
    $bool = ['installed', 'package', 'crs', 'enabled'];
    $int  = ['audit_events', 'denied_recent'];
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        $kv = explode('=', $line, 2);
        if (count($kv) !== 2 || !array_key_exists($kv[0], $status)) {
            continue;
        }
        [$k, $v] = $kv;
        if (in_array($k, $bool, true)) {
            $status[$k] = trim($v) === 'yes';
        } elseif (in_array($k, $int, true)) {
            $status[$k] = (int) trim($v);
        } else {
            $status[$k] = trim($v);
        }
    }
    return $status;
}

/** Switch the rule engine between blocking, logging-only and off. */
function modsec_set_mode(string $mode): array
{
    if (!in_array($mode, ['On', 'DetectionOnly', 'Off'], true)) {
        return ['ok' => false, 'error' => 'Invalid mode.'];
    }
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed.'];
    }
    [$code, $out] = helper_cmd('modsec-mode ' . escapeshellarg($mode), 60);
    audit('modsecurity.mode', $mode . ' (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => trim($out) ?: 'Could not change the ModSecurity mode.'];
    }
    return ['ok' => true, 'output' => trim($out), 'mode' => $mode];
}

/** Recent ModSecurity findings (nginx error log, falling back to the audit log). */
function modsec_log(int $lines = 200): array
{
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed.'];
    }
    $lines = max(20, min(2000, $lines));
    [$code, $out] = helper_cmd('modsec-log ' . (int) $lines, 30);
    if ($code !== 0) {
        return ['ok' => false, 'error' => trim($out) ?: 'Could not read the ModSecurity log.'];
    }
    return ['ok' => true, 'log' => $out];
}
