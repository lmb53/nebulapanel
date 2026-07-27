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
    $allowed = array_column(log_sources(), 'id');
    if (!in_array($id, $allowed, true)) {
        audit('log.read.denied', $id);
        return 'Source not allowed.';
    }

    if (strpos($id, 'unit:') === 0) {
        $unit = substr($id, 5);
        [$code, $out] = helper_cmd('log-read-unit ' . escapeshellarg($unit) . ' ' . $lines, 15);
        audit('log.read', $id . ' exit=' . $code);
        return log_redact(substr($out, 0, 1024 * 1024));
    }

    if (strpos($id, 'file:') === 0) {
        $path = substr($id, 5);
        [$code, $out] = helper_cmd('log-read-file ' . escapeshellarg($path) . ' ' . $lines, 15);
        audit('log.read', $id . ' exit=' . $code);
        return log_redact(substr($out, 0, 1024 * 1024));
    }

    return 'Unknown source.';
}

function log_redact(string $text): string
{
    $text = redact_secrets($text);
    $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [redacted]', $text) ?? $text;
    $text = preg_replace('/\b(mysql|postgres(?:ql)?):\/\/[^@\s]+@/i', '$1://[redacted]@', $text) ?? $text;
    return $text;
}
