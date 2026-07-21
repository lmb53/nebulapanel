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
    'log_errors',
    'max_file_uploads',
    'default_socket_timeout',
    'opcache.enable',
    'opcache.memory_consumption',
    'opcache.max_accelerated_files',
    'opcache.validate_timestamps',
    'xdebug.mode',
    'xdebug.client_port',
    'xdebug.start_with_request',
];

const PHP_EXTENSION_CATALOG = [
    'curl' => ['cURL', 'HTTP client support', ['curl']],
    'mbstring' => ['Multibyte String', 'Unicode and multibyte string handling', ['mbstring']],
    'xml' => ['XML', 'DOM, SimpleXML, XMLReader and XMLWriter', ['dom', 'SimpleXML', 'xml', 'xmlreader', 'xmlwriter']],
    'gd' => ['GD', 'Image creation and processing', ['gd']],
    'zip' => ['ZIP', 'ZIP archive support', ['zip']],
    'mysql' => ['MySQL', 'MySQLi and PDO MySQL drivers', ['mysqli', 'pdo_mysql']],
    'intl' => ['Internationalization', 'ICU locale and formatting support', ['intl']],
    'bcmath' => ['BCMath', 'Arbitrary precision mathematics', ['bcmath']],
    'soap' => ['SOAP', 'SOAP client and server support', ['soap']],
    'imagick' => ['ImageMagick', 'Advanced image processing bindings', ['imagick']],
    'redis' => ['Redis', 'Redis client extension', ['redis']],
    'xdebug' => ['Xdebug', 'Debugger, profiler and coverage tools', ['xdebug']],
    'imap' => ['IMAP', 'Mailbox access support', ['imap']],
    'opcache' => ['OPcache', 'PHP bytecode cache', ['Zend OPcache']],
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
    if (!in_array($ver, php_installed_versions(), true)) {
        return ['ok' => false, 'error' => 'PHP version is not installed.'];
    }
    if (!in_array($key, PHP_INI_KEYS, true)) {
        return ['ok' => false, 'error' => 'Setting is not editable.'];
    }
    if (!preg_match('/^[A-Za-z0-9_.,-]+$/', $val)) {
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

function php_ini_path(string $version, string $sapi = 'fpm'): ?string
{
    if (!php_valid_version($version) || !in_array($sapi, ['fpm', 'cli'], true)) { return null; }
    $path = "/etc/php/$version/$sapi/php.ini";
    return is_file($path) && is_readable($path) ? $path : null;
}

function php_ini_content(string $version): array
{
    $path = php_ini_path($version, 'fpm');
    if ($path === null) { return ['ok' => false, 'error' => 'FPM php.ini is not readable.', 'content' => '']; }
    $content = @file_get_contents($path);
    if ($content === false || strlen($content) > 350000) {
        return ['ok' => false, 'error' => 'php.ini could not be read or is too large.', 'content' => ''];
    }
    return ['ok' => true, 'path' => $path, 'content' => $content, 'backup' => is_file($path . '.nebula-backup')];
}

function php_ini_replace(string $version, string $content): array
{
    if (!php_valid_version($version) || !in_array($version, php_installed_versions(), true)) {
        return ['ok' => false, 'error' => 'PHP version is not installed.'];
    }
    if ($content === '' || strlen($content) > 350000 || strpos($content, "\0") !== false || stripos($content, '[PHP]') === false) {
        return ['ok' => false, 'error' => 'php.ini is empty, oversized, or missing its [PHP] section.'];
    }
    [$code, $output] = helper_cmd('php-ini-replace ' . escapeshellarg($version) . ' ' . escapeshellarg(base64_encode($content)), 120);
    audit('php.ini.full', $version . ' (' . strlen($content) . ' bytes)');
    return $code === 0 ? ['ok' => true] : ['ok' => false, 'error' => trim($output) ?: 'Could not save php.ini.'];
}

function php_ini_restore(string $version): array
{
    if (!php_valid_version($version) || !in_array($version, php_installed_versions(), true)) {
        return ['ok' => false, 'error' => 'PHP version is not installed.'];
    }
    [$code, $output] = helper_cmd('php-ini-restore ' . escapeshellarg($version));
    audit('php.ini.restore', $version);
    return $code === 0 ? ['ok' => true] : ['ok' => false, 'error' => trim($output) ?: 'Could not restore php.ini.'];
}

function php_extension_states(string $version): array
{
    $loaded = array_map('strtolower', php_modules($version));
    $states = [];
    foreach (PHP_EXTENSION_CATALOG as $key => $meta) {
        $installed = false;
        $enabled = false;
        foreach ($meta[2] as $module) {
            $moduleLower = strtolower($module);
            if (in_array($moduleLower, $loaded, true)) { $enabled = true; $installed = true; }
            if (is_file("/etc/php/$version/mods-available/$moduleLower.ini")) { $installed = true; }
        }
        [$packageCode] = run_cmd('dpkg-query -W -f=' . escapeshellarg('${Status}') . ' ' . escapeshellarg("php$version-$key") . ' 2>/dev/null');
        if ($packageCode === 0) { $installed = true; }
        $states[] = ['key' => $key, 'label' => $meta[0], 'description' => $meta[1], 'installed' => $installed, 'enabled' => $enabled];
    }
    return $states;
}

function php_extension_action(string $version, string $extension, string $action, ?callable $onOutput = null): array
{
    if (!in_array($version, php_installed_versions(), true)) { return ['ok' => false, 'error' => 'PHP version is not installed.']; }
    if (!isset(PHP_EXTENSION_CATALOG[$extension]) || !in_array($action, ['install', 'enable', 'disable'], true)) {
        return ['ok' => false, 'error' => 'Invalid extension action.'];
    }
    $args = 'php-extension ' . escapeshellarg($action) . ' ' . escapeshellarg($version) . ' ' . escapeshellarg($extension);
    [$code, $output] = $onOutput ? helper_cmd_stream($args, $onOutput, 900) : helper_cmd($args, 900);
    audit('php.extension', "$version $extension $action (exit $code)");
    return $code === 0 ? ['ok' => true, 'output' => $output] : ['ok' => false, 'error' => trim($output) ?: 'Extension action failed.'];
}

function php_restart_fpm(string $version): array
{
    if ($version !== 'all' && !in_array($version, php_installed_versions(), true)) {
        return ['ok' => false, 'error' => 'PHP version is not installed.'];
    }
    [$code, $output] = helper_cmd('php-restart ' . escapeshellarg($version));
    audit('php.restart', $version);
    return $code === 0 ? ['ok' => true] : ['ok' => false, 'error' => trim($output) ?: 'Restart failed.'];
}

function php_support_status(string $version): array
{
    $dates = [
        '8.2' => ['security', '2026-12-31'], '8.3' => ['security', '2027-12-31'],
        '8.4' => ['active', '2028-12-31'], '8.5' => ['active', '2029-12-31'],
    ];
    if (!isset($dates[$version])) { return ['key' => 'eol', 'label' => 'End of life', 'until' => null]; }
    return ['key' => $dates[$version][0], 'label' => $dates[$version][0] === 'active' ? 'Active support' : 'Security fixes', 'until' => $dates[$version][1]];
}

function php_version_summaries(): array
{
    require_once APP_ROOT . '/lib/mod_sites.php';
    $sites = sites_list();
    $summaries = [];
    foreach (php_installed_versions() as $version) {
        $used = array_values(array_filter($sites, fn($site) => ($site['php'] ?? '') === $version));
        [$code, $memory] = run_cmd('systemctl show ' . escapeshellarg("php$version-fpm") . ' -p MemoryCurrent --value 2>/dev/null');
        $summaries[] = [
            'version' => $version,
            'status' => service_status("php$version-fpm"),
            'memory' => $code === 0 && ctype_digit(trim($memory)) ? (int) trim($memory) : 0,
            'sites' => count($used),
            'support' => php_support_status($version),
            'default' => $version === php_default_version(),
        ];
    }
    return $summaries;
}

function php_fpm_pools(string $version): array
{
    if (!php_valid_version($version)) { return []; }
    $pools = [];
    foreach (glob("/etc/php/$version/fpm/pool.d/*.conf") ?: [] as $file) {
        $content = (string) @file_get_contents($file);
        if ($content === '') { continue; }
        preg_match('/^\s*\[([^\]]+)\]/m', $content, $name);
        $read = static function (string $key) use ($content): string {
            return preg_match_all('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.+)$/m', $content, $matches)
                ? trim((string) end($matches[1])) : '';
        };
        $pools[] = ['name' => $name[1] ?? basename($file, '.conf'), 'file' => $file, 'listen' => $read('listen'), 'pm' => $read('pm'), 'max_children' => $read('pm.max_children'), 'user' => $read('user')];
    }
    return $pools;
}

function php_composer_version(): string
{
    if (!has_cmd('composer')) { return ''; }
    [$code, $output] = run_cmd('composer --version --no-ansi 2>/dev/null', 10);
    return $code === 0 ? trim($output) : '';
}

function php_composer_install(?callable $onOutput = null): array
{
    if (has_cmd('composer')) { return ['ok' => false, 'error' => 'Composer is already installed.']; }
    [$code, $output] = $onOutput
        ? helper_cmd_stream('composer-install', $onOutput, 900)
        : helper_cmd('composer-install', 900);
    audit('php.composer.install', 'exit ' . $code);
    return $code === 0 ? ['ok' => true, 'output' => $output] : ['ok' => false, 'error' => trim($output) ?: 'Composer installation failed.'];
}
