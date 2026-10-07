<?php
/**
 * End-to-end session lifecycle regressions, driven through the real front
 * controller. Each request runs in its own PHP process because index.php
 * exits after emitting its response. Run: php tests/session-lifecycle.php
 */

$source = dirname(__DIR__) . '/panel';
$root = sys_get_temp_dir() . '/nebula-session-life-' . bin2hex(random_bytes(6));
$app = $root . '/panel';
$sessions = $root . '/sessions';

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path) || is_link($path)) {
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
        throw new RuntimeException('Could not create test directory.');
    }
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'data') { continue; }
        $src = $from . DIRECTORY_SEPARATOR . $entry;
        $dst = $to . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($src)) { $copyTree($src, $dst); }
        elseif (!copy($src, $dst)) { throw new RuntimeException('Could not copy test fixture.'); }
    }
};
$copyTree($source, $app);
mkdir($app . '/data', 0700, true);
mkdir($sessions, 0700, true);
file_put_contents($app . '/data/panel-users.json', json_encode([
    'version' => 1,
    'users' => [[
        'id' => 1, 'username' => 'lifecycle-admin',
        'hash' => password_hash('unused lifecycle password', PASSWORD_DEFAULT),
        'role' => 'admin', 'enabled' => true, 'created' => date('c'), 'session_version' => 1,
    ]],
]));

// One request = one process. The runner pins the session ID (as a browser
// cookie would) and prints the JSON body the panel emits.
$runner = $root . '/runner.php';
file_put_contents($runner, <<<'PHP'
<?php
[, $app, $sessions, $sid, $route] = $argv;
ini_set('session.save_path', $sessions);
session_name('nebula_sess');
if ($sid !== '-') { session_id($sid); }
$_GET = ['r' => $route];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/test/index.php';
$_SERVER['REQUEST_URI'] = '/test/?r=' . rawurlencode($route);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
require $app . '/index.php';
PHP);

$request = static function (string $sid, string $route = 'api/metrics') use ($runner, $app, $sessions): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($app) . ' '
        . escapeshellarg($sessions) . ' ' . escapeshellarg($sid) . ' ' . escapeshellarg($route) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $json = json_decode($out, true);
    return is_array($json) ? $json : ['_raw' => $out];
};

// Write session files in PHP's default "php" serialize_handler format. The
// session API itself is unusable here once this script has printed output.
$writeSession = static function (string $sid, array $data) use ($sessions): void {
    $encoded = '';
    foreach ($data as $key => $value) { $encoded .= $key . '|' . serialize($value); }
    file_put_contents($sessions . '/sess_' . $sid, $encoded);
};
$readSession = static function (string $sid) use ($sessions): string {
    return (string) @file_get_contents($sessions . '/sess_' . $sid);
};
$sessionIds = static function () use ($sessions): array {
    $ids = [];
    foreach (glob($sessions . '/sess_*') ?: [] as $file) { $ids[] = substr(basename($file), 5); }
    sort($ids);
    return $ids;
};

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok' : 'not ok') . " - $message\n";
    if (!$ok) { $failures++; }
};

$identity = ['uid' => 1, 'username' => 'lifecycle-admin', 'role' => 'admin', 'session_version' => 1];

// 1. An API call without a session gets JSON, not an HTML login redirect.
$anon = $request('-');
$check(($anon['ok'] ?? null) === false && ($anon['code'] ?? '') === 'unauthorized'
    && stripos((string) ($anon['error'] ?? ''), 'session') !== false,
    'an unauthenticated API request receives a JSON 401');

// 2. Rotation keeps the old ID usable for in-flight requests.
$old = 'lifecycle' . bin2hex(random_bytes(8));
$writeSession($old, $identity + ['last_seen' => time(), 'created_at' => time() - 2000, 'rotated_at' => time() - 2000]);
$before = $sessionIds();
$rotated = $request($old);
$check(($rotated['ok'] ?? null) === true, 'the request that rotates the session ID succeeds');
$new = array_values(array_diff($sessionIds(), $before));
$check(count($new) === 1 && strpos($readSession($new[0]), 'obsolete_at') === false
    && strpos($readSession($new[0]), 'uid|i:1') !== false,
    'rotation issues a new, fully authenticated session');
$check(strpos($readSession($old), 'obsolete_at') !== false,
    'the rotated-away session is kept and marked obsolete instead of deleted');
$concurrent = $request($old);
$check(($concurrent['ok'] ?? null) === true,
    'a concurrent request still carrying the old cookie is not logged out');
$check(count($sessionIds()) === count($before) + 1,
    'a request on an obsolete session does not rotate again');

// 3. After the grace window the obsolete ID stops working.
$stale = 'lifecycle' . bin2hex(random_bytes(8));
$writeSession($stale, $identity + ['last_seen' => time(), 'created_at' => time(), 'rotated_at' => time(), 'obsolete_at' => time() - 3600]);
$late = $request($stale);
$check(($late['code'] ?? '') === 'unauthorized', 'an obsolete session ID is rejected after its grace window');

// 4. Idle timeout still applies and answers API callers with JSON.
$idle = 'lifecycle' . bin2hex(random_bytes(8));
$writeSession($idle, $identity + ['last_seen' => time() - 100000, 'created_at' => time(), 'rotated_at' => time()]);
$check(($request($idle)['code'] ?? '') === 'unauthorized', 'an idle-expired session receives a JSON 401');

if ($failures) {
    fwrite(STDERR, "$failures session lifecycle regression(s) failed.\n");
    exit(1);
}
echo "Session lifecycle regressions passed.\n";
