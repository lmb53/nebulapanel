<?php
/**
 * phpMyAdmin module — installs/removes phpMyAdmin under the web document root
 * via the privileged helper (nebula-helper pma-install / pma-remove).
 * State is tracked in data/pma.json.
 */

/** Path to the state file. */
function pma_file(): string
{
    return APP_ROOT . '/data/pma.json';
}

/** Decoded state array, or null if none. */
function pma_get(): ?array
{
    $f = pma_file();
    if (!is_file($f)) {
        return null;
    }
    $s = json_decode((string) @file_get_contents($f), true);
    return is_array($s) ? $s : null;
}

/** Is phpMyAdmin currently installed (state + files present)? */
function pma_installed(): bool
{
    $s = pma_get();
    return is_array($s)
        && !empty($s['dir'])
        && is_dir($s['dir'])
        && is_file($s['dir'] . '/config.inc.php');
}

/** Public URL of the installed phpMyAdmin, or null. */
function pma_url(): ?string
{
    $s = pma_get();
    return $s['url'] ?? null;
}

/** Install phpMyAdmin into the web document root via the helper. */
function pma_install(?callable $onOutput = null): array
{
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed.'];
    }
    if (pma_installed()) {
        $s = pma_get();
        return ['ok' => true, 'url' => $s['url']];
    }
    $name = 'dbadmin-' . bin2hex(random_bytes(4));
    $target = dirname(APP_ROOT) . '/' . $name;
    $args = 'pma-install ' . escapeshellarg($target);
    [$c, $o] = $onOutput ? helper_cmd_stream($args, $onOutput, 300) : helper_cmd($args, 300);
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'install failed'];
    }
    $url = '/' . $name . '/';
    @file_put_contents(
        pma_file(),
        json_encode(['dir' => $target, 'url' => $url, 'installed_at' => date('c')], JSON_PRETTY_PRINT),
        LOCK_EX
    );
    @chmod(pma_file(), 0600);
    audit('pma.install', $target);
    return ['ok' => true, 'url' => $url];
}

/** Remove phpMyAdmin and clear state. */
function pma_remove(): array
{
    $s = pma_get();
    if (!$s) {
        return ['ok' => true];
    }
    if (helper_available() && !empty($s['dir'])) {
        [$c, $o] = helper_cmd('pma-remove ' . escapeshellarg($s['dir']));
        if ($c !== 0) {
            return ['ok' => false, 'error' => trim($o)];
        }
    }
    @unlink(pma_file());
    audit('pma.remove');
    return ['ok' => true];
}
