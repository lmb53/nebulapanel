<?php
/**
 * Databases module — MariaDB/MySQL administration through the typed helper.
 *
 * SECURITY: the panel never sends SQL. Every operation is a typed helper
 * action (`nebula-helper db-action <op> ...`) that re-validates identifiers
 * and builds the statement itself; passwords travel Base64-encoded and reach
 * mysql on stdin, so they never appear in the process list.
 */

const SYSTEM_DBS = ['mysql', 'information_schema', 'performance_schema', 'sys'];

/** Is the mysql client available? */
function db_available(): bool
{
    return has_cmd('mysql');
}

/**
 * State of the database *server*, which is what every query actually needs.
 *
 * The `mysql` client arrives as a dependency of unrelated packages (php-mysql
 * pulls in the client libraries, and mariadb-client is a common transitive
 * dep), so a client-only check reports the Databases page as fully working on
 * a server that has no mysqld at all. Every query then fails with the raw
 * "ERROR 2002 ... /var/run/mysqld/mysqld.sock" text, which says nothing about
 * the actual remedy.
 *
 * Returns state ∈ ready|stopped|absent|no-client, plus a message describing the
 * fix in the panel's own terms.
 */
function db_server_status(): array
{
    if (!db_available()) {
        return [
            'state'  => 'no-client',
            'unit'   => null,
            'ready'  => false,
            'message' => 'No MySQL/MariaDB client is installed. Install MariaDB from Install Apps, then reload this page.',
        ];
    }
    // mariadb and mysql ship mutually exclusive units; whichever is loaded wins.
    // A running unit is proof enough on its own. For "installed but stopped" we
    // additionally require a server binary, because service_status() cannot
    // tell a stopped unit from a missing one when systemctl is unavailable
    // (containers, non-systemd hosts) and would report absent servers stopped.
    $serverInstalled = has_cmd('mysqld') || has_cmd('mariadbd');
    $unit = null;
    $state = 'absent';
    foreach (['mariadb', 'mysql'] as $candidate) {
        $status = service_status($candidate);
        if ($status === 'active') {
            $unit = $candidate;
            $state = 'ready';
            break;
        }
        if ($unit === null && $serverInstalled && $status !== 'not-installed' && $status !== 'unknown') {
            $unit = $candidate;
            $state = 'stopped';
        }
    }
    // No systemd answer (containers, other init systems): a listening server
    // socket is proof enough that the server runs.
    if ($state !== 'ready') {
        foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock'] as $socket) {
            if (@filetype($socket) === 'socket' && ($probe = @stream_socket_client('unix://' . $socket, $errno, $errstr, 1))) {
                fclose($probe);
                return ['state' => 'ready', 'unit' => $unit ?? 'mariadb', 'ready' => true, 'message' => ''];
            }
        }
    }
    if ($unit === null && $serverInstalled) {
        // Present on disk but systemd knows nothing about it.
        $unit = 'mariadb';
        $state = 'stopped';
    }
    if ($unit === null) {
        return [
            'state'  => 'absent',
            'unit'   => null,
            'ready'  => false,
            'message' => 'The MySQL/MariaDB client is present but no database server is installed on this machine. '
                       . 'Install MariaDB from Install Apps, then reload this page.',
        ];
    }
    if ($state !== 'ready') {
        return [
            'state'  => 'stopped',
            'unit'   => $unit,
            'ready'  => false,
            'message' => 'The ' . $unit . ' service is installed but not running, so the panel cannot reach the '
                       . 'database socket. Start it from Services, then reload this page.',
        ];
    }
    return ['state' => 'ready', 'unit' => $unit, 'ready' => true, 'message' => ''];
}

/**
 * Turn a raw mysql client failure into something a panel user can act on.
 * Connection errors are reported by the *client*, so they surface as ordinary
 * command output rather than a helper/sudo problem.
 */
function db_error(string $out, int $code): string
{
    $raw = trim($out);
    $connectionFailure = stripos($raw, 'ERROR 2002') !== false
        || stripos($raw, 'ERROR 2003') !== false
        || stripos($raw, "Can't connect to local") !== false
        || stripos($raw, "Can't connect to MySQL server") !== false;
    if ($connectionFailure) {
        $status = db_server_status();
        // Fall back to the generic phrasing only when the unit looks healthy —
        // that combination means the socket path or bind address is wrong.
        return $status['ready']
            ? 'Could not connect to the database server even though ' . ($status['unit'] ?? 'it')
              . ' is running. Check its socket path and bind-address in the server configuration, then retry.'
            : $status['message'];
    }
    if (stripos($raw, 'ERROR 1045') !== false || stripos($raw, 'Access denied') !== false) {
        return 'The database server refused the panel\'s root socket login. '
             . 'Confirm unix_socket authentication is enabled for root, then retry.';
    }
    return sudo_error($out, $code);
}

