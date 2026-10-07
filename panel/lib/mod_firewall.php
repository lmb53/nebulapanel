<?php
/**
 * Firewall module — manages UFW through validated privileged helper actions.
 */

function fw_available(): bool
{
    return has_cmd('ufw');
}

/** Current firewall status + numbered rules. */
function fw_status(): array
{
    [$code, $out] = sudo_cmd('ufw status numbered');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code), 'active' => false, 'rules' => []];
    }
    $active = stripos($out, 'Status: active') !== false;
    $rules = [];
    foreach (preg_split('/\r?\n/', $out) as $line) {
        if (preg_match('/^\[\s*(\d+)\]\s+(.*)$/', trim($line), $m)) {
            $rules[] = ['num' => (int) $m[1], 'raw' => trim($m[2])];
        }
    }
    return ['ok' => true, 'active' => $active, 'rules' => $rules];
}

/** Add a rule. $action allow|deny|reject; $port service name or number; $proto tcp|udp|any. */
function fw_add(string $action, string $port, string $proto): array
{
    if (!in_array($action, ['allow', 'deny', 'reject'], true)) {
        return ['ok' => false, 'error' => 'Invalid action.'];
    }
    if (!preg_match('/^[A-Za-z0-9-]{1,40}$/', $port)) {
        return ['ok' => false, 'error' => 'Invalid port or service name.'];
    }
    if (!in_array($proto, ['tcp', 'udp', 'any'], true)) {
        return ['ok' => false, 'error' => 'Invalid protocol.'];
    }
    $target = ($proto === 'any') ? $port : $port . '/' . $proto;
    $cmd = 'ufw ' . $action . ' ' . escapeshellarg($target);
    [$code, $out] = sudo_cmd($cmd);
    audit('firewall.add', $action . ' ' . $target . ' (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    return ['ok' => true, 'output' => trim($out)];
}

/**
 * Delete a numbered rule. UFW renumbers rules after every change, so a number
 * taken from a page loaded earlier can point at a different rule by now
 * (possibly the SSH allow rule). When the caller says which rule it saw, the
 * rule currently at that number must still match it.
 */
function fw_delete(int $num, string $expect = ''): array
{
    if ($num < 1) {
        return ['ok' => false, 'error' => 'Invalid rule number.'];
    }
    if ($expect !== '') {
        $status = fw_status();
        if (empty($status['ok'])) {
            return ['ok' => false, 'error' => (string) ($status['error'] ?? 'Could not read the firewall rules.')];
        }
        $current = null;
        foreach ($status['rules'] as $rule) {
            if ((int) $rule['num'] === $num) { $current = (string) $rule['raw']; break; }
        }
        if ($current === null || fw_rule_key($current) !== fw_rule_key($expect)) {
            return ['ok' => false, 'conflict' => true, 'error' => 'The firewall rules changed since this page loaded. Refresh and try again.'];
        }
    }
    [$code, $out] = sudo_cmd('ufw --force delete ' . (int) $num);
    audit('firewall.delete', 'rule ' . (int) $num . ' (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    return ['ok' => true, 'output' => trim($out)];
}

/** Compare UFW rule lines regardless of column padding. */
function fw_rule_key(string $raw): string
{
    return (string) preg_replace('/\s+/', ' ', trim($raw));
}

/** TCP ports sshd listens on, from its config (22 when unspecified). */
function fw_ssh_ports(): array
{
    $files = array_merge(['/etc/ssh/sshd_config'], glob('/etc/ssh/sshd_config.d/*.conf') ?: []);
    $ports = [];
    foreach ($files as $file) {
        foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*Port\s+(\d{1,5})\s*$/i', $line, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 65535) {
                $ports[] = (int) $m[1];
            }
        }
    }
    return $ports ? array_values(array_unique($ports)) : [22];
}

/**
 * Ports that must stay reachable when UFW is switched on: sshd, and the port
 * this panel request arrived on. Enabling UFW without them locks the operator
 * out of both the server and the panel.
 */
function fw_lockout_ports(): array
{
    $ports = fw_ssh_ports();
    $panel = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    $loopback = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
    if ($panel >= 1 && $panel <= 65535 && !$loopback) {
        $ports[] = $panel;
    }
    return array_values(array_unique($ports));
}

/** Enable or disable the firewall. */
function fw_set(bool $enable): array
{
    $safeguards = [];
    if ($enable) {
        foreach (fw_lockout_ports() as $port) {
            [$allowCode, $allowOut] = sudo_cmd('ufw allow ' . escapeshellarg($port . '/tcp'));
            if ($allowCode !== 0) {
                return ['ok' => false, 'error' => 'Refusing to enable UFW: could not allow port ' . $port . '/tcp first ('
                    . sudo_error($allowOut, $allowCode) . ').'];
            }
            $safeguards[] = $port . '/tcp';
        }
    }
    [$code, $out] = sudo_cmd($enable ? 'ufw --force enable' : 'ufw disable');
    audit('firewall.' . ($enable ? 'enable' : 'disable'), 'exit ' . $code . ($safeguards ? ' safeguards=' . implode(',', $safeguards) : ''));
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    return ['ok' => true, 'output' => trim($out), 'safeguards' => $safeguards];
}
