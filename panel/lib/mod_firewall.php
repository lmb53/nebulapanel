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
/**
 * Add a UFW rule. $port is a number, a range "8000:8100" or a UFW service
 * name; $from optionally limits the rule to one address or network (CIDR).
 * Rules that would block SSH or the panel itself for everyone are refused.
 */
function fw_add(string $action, string $port, string $proto, string $from = ''): array
{
    if (!in_array($action, ['allow', 'deny', 'reject'], true)) {
        return ['ok' => false, 'error' => 'Invalid action.'];
    }
    $port = trim($port);
    $from = trim($from);
    $isRange = (bool) preg_match('/^\d{1,5}:\d{1,5}$/', $port);
    if (!preg_match('/^(\d{1,5}|\d{1,5}:\d{1,5}|[A-Za-z][A-Za-z0-9-]{0,39})$/', $port)) {
        return ['ok' => false, 'error' => 'Enter a port (22), a range (8000:8100) or a service name (http).'];
    }
    foreach (array_map('intval', explode(':', $port)) as $number) {
        if (ctype_digit(str_replace(':', '', $port)) && ($number < 1 || $number > 65535)) {
            return ['ok' => false, 'error' => 'Ports must be between 1 and 65535.'];
        }
    }
    if ($isRange && (int) explode(':', $port)[0] >= (int) explode(':', $port)[1]) {
        return ['ok' => false, 'error' => 'A port range must go from low to high.'];
    }
    if (!in_array($proto, ['tcp', 'udp', 'any'], true)) {
        return ['ok' => false, 'error' => 'Invalid protocol.'];
    }
    if ($isRange && $proto === 'any') {
        return ['ok' => false, 'error' => 'Port ranges need a protocol (TCP or UDP).'];
    }
    if ($from !== '' && !fw_source_ok($from)) {
        return ['ok' => false, 'error' => 'Source must be an IP address or a network such as 203.0.113.0/24.'];
    }
    if ($from !== '' && !ctype_digit(str_replace(':', '', $port))) {
        return ['ok' => false, 'error' => 'Rules limited to a source address need a port number, not a service name.'];
    }
    if ($action !== 'allow' && $from === '' && array_intersect(fw_port_numbers($port), fw_lockout_ports())) {
        return ['ok' => false, 'error' => 'This would block SSH or this panel for everyone and lock you out. Limit the rule to a source address instead.'];
    }
    if ($from !== '') {
        $cmd = 'ufw ' . $action . ($proto !== 'any' ? ' proto ' . $proto : '') . ' from ' . escapeshellarg($from) . ' to any port ' . escapeshellarg($port);
        $label = $action . ' ' . $port . ($proto !== 'any' ? '/' . $proto : '') . ' from ' . $from;
    } else {
        $target = ($proto === 'any') ? $port : $port . '/' . $proto;
        $cmd = 'ufw ' . $action . ' ' . escapeshellarg($target);
        $label = $action . ' ' . $target;
    }
    [$code, $out] = sudo_cmd($cmd);
    audit('firewall.add', $label . ' (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    return ['ok' => true, 'output' => trim($out)];
}

function fw_source_ok(string $source): bool
{
    [$address, $bits] = array_pad(explode('/', $source, 2), 2, null);
    if (!filter_var($address, FILTER_VALIDATE_IP)) { return false; }
    if ($bits === null) { return true; }
    $max = str_contains($address, ':') ? 128 : 32;
    return ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= $max;
}

/** Port numbers a port spec or a UFW rule's "To" column covers. */
function fw_port_numbers(string $spec): array
{
    $spec = strtolower(trim(preg_replace('#/(tcp|udp)$#', '', trim($spec)) ?? ''));
    $apps = ['openssh' => [22], 'ssh' => [22], 'nginx full' => [80, 443], 'nginx http' => [80], 'nginx https' => [443],
        'http' => [80], 'https' => [443], 'www' => [80], 'apache full' => [80, 443]];
    if (isset($apps[$spec])) { return $apps[$spec]; }
    if (preg_match('/^(\d{1,5}):(\d{1,5})$/', $spec, $m)) {
        return array_values(array_filter(fw_lockout_ports(), fn($p) => $p >= (int) $m[1] && $p <= (int) $m[2]));
    }
    $ports = [];
    foreach (explode(',', $spec) as $part) { if (ctype_digit($part)) { $ports[] = (int) $part; } }
    return $ports;
}

/** True when deleting this "ufw status numbered" row could cut off SSH or the panel. */
function fw_rule_protects_access(string $raw): bool
{
    if (!preg_match('/^(.+?)\s{2,}ALLOW(?:\s+IN)?\s+(.+)$/i', trim($raw), $m)) { return false; }
    $to = preg_replace('/\s*\(v6\)$/i', '', trim($m[1])) ?? '';
    $from = trim($m[2]);
    if (!preg_match('/^anywhere/i', $from)) { return false; }
    return (bool) array_intersect(fw_port_numbers($to), fw_lockout_ports());
}

function fw_delete(int $num, string $expect = '', bool $force = false): array
{
    if ($num < 1) {
        return ['ok' => false, 'error' => 'Invalid rule number.'];
    }
    if (!$force) {
        $status = fw_status();
        foreach ((array) ($status['rules'] ?? []) as $rule) {
            if ((int) $rule['num'] === $num && fw_rule_protects_access((string) $rule['raw'])) {
                return ['ok' => false, 'needs_force' => true, 'conflict' => true,
                    'error' => 'This rule keeps SSH or this panel reachable. Deleting it while the firewall is on can lock you out.'];
            }
        }
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
