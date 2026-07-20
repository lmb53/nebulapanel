<?php
/**
 * Databases module — MariaDB/MySQL admin via root socket auth (`sudo mysql`).
 *
 * SECURITY: identifiers (db / user names) are validated against a strict
 * whitelist regex before ever touching SQL; host strings likewise. String
 * values (passwords) are SQL-escaped via db_sql_str(). The entire SQL string
 * is passed through escapeshellarg() before reaching the shell.
 */

const SYSTEM_DBS = ['mysql', 'information_schema', 'performance_schema', 'sys'];

/** Is the mysql client available? */
function db_available(): bool
{
    return has_cmd('mysql');
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
    return sudo_cmd('mysql -N -B -e ' . escapeshellarg($sql));
}

/** List databases with total (data+index) size in bytes. */
function db_list(): array
{
    $sql = "SELECT s.schema_name, COALESCE(SUM(t.data_length+t.index_length),0) "
        . "FROM information_schema.schemata s "
        . "LEFT JOIN information_schema.tables t ON t.table_schema=s.schema_name "
        . "GROUP BY s.schema_name;";
    [$c, $o] = db_run($sql);
    if ($c !== 0) {
        return ['ok' => false, 'error' => sudo_error($o, $c), 'databases' => []];
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
            'system' => in_array($name, SYSTEM_DBS, true),
        ];
    }
    return ['ok' => true, 'databases' => $databases];
}

/** List database user accounts. */
function db_users(): array
{
    [$c, $o] = db_run("SELECT User,Host FROM mysql.user ORDER BY User;");
    if ($c !== 0) {
        return ['ok' => false, 'error' => sudo_error($o, $c), 'users' => []];
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
        return ['ok' => false, 'error' => sudo_error($o, $c)];
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
        return ['ok' => false, 'error' => sudo_error($o, $c)];
    }
    audit('db.drop', $name);
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
        return ['ok' => false, 'error' => sudo_error($o, $c)];
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
        return ['ok' => false, 'error' => sudo_error($o, $c)];
    }
    audit('db.user.drop', $user . '@' . $host);
    return ['ok' => true];
}
