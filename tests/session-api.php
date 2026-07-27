<?php
/**
 * End-to-end regression for a normal browser-session API request.
 *
 * This uses a disposable copy because index.php intentionally derives APP_ROOT
 * from its own location and exits after emitting JSON.
 */

$source = dirname(__DIR__) . '/panel';
$root = sys_get_temp_dir() . '/nebula-session-api-' . bin2hex(random_bytes(6));
$app = $root . '/panel';

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};
register_shutdown_function(static function () use ($root, $removeTree): void {
    $removeTree($root);
});

$copyTree = static function (string $from, string $to) use (&$copyTree): void {
    if (!is_dir($to) && !mkdir($to, 0700, true) && !is_dir($to)) {
        throw new RuntimeException('Could not create API test directory.');
    }
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'data') { continue; }
        $src = $from . DIRECTORY_SEPARATOR . $entry;
        $dst = $to . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($src)) { $copyTree($src, $dst); }
        elseif (!copy($src, $dst)) { throw new RuntimeException('Could not copy API test fixture.'); }
    }
};
$copyTree($source, $app);

mkdir($app . '/data', 0700, true);
mkdir($root . '/sessions', 0700, true);
file_put_contents($app . '/data/panel-users.json', json_encode([
    'version' => 1,
    'users' => [[
        'id' => 1,
        'username' => 'session-api-admin',
        'hash' => password_hash('unused regression password', PASSWORD_DEFAULT),
        'role' => 'admin',
        'enabled' => true,
        'created' => date('c'),
        'session_version' => 1,
    ]],
], JSON_PRETTY_PRINT));

ini_set('session.save_path', $root . '/sessions');
session_name('nebula_sess');
session_id('nebula-session-api-' . bin2hex(random_bytes(6)));
session_start();
$_SESSION = [
    'uid' => 1,
    'username' => 'session-api-admin',
    'role' => 'admin',
    'session_version' => 1,
    'last_seen' => time(),
    'created_at' => time(),
    'rotated_at' => time(),
];
session_write_close();

$_GET = ['r' => 'api/metrics'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/test/index.php';
$_SERVER['REQUEST_URI'] = '/test/?r=api%2Fmetrics';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['HTTPS'] = 'on';

require $app . '/index.php';
