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

function pma_save(array $state): bool
{
    return write_json_file(pma_file(), $state, 0600);
}

function pma_signon_url(): string
{
    global $https;
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    return ((bool) $https ? 'https://' : 'http://') . $host . url('pma-signon');
}

/** Ensure an installed copy uses phpMyAdmin's signon authentication driver. */
function pma_ensure_signon(): array
{
    $state = pma_get();
    if (!$state || empty($state['dir'])) {
        return ['ok' => false, 'error' => 'phpMyAdmin is not installed.'];
    }
    if (empty($state['token_secret'])) {
        $state['token_secret'] = bin2hex(random_bytes(32));
        $state['accounts'] = (array) ($state['accounts'] ?? []);
        if (!pma_save($state)) {
            return ['ok' => false, 'error' => 'Could not save phpMyAdmin signon state.'];
        }
    }
    [$c, $o] = helper_cmd(
        'pma-signon ' . escapeshellarg((string) $state['dir']) . ' ' . escapeshellarg(pma_signon_url())
    );
    return $c === 0 ? ['ok' => true] : ['ok' => false, 'error' => trim($o) ?: 'Could not configure phpMyAdmin signon.'];
}

function pma_b64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function pma_b64url_decode(string $value): ?string
{
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $value)) { return null; }
    $raw = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    return $raw === false ? null : $raw;
}

/** Per-database least-privilege account used only by phpMyAdmin signon. */
function pma_database_account(string $database): array
{
    require_once APP_ROOT . '/lib/mod_db.php';
    if (!db_ident_ok($database) || in_array($database, SYSTEM_DBS, true)) {
        return ['ok' => false, 'error' => 'Invalid database.'];
    }
    $found = false;
    foreach (db_list()['databases'] ?? [] as $db) {
        if (($db['name'] ?? '') === $database) { $found = true; break; }
    }
    if (!$found) { return ['ok' => false, 'error' => 'Database not found.']; }

    $state = pma_get() ?: [];
    $key = hash('sha256', $database);
    $account = $state['accounts'][$key] ?? null;
    if (!is_array($account) || empty($account['user']) || empty($account['password'])) {
        $account = [
            'database' => $database,
            'user' => 'nebula_pma_' . substr($key, 0, 12),
            'password' => pma_b64url_encode(random_bytes(30)),
        ];
    }
    $user = (string) $account['user'];
    $password = db_sql_str((string) $account['password']);
    $sql = "CREATE USER IF NOT EXISTS '$user'@'localhost' IDENTIFIED BY $password; "
        . "ALTER USER '$user'@'localhost' IDENTIFIED BY $password; "
        . "GRANT ALL PRIVILEGES ON `$database`.* TO '$user'@'localhost'; FLUSH PRIVILEGES;";
    [$c, $o] = db_run($sql);
    if ($c !== 0) { return ['ok' => false, 'error' => sudo_error($o, $c)]; }
    $state['accounts'][$key] = $account;
    if (!pma_save($state)) { return ['ok' => false, 'error' => 'Could not save phpMyAdmin account state.']; }
    return ['ok' => true, 'account' => $account];
}

/** Return a short-lived HMAC-signed, password-free launch URL. */
function pma_launch(string $database): array
{
    if (!pma_installed()) { return ['ok' => false, 'error' => 'phpMyAdmin is not installed.']; }
    $configured = pma_ensure_signon();
    if (empty($configured['ok'])) { return $configured; }
    $account = pma_database_account($database);
    if (empty($account['ok'])) { return $account; }
    $state = pma_get();
    $nonce = bin2hex(random_bytes(12));
    $expires = time() + 60;
    $payload = pma_b64url_encode((string) json_encode([
        'db' => $database,
        'uid' => (int) ($_SESSION['uid'] ?? 0),
        'exp' => $expires,
        'nonce' => $nonce,
    ], JSON_UNESCAPED_SLASHES));
    $sig = pma_b64url_encode(hash_hmac('sha256', $payload, (string) $state['token_secret'], true));
    $launches = array_filter((array) ($state['launches'] ?? []), fn($launch) => (int) ($launch['exp'] ?? 0) >= time());
    $launches[$nonce] = ['db' => $database, 'uid' => (int) ($_SESSION['uid'] ?? 0), 'exp' => $expires];
    $state['launches'] = $launches;
    if (!pma_save($state)) { return ['ok' => false, 'error' => 'Could not save the phpMyAdmin launch token.']; }
    return ['ok' => true, 'url' => url('pma-signon', ['token' => $payload . '.' . $sig])];
}