/** Strict identifier whitelist (db / user names). */
function db_ident_ok(string $s): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_]+$/', $s) && strlen($s) <= 64;
}

/** Host string whitelist (e.g. localhost, %, 10.0.%.%). */
function db_host_ok(string $s): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_.%-]+$/', $s) && strlen($s) <= 255;
}

/** Database accounts the panel must never create, change or drop. */
const DB_RESERVED_USERS = ['root', 'mysql', 'mariadb', 'mariadb.sys', 'debian-sys-maint', 'admin',
    'mysql.sys', 'mysql.session', 'mysql.infoschema'];

function db_user_reserved(string $user): bool
{
    return in_array(strtolower($user), DB_RESERVED_USERS, true);
}

/** Run one typed database action through the helper. Returns [code, out, err]. */
function db_action(string $op, array $args = [], int $timeout = 120): array
{
    $line = 'db-action ' . escapeshellarg($op);
    foreach ($args as $arg) { $line .= ' ' . escapeshellarg((string) $arg); }
    return helper_cmd($line, $timeout);
}

/** List databases with total (data+index) size in bytes. */
function db_list(): array
{
    [$c, $o] = db_action('list');
    if ($c !== 0) {
        return ['ok' => false, 'error' => db_error($o, $c), 'databases' => []];
    }
    $databases = [];
    foreach (preg_split('/\r?\n/', trim($o)) as $line) {
        if ($line === '') {
            continue;
        }
        $cols = explode("\t", $line);
        $name = $cols[0] ?? '';
        if ($name === '') {
            continue;
        }
        $databases[] = [
            'name'   => $name,
            'size'   => (int) ($cols[1] ?? 0),
            'tables' => (int) ($cols[2] ?? 0),
            'collation' => (string) ($cols[3] ?? ''),
            'system' => in_array($name, SYSTEM_DBS, true),
        ];
    }
    return ['ok' => true, 'databases' => $databases];
}

/** Database engine/version string. */
function db_version(): string
{
    [$c, $o] = db_action('version');
    return $c === 0 ? trim((string) $o) : '';
}

/** Users with explicit schema privileges, keyed by database name. */
function db_schema_users(): array
{
    [$c, $o] = db_action('schema-users');
    if ($c !== 0) {
        return [];
    }
    $map = [];
    foreach (preg_split('/\r?\n/', trim($o)) as $line) {
        if ($line === '') { continue; }
        [$database, $grantee] = array_pad(explode("\t", $line, 3), 3, '');
        $map[$database][] = trim($grantee, "'");
    }
    return $map;
}

function db_links_file(): string
{
    return APP_ROOT . '/data/database-links.json';
}

function db_links(): array
{
    $links = @json_decode((string) @file_get_contents(db_links_file()), true);
    return is_array($links) ? $links : [];
}

/** Attach a database to a tracked website for navigation and ownership UI. */
function db_link_website(string $database, string $website): array
{
    if (!db_ident_ok($database) || in_array($database, SYSTEM_DBS, true)) {
        return ['ok' => false, 'error' => 'Invalid database name.'];
    }
    $exists = false;
    foreach (db_list()['databases'] ?? [] as $db) {
        if (($db['name'] ?? '') === $database) { $exists = true; break; }
    }
    if (!$exists) {
        return ['ok' => false, 'error' => 'Database not found.'];
    }
    if ($website !== '') {
        require_once APP_ROOT . '/lib/mod_sites.php';
        $websiteExists = false;
        foreach (sites_list() as $site) {
            if (($site['domain'] ?? '') === $website) { $websiteExists = true; break; }
        }
        if (!$websiteExists) {
            return ['ok' => false, 'error' => 'Website not found.'];
        }
    }
    $links = db_links();
    if ($website === '') { unset($links[$database]); } else { $links[$database] = $website; }
    if (!write_json_file(db_links_file(), $links)) {
        return ['ok' => false, 'error' => 'Could not save the website link.'];
    }
    audit('db.link', $database . ' -> ' . ($website ?: 'none'));
    return ['ok' => true];
}

