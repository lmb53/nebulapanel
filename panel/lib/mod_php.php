<?php
/**
 * PHP manager module — reads per-version php.ini settings and loaded
 * extensions, and applies whitelisted ini changes via the privileged helper.
 *
 * Depends on php_installed_versions() from lib/mod_apps.php, which the calling
 * api/view is responsible for require'ing first (not required here to avoid
 * redefining helpers).
 */

/** Whitelisted php.ini keys the panel is allowed to read / write. */
const PHP_INI_KEYS = [
    'memory_limit',
    'upload_max_filesize',
    'post_max_size',
    'max_execution_time',
    'max_input_time',
    'max_input_vars',
    'display_errors',
];

/** Is $ver a plausible "major.minor" PHP version string? */
function php_valid_version(string $ver): bool
{
    return (bool) preg_match('/^[0-9]+\.[0-9]+$/', $ver);
}

/**
 * Read the whitelisted ini keys from /etc/php/<ver>/fpm/php.ini.
 * Returns [key => value] for every whitelist key ('' when not found).
 * Returns [] if the version is invalid or the config dir is missing.
 */
function php_read_settings(string $ver): array
{
    if (!php_valid_version($ver)) {
        return [];
    }
    $dir = "/etc/php/$ver";
    if (!is_dir($dir)) {
        return [];
    }

    $out = array_fill_keys(PHP_INI_KEYS, '');
    $ini = "$dir/fpm/php.ini";
    if (!is_file($ini) || !is_readable($ini)) {
        return $out;
    }

    $parsed = @parse_ini_file($ini, false, INI_SCANNER_RAW);
    if (is_array($parsed)) {
        foreach (PHP_INI_KEYS as $k) {
            if (array_key_exists($k, $parsed) && $parsed[$k] !== null) {
                $out[$k] = trim((string) $parsed[$k]);
            }
        }
        return $out;
    }

    // Fallback: manual regex per key (last uncommented assignment wins).
    $contents = (string) @file_get_contents($ini);
    foreach (PHP_INI_KEYS as $k) {
        if (preg_match_all('/^\s*' . preg_quote($k, '/') . '\s*=\s*(.*)$/mi', $contents, $m)) {
            $val = trim(end($m[1]));
            $val = trim($val, "\"'");
            $out[$k] = $val;
        }
    }
    return $out;
}

/**
 * Loaded extension/module names for a PHP version, via `phpX -m`.
 * Skips section headers like "[Zend Modules]". Returns [] on failure.
 */
function php_modules(string $ver): array
{
    if (!php_valid_version($ver)) {
        return [];
    }
    [$code, $out] = run_cmd("php$ver -m 2>/dev/null");
    if ($code !== 0 || $out === '') {
        return [];
    }
    $mods = [];
    foreach (preg_split('/\r?\n/', $out) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '[') {
            continue;
        }
        $mods[] = $line;
    }
    return $mods;
}

/**
 * Set a whitelisted ini key for a PHP version via the privileged helper.
 * Validates version, key and value before shelling out.
 */
function php_set(string $ver, string $key, string $val): array
{
    if (!php_valid_version($ver)) {
        return ['ok' => false, 'error' => 'Invalid PHP version.'];
    }
    if (!in_array($key, PHP_INI_KEYS, true)) {
        return ['ok' => false, 'error' => 'Setting is not editable.'];
    }
    if (!preg_match('/^[A-Za-z0-9_.]+$/', $val)) {
        return ['ok' => false, 'error' => 'Value contains invalid characters.'];
    }

    [$code, $out] = helper_cmd(
        'php-ini ' . escapeshellarg($ver) . ' ' . escapeshellarg($key) . ' ' . escapeshellarg($val)
    );
    audit('php.ini', "$ver $key=$val");
    return $code === 0
        ? ['ok' => true]
        : ['ok' => false, 'error' => trim($out) ?: 'failed'];
}

/** Best-effort default version: highest installed (last in sorted list). */
function php_default_version(): string
{
    $versions = php_installed_versions();
    return $versions ? (string) end($versions) : '';
}