/** Validate the signed launch token, create the signon session, and redirect. */
function pma_accept_signon(string $token): void
{
    $state = pma_get();
    $parts = explode('.', $token, 2);
    if (!$state || count($parts) !== 2 || empty($state['token_secret'])) {
        http_response_code(403); exit('Invalid phpMyAdmin launch token.');
    }
    [$payload, $sig] = $parts;
    $expected = pma_b64url_encode(hash_hmac('sha256', $payload, (string) $state['token_secret'], true));
    $decoded = pma_b64url_decode($payload);
    $claims = $decoded === null ? null : json_decode($decoded, true);
    if (!hash_equals($expected, $sig) || !is_array($claims)
        || (int) ($claims['uid'] ?? 0) !== (int) ($_SESSION['uid'] ?? 0)
        || (int) ($claims['exp'] ?? 0) < time()
        || (int) ($claims['exp'] ?? 0) > time() + 120) {
        http_response_code(403); exit('Expired or invalid phpMyAdmin launch token.');
    }
    $database = (string) ($claims['db'] ?? '');
    $nonce = (string) ($claims['nonce'] ?? '');
    $launch = $state['launches'][$nonce] ?? null;
    if (!is_array($launch) || ($launch['db'] ?? '') !== $database
        || (int) ($launch['uid'] ?? 0) !== (int) ($_SESSION['uid'] ?? 0)
        || (int) ($launch['exp'] ?? 0) < time()) {
        http_response_code(403); exit('The phpMyAdmin launch token was already used or expired.');
    }
    unset($state['launches'][$nonce]);
    if (!pma_save($state)) { http_response_code(500); exit('Could not consume the phpMyAdmin launch token.'); }
    $key = hash('sha256', $database);
    $account = $state['accounts'][$key] ?? null;
    if (!is_array($account) || ($account['database'] ?? '') !== $database) {
        http_response_code(403); exit('phpMyAdmin account is unavailable.');
    }
    audit('pma.launch', $database);
    // PHP keeps the current session ID in process memory after closing the
    // panel session. Clear it before changing names so the short-lived
    // phpMyAdmin signon session can never replace/delete nebula_sess.
    session_write_close();
    session_id('');
    session_name('NebulaPmaSignon');
    global $https;
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => (bool) $https, 'samesite' => 'Lax']);
    session_start();
    $_SESSION['PMA_single_signon_user'] = (string) $account['user'];
    $_SESSION['PMA_single_signon_password'] = (string) $account['password'];
    $_SESSION['PMA_single_signon_host'] = 'localhost';
    session_write_close();
    $target = rtrim((string) ($state['url'] ?? ''), '/') . '/index.php?route=/database/structure&db=' . rawurlencode($database);
    header('Location: ' . $target);
    exit;
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
    if (!pma_save([
        'dir' => $target,
        'url' => $url,
        'installed_at' => date('c'),
        'token_secret' => bin2hex(random_bytes(32)),
        'accounts' => [],
    ])) {
        helper_cmd('pma-remove ' . escapeshellarg($target));
        return ['ok' => false, 'error' => 'Could not save phpMyAdmin installation state.'];
    }
    $configured = pma_ensure_signon();
    if (empty($configured['ok'])) { return $configured; }
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
    require_once APP_ROOT . '/lib/mod_db.php';
    foreach ((array) ($s['accounts'] ?? []) as $account) {
        $user = (string) ($account['user'] ?? '');
        if (preg_match('/^nebula_pma_[a-f0-9]{12}$/', $user)) {
            db_run("DROP USER IF EXISTS '$user'@'localhost';");
        }
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