/** Create a database and, optionally, a user/grant and website link. */
function db_create_bundle(string $name, string $user, string $host, string $password, string $website): array
{
    if ($website === '') { return ['ok' => false, 'error' => 'A website owner is required.']; }
    // Validate the user up front so a bad request never creates (and then has
    // to drop) the database.
    if ($user !== '') {
        if (!db_ident_ok($user)) { return ['ok' => false, 'error' => 'Invalid user name.']; }
        if (!db_host_ok($host ?: 'localhost')) { return ['ok' => false, 'error' => 'Invalid host.']; }
        if (($passwordError = db_password_error($password)) !== null) { return ['ok' => false, 'error' => $passwordError]; }
    }
    $created = db_create($name);
    if (empty($created['ok'])) { return $created; }
    if ($user !== '') {
        $createdUser = db_create_user($user, $host ?: 'localhost', $password, $name);
        if (empty($createdUser['ok'])) {
            db_drop($name);
            return $createdUser;
        }
    }
    if ($website !== '') {
        $linked = db_link_website($name, $website);
        if (empty($linked['ok'])) {
            if ($user !== '') { db_drop_user($user, $host ?: 'localhost'); }
            db_drop($name);
            return $linked;
        }
    }
    return ['ok' => true];
}

/** List database user accounts. */
function db_users(): array
{
    [$c, $o] = db_action('users');
    if ($c !== 0) {
        return ['ok' => false, 'error' => db_error($o, $c), 'users' => []];
    }
    $users = [];
    foreach (preg_split('/\r?\n/', trim($o)) as $line) {
        if ($line === '') {
            continue;
        }
        $cols = explode("\t", $line);
        $users[] = ['user' => $cols[0] ?? '', 'host' => $cols[1] ?? ''];
    }
    return ['ok' => true, 'users' => $users];
}

/** Create a database. */
function db_create(string $name): array
{
    if (!db_ident_ok($name)) {
        return ['ok' => false, 'error' => 'Invalid database name.'];
    }
    if (in_array($name, SYSTEM_DBS, true)) {
        return ['ok' => false, 'error' => 'Refusing to create a system database.'];
    }
    [$c, $o] = db_action('create', [$name]);
    if ($c !== 0) {
        return ['ok' => false, 'error' => db_error($o, $c)];
    }
    audit('db.create', $name);
    return ['ok' => true];
}

/** Drop a database. */
function db_drop(string $name): array
{
    if (!db_ident_ok($name)) {
        return ['ok' => false, 'error' => 'Invalid database name.'];
    }
    if (in_array($name, SYSTEM_DBS, true)) {
        return ['ok' => false, 'error' => 'Refusing to drop a system database.'];
    }
    [$c, $o] = db_action('drop', [$name]);
    if ($c !== 0) {
        return ['ok' => false, 'error' => db_error($o, $c)];
    }
    audit('db.drop', $name);
    $links = db_links();
    if (isset($links[$name])) {
        unset($links[$name]);
        write_json_file(db_links_file(), $links);
    }
    return ['ok' => true];
}

/**
 * An empty password creates an account anyone can use, from any host the
 * grant names. The UI already insists on one; enforce it for API callers too.
 */
function db_password_error(string $password): ?string
{
    if (strlen($password) < 8 || strlen($password) > 256 || !preg_match('/^[\x20-\x7E]+$/', $password)
        || str_contains($password, '\\')) {
        return 'Database user passwords must be 8–256 printable characters without a backslash.';
    }
    return null;
}

/** Create a user, optionally granting all privileges on one database. */
function db_create_user(string $user, string $host, string $password, string $grantDb = ''): array
{
    if (!db_ident_ok($user)) {
        return ['ok' => false, 'error' => 'Invalid user name.'];
    }
    if (!db_host_ok($host)) {
        return ['ok' => false, 'error' => 'Invalid host.'];
    }
    if ($grantDb !== '' && !db_ident_ok($grantDb)) {
        return ['ok' => false, 'error' => 'Invalid database name.'];
    }
    if (($passwordError = db_password_error($password)) !== null) {
        return ['ok' => false, 'error' => $passwordError];
    }
    if (db_user_reserved($user)) {
        return ['ok' => false, 'error' => 'That is a reserved system account name.'];
    }
    [$c, $o] = db_action('user-create', array_merge([$user, $host, base64_encode($password)], $grantDb !== '' ? [$grantDb] : []));
    if ($c !== 0) {
        return ['ok' => false, 'error' => db_error($o, $c)];
    }
    audit('db.user.create', $user . '@' . $host);
    return ['ok' => true];
}

/** Drop a user account. */
function db_drop_user(string $user, string $host): array
{
    if (!db_ident_ok($user)) {
        return ['ok' => false, 'error' => 'Invalid user name.'];
    }
    if (!db_host_ok($host)) {
        return ['ok' => false, 'error' => 'Invalid host.'];
    }
    if (db_user_reserved($user)) {
        return ['ok' => false, 'error' => 'System database accounts cannot be dropped from the panel.'];
    }
    [$c, $o] = db_action('user-drop', [$user, $host]);
    if ($c !== 0) {
        return ['ok' => false, 'error' => db_error($o, $c)];
    }
    audit('db.user.drop', $user . '@' . $host);
    return ['ok' => true];
}

