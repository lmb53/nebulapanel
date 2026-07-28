<?php
/**
 * Databases module — MariaDB/MySQL administration through the typed helper.
 *
 * SECURITY: identifiers (db / user names) are validated against a strict
 * whitelist regex before ever touching SQL; host strings likewise. String
 * values (passwords) are SQL-escaped via db_sql_str(). SQL is Base64-wrapped
 * for transport to the typed helper, decoded to a private temporary file, and
 * supplied to mysql on stdin so statements and passwords never appear in the
 * process list.
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
              . ' is running. Check its socket path and bind-address, then retry. Server said: ' . $raw
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

/** Quote + escape a SQL string literal. */
function db_sql_str(string $v): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
}

/** Run SQL as root via socket auth. Returns [code, out, err]. */
function db_run(string $sql): array
{
    return helper_cmd('db-query ' . escapeshellarg(base64_encode($sql)), 120);
}

/** List databases with total (data+index) size in bytes. */
function db_list(): array
{
    $sql = "SELECT s.schema_name, COALESCE(SUM(t.data_length+t.index_length),0), "
        . "COUNT(t.table_name), s.default_collation_name "
        . "FROM information_schema.schemata s "
        . "LEFT JOIN information_schema.tables t ON t.table_schema=s.schema_name "
        . "GROUP BY s.schema_name,s.default_collation_name;";
    [$c, $o] = db_run($sql);
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
    [$c, $o] = db_run('SELECT VERSION();');
    return $c === 0 ? trim((string) $o) : '';
}

/** Users with explicit schema privileges, keyed by database name. */
function db_schema_users(): array
{
    [$c, $o] = db_run("SELECT TABLE_SCHEMA,GRANTEE FROM information_schema.SCHEMA_PRIVILEGES GROUP BY TABLE_SCHEMA,GRANTEE ORDER BY TABLE_SCHEMA,GRANTEE;");
    if ($c !== 0) {
        return [];
    }
    $map = [];
    foreach (preg_split('/\r?\n/', trim($o)) as $line) {
        if ($line === '') { continue; }
        [$database, $grantee] = array_pad(explode("\t", $line, 2), 2, '');
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
    [$c, $o] = db_run("SELECT User,Host FROM mysql.user ORDER BY User;");
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
    [$c, $o] = db_run("CREATE DATABASE `$name`");
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
    [$c, $o] = db_run("DROP DATABASE `$name`");
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
    // user/host are regex-validated, so safe to inline inside the quotes.
    $sql = "CREATE USER '" . $user . "'@'" . $host . "' IDENTIFIED BY " . db_sql_str($password) . ";";
    if ($grantDb !== '') {
        $sql .= " GRANT ALL PRIVILEGES ON `" . $grantDb . "`.* TO '" . $user . "'@'" . $host . "';";
    }
    $sql .= " FLUSH PRIVILEGES;";
    [$c, $o] = db_run($sql);
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
    [$c, $o] = db_run("DROP USER '" . $user . "'@'" . $host . "';");
    if ($c !== 0) {
        return ['ok' => false, 'error' => db_error($o, $c)];
    }
    audit('db.user.drop', $user . '@' . $host);
    return ['ok' => true];
}
