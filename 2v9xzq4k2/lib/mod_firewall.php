<?php
/**
 * Firewall module — manages UFW (Uncomplicated Firewall) rules via sudo.
 * Requires the web user to have a sudoers rule for ufw (see README).
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

/** Delete a numbered rule. */
function fw_delete(int $num): array
{
    if ($num < 1) {
        return ['ok' => false, 'error' => 'Invalid rule number.'];
    }
    [$code, $out] = sudo_cmd('ufw --force delete ' . (int) $num);
    audit('firewall.delete', 'rule ' . (int) $num . ' (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    return ['ok' => true, 'output' => trim($out)];
}

/** Enable or disable the firewall. */
function fw_set(bool $enable): array
{
    [$code, $out] = sudo_cmd($enable ? 'ufw --force enable' : 'ufw disable');
    audit('firewall.' . ($enable ? 'enable' : 'disable'), 'exit ' . $code);
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    return ['ok' => true, 'output' => trim($out)];
}