/** Change a database user's password. */
function db_user_password(string $user, string $host, string $password): array
{
    if (!db_ident_ok($user) || db_user_reserved($user)) { return ['ok' => false, 'error' => 'Invalid user name.']; }
    if (!db_host_ok($host)) { return ['ok' => false, 'error' => 'Invalid host.']; }
    if (($passwordError = db_password_error($password)) !== null) { return ['ok' => false, 'error' => $passwordError]; }
    [$c, $o] = db_action('user-password', [$user, $host, base64_encode($password)]);
    if ($c !== 0) { return ['ok' => false, 'error' => db_error($o, $c)]; }
    audit('db.user.password', $user . '@' . $host);
    return ['ok' => true];
}

/** Set a user's access to one database: all, read (SELECT/SHOW VIEW) or none. */
function db_grant(string $user, string $host, string $database, string $level): array
{
    if (!db_ident_ok($user) || db_user_reserved($user)) { return ['ok' => false, 'error' => 'Invalid user name.']; }
    if (!db_host_ok($host)) { return ['ok' => false, 'error' => 'Invalid host.']; }
    if (!db_ident_ok($database) || in_array($database, SYSTEM_DBS, true)) { return ['ok' => false, 'error' => 'Invalid database name.']; }
    if (!in_array($level, ['all', 'read', 'none'], true)) { return ['ok' => false, 'error' => 'Choose full, read-only or no access.']; }
    [$c, $o] = db_action('grant', [$user, $host, $database, $level]);
    if ($c !== 0) { return ['ok' => false, 'error' => db_error($o, $c)]; }
    audit('db.grant', $user . '@' . $host . ' ' . $database . ' ' . $level);
    return ['ok' => true];
}

function db_exports_dir(): string
{
    return DATA_DIR . '/db-exports';
}

/** Dump a database (gzip) into data/db-exports for download. */
function db_export(string $database, ?callable $onOutput = null): array
{
    if (!db_ident_ok($database) || in_array($database, SYSTEM_DBS, true)) { return ['ok' => false, 'error' => 'Invalid database name.']; }
    $name = $database . '-' . date('Ymd-His') . '.sql.gz';
    if ($onOutput) { $onOutput("==> Exporting $database\n", 'stdout'); }
    [$c, $o] = db_action('export', [$database, $name], 3600);
    if ($c !== 0) { return ['ok' => false, 'error' => db_error($o, $c)]; }
    audit('db.export', $database);
    return ['ok' => true, 'file' => $name, 'url' => url('db-export-download', ['file' => $name])];
}

/** Resolve an export file name to its path (or null). */
function db_export_resolve(string $file): ?string
{
    if (!preg_match('/^[A-Za-z0-9_]{1,64}-[0-9]{8}-[0-9]{6}\.sql\.gz$/', $file)) { return null; }
    $path = db_exports_dir() . '/' . $file;
    return is_file($path) && !is_link($path) ? $path : null;
}

/** Remove exports older than a day (they exist only to be downloaded). */
function db_exports_prune(): int
{
    $removed = 0;
    foreach (glob(db_exports_dir() . '/*.sql.gz') ?: [] as $file) {
        if (time() - (int) @filemtime($file) > 86400 && @unlink($file)) { $removed++; }
    }
    return $removed;
}

/** Import a staged dump (uploaded through the chunked uploader) into a database. */
function db_import(string $database, string $stagedId, ?callable $onOutput = null): array
{
    if (!db_ident_ok($database) || in_array($database, SYSTEM_DBS, true)) { return ['ok' => false, 'error' => 'Invalid database name.']; }
    if (!preg_match('/^[a-f0-9]{32}$/', $stagedId) || !is_file(DATA_DIR . '/file-staging/' . $stagedId)) {
        return ['ok' => false, 'error' => 'The uploaded dump was not found. Upload it again.'];
    }
    if ($onOutput) { $onOutput("==> Importing into $database (as an account limited to this database)\n", 'stdout'); }
    [$c, $o] = db_action('import', [$database, realpath(DATA_DIR . '/file-staging/' . $stagedId)], 3600);
    @unlink(DATA_DIR . '/file-staging/' . $stagedId);
    if ($c !== 0) { return ['ok' => false, 'error' => db_error($o, $c)]; }
    audit('db.import', $database);
    return ['ok' => true];
}
