<?php
/**
 * Updates module — inspects and applies apt package updates.
 * Read operations use the unprivileged apt client; refresh/upgrade need a
 * sudoers rule for apt-get (see README).
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
        $parts = preg_split('/\s+/', $line);
        $package = explode('/', $parts[0])[0];
        $candidate = $parts[1] ?? '';
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

/** Upgrade all packages (apt-get -y upgrade). */
function upd_upgrade(?callable $onOutput = null): array
{
    $cmd = 'DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Use-Pty=0 -o APT::Color=0 -y upgrade';
    [$c, $o] = $onOutput ? sudo_cmd_stream($cmd, $onOutput, 900) : sudo_cmd($cmd, 900);
    audit('updates.upgrade');
    return ['ok' => $c === 0, 'output' => $o, 'error' => $c === 0 ? null : sudo_error($o, $c)];
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
    $cmd = 'DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Use-Pty=0 -o APT::Color=0 install --only-upgrade -y ' . escapeshellarg($package);
    [$c, $o] = $onOutput ? sudo_cmd_stream($cmd, $onOutput, 600) : sudo_cmd($cmd, 600);
    audit('updates.package', $package . ' (exit ' . $c . ')');
    return ['ok' => $c === 0, 'output' => $o, 'error' => $c === 0 ? null : sudo_error($o, $c)];
}
