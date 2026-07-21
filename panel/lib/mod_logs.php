<?php
/**
 * Logs module — read systemd journal units and whitelisted log files.
 * Sources are derived from the configured services plus a fixed set of
 * common /var/log files (only those that exist). All values used in a shell
 * command are validated (strict regex / realpath under /var/log) and passed
 * through escapeshellarg().
 */

/** Available log sources: [['id'=>..., 'label'=>...], ...]. */
function log_sources(): array
{
    global $config;
    $sources = [];

    foreach (($config['services'] ?? []) as $svc) {
        $sources[] = ['id' => 'unit:' . $svc, 'label' => $svc . ' (journal)'];
    }

    $files = ['/var/log/syslog', '/var/log/auth.log', '/var/log/kern.log', '/var/log/dpkg.log'];
    $files = array_merge($files, glob('/var/log/nginx/*.log') ?: [], glob('/var/log/apache2/*.log') ?: []);
    foreach ($files as $path) {
        if (file_exists($path)) {
            $sources[] = ['id' => 'file:' . $path, 'label' => $path];
        }
    }

    return $sources;
}

/** Read a log source, returning up to $lines (clamped 10..2000) lines of text. */
function log_read(string $id, int $lines): string
{
    $lines = max(10, min(2000, $lines));

    if (strpos($id, 'unit:') === 0) {
        $unit = substr($id, 5);
        if (!preg_match('/^[A-Za-z0-9@._-]+$/', $unit)) {
            return 'Invalid unit.';
        }
        [$code, $out] = run_cmd('journalctl -u ' . escapeshellarg($unit) . ' -n ' . $lines . ' --no-pager 2>&1');
        if ($code !== 0) {
            [$code, $out] = sudo_cmd('journalctl -u ' . escapeshellarg($unit) . ' -n ' . $lines . ' --no-pager');
        }
        return $out;
    }

    if (strpos($id, 'file:') === 0) {
        $path = substr($id, 5);
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, '/var/log/')) {
            return 'File not allowed.';
        }
        [$code, $out] = run_cmd('tail -n ' . $lines . ' ' . escapeshellarg($real) . ' 2>&1');
        if ($code !== 0) {
            [$code, $out] = sudo_cmd('tail -n ' . $lines . ' ' . escapeshellarg($real));
        }
        return $out;
    }

    return 'Unknown source.';
}
