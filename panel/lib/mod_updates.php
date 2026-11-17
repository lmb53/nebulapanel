<?php
/**
 * Updates module — inspects and applies apt package updates through the
 * validating privileged helper.
 */

/** Is the apt package manager available? */
function upd_available(): bool
{
    return has_cmd('apt-get');
}

/**
 * List upgradable packages.
 * Parses lines like:
 *   pkg/repo 1.2-3 amd64 [upgradable from: 1.2-1]
 * Returns array of ['package','current','candidate'].
 */
function upd_list(): array
{
    [$code, $out] = run_cmd('apt list --upgradable 2>/dev/null');
    if ($code !== 0 || $out === '') {
        return [];
    }
    $pkgs = [];
    foreach (preg_split('/\r?\n/', $out) as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'Listing...') === 0) {
            continue;
        }
        // Only "name/suite version arch [upgradable from: x]" rows describe a
        // package; apt can interleave notices ("N: ...", "WARNING: ...").
        if (!preg_match('#^([a-z0-9][a-z0-9+.-]*)/\S+\s+(\S+)#i', $line, $row)) {
            continue;
        }
        $package = $row[1];
        $candidate = $row[2];
        $current = '';
        if (preg_match('/upgradable from:\s*([^\]]*)\]/', $line, $m)) {
            $current = trim($m[1]);
        }
        $pkgs[] = ['package' => $package, 'current' => $current, 'candidate' => $candidate];
    }
    return $pkgs;
}

/** Number of upgradable packages. */
function upd_count(): int
{
    return count(upd_list());
}

/** Refresh the package index (apt-get update). */
function upd_refresh(?callable $onOutput = null): array
{
    $cmd = 'apt-get -o Dpkg::Use-Pty=0 -o APT::Color=0 update';
    [$c, $o] = $onOutput ? sudo_cmd_stream($cmd, $onOutput, 300) : sudo_cmd($cmd, 300);
    return ['ok' => $c === 0, 'output' => $o, 'error' => $c === 0 ? null : sudo_error($o, $c)];
}

/** Keep locally changed configuration files without stopping to ask. */
const UPD_DPKG_OPTS = '-o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold';

/**
 * Upgrade all packages. 'upgrade' never installs or removes packages;
 * 'full-upgrade' also takes new dependencies and removals (kernel and
 * distribution point releases need it).
 */
function upd_upgrade(?callable $onOutput = null, string $mode = 'upgrade'): array
{
    if (!in_array($mode, ['upgrade', 'full-upgrade', 'autoremove'], true)) { return ['ok' => false, 'error' => 'Invalid upgrade mode.']; }
    $cmd = 'DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Use-Pty=0 -o APT::Color=0 ' . UPD_DPKG_OPTS . ' -y ' . $mode;
    [$c, $o] = $onOutput ? sudo_cmd_stream($cmd, $onOutput, 1800) : sudo_cmd($cmd, 1800);
    audit('updates.' . $mode, 'exit ' . $c);
    return ['ok' => $c === 0, 'output' => $o, 'error' => $c === 0 ? null : sudo_error($o, $c)];
}

/** Packages that full-upgrade would add or remove (it never runs anything). */
function upd_full_upgrade_preview(): array
{
    [$c, $o] = run_cmd('apt-get -s -o APT::Color=0 full-upgrade 2>/dev/null', 60);
    if ($c !== 0) { return ['ok' => false, 'error' => 'Could not simulate the upgrade.']; }
    $new = []; $remove = []; $held = [];
    foreach (preg_split('/\r?\n/', (string) $o) as $line) {
        if (preg_match('/^Inst (\S+) \(/', $line, $m)) { $new[] = $m[1]; } // "Inst pkg [old] (new …)" is an upgrade
        if (preg_match('/^Remv (\S+)/', $line, $m)) { $remove[] = $m[1]; }
    }
    if (preg_match('/The following packages have been kept back:\n((?:  .*\n)+)/', (string) $o, $m)) { $held = preg_split('/\s+/', trim($m[1])); }
    return ['ok' => true, 'new' => $new, 'remove' => $remove, 'kept_back' => $held];
}

/** unattended-upgrades state. */
function upd_auto_status(): array
{
    if (!helper_available()) { return ['ok' => false, 'error' => 'The privileged helper is not installed.']; }
    [$c, $o] = helper_cmd('auto-updates status', 30);
    if ($c !== 0) { return ['ok' => false, 'error' => trim((string) $o) ?: 'Could not read the automatic update settings.']; }
    $state = ['ok' => true];
    foreach (preg_split('/\r?\n/', trim((string) $o)) as $line) {
        if (preg_match('/^(installed|enabled|auto_reboot|reboot_time|last_run)=(.*)$/', $line, $m)) { $state[$m[1]] = $m[2]; }
    }
    $state['installed'] = ($state['installed'] ?? '') === 'yes';
    $state['enabled'] = ($state['enabled'] ?? '') === 'yes';
    $state['auto_reboot'] = ($state['auto_reboot'] ?? '') === 'true';
    $state['last_run'] = (int) ($state['last_run'] ?? 0);
    return $state;
}

/** Turn unattended security updates on (optionally rebooting at a set time) or off. */
function upd_auto_set(bool $enable, string $rebootTime = '', ?callable $onOutput = null): array
{
    if ($rebootTime !== '' && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $rebootTime)) { return ['ok' => false, 'error' => 'Reboot time must be HH:MM (24-hour).']; }
    $args = 'auto-updates ' . ($enable ? 'enable' . ($rebootTime !== '' ? ' reboot ' . escapeshellarg($rebootTime) : '') : 'disable');
    [$c, $o] = $onOutput ? helper_cmd_stream($args, $onOutput, 600) : helper_cmd($args, 600);
    if ($c !== 0) { return ['ok' => false, 'error' => trim(preg_replace('/^==> .*$/m', '', (string) $o) ?? '') ?: 'Could not change automatic updates.']; }
    audit('updates.auto', $enable ? 'enabled' . ($rebootTime !== '' ? ', reboot at ' . $rebootTime : '') : 'disabled');
    return ['ok' => true];
}

/** Upgrade one package that is currently present in apt's upgradable list. */
function upd_install_package(string $package, ?callable $onOutput = null): array
{
    if (!preg_match('/^[a-z0-9][a-z0-9+.-]{0,127}$/i', $package)) {
        return ['ok' => false, 'error' => 'Invalid package name.'];
    }
    if (!in_array($package, array_column(upd_list(), 'package'), true)) {
        return ['ok' => false, 'error' => 'Package is not currently listed as upgradable.'];
    }
    $cmd = 'DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Use-Pty=0 -o APT::Color=0 ' . UPD_DPKG_OPTS . ' install --only-upgrade -y ' . escapeshellarg($package);
    [$c, $o] = $onOutput ? sudo_cmd_stream($cmd, $onOutput, 600) : sudo_cmd($cmd, 600);
    audit('updates.package', $package . ' (exit ' . $c . ')');
    return ['ok' => $c === 0, 'output' => $o, 'error' => $c === 0 ? null : sudo_error($o, $c)];
}
