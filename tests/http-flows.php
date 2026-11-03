<?php
/**
 * End-to-end HTTP regressions for browser flows. Runs a disposable copy of the
 * panel under PHP's built-in web server and drives it like the browser does:
 * cookie session, CSRF header, JSON bodies. Run: php tests/http-flows.php
 */

$source = dirname(__DIR__) . '/panel';
$root = sys_get_temp_dir() . '/nebula-http-flows-' . bin2hex(random_bytes(6));
$app = $root . '/panel';
$fmRoot = $root . '/sites';
$server = null;

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
register_shutdown_function(static function () use ($root, $removeTree, &$server): void {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
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
mkdir($fmRoot . '/site', 0755, true);
$password = 'Correct horse battery 1';
file_put_contents($app . '/data/panel-users.json', json_encode([
    'version' => 1,
    'users' => [[
        'id' => 1, 'username' => 'flow-admin',
        'hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'admin', 'enabled' => true, 'created' => date('c'), 'session_version' => 1,
    ]],
]));

// Pick a free loopback port and start the server.
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = array_merge(getenv(), ['NEBULA_FM_ROOT' => $fmRoot, 'NEBULA_SITES_ROOT' => $fmRoot, 'NEBULA_FORCE_HTTPS' => '0']);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $app], [
    0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
], $pipes, $app, $env);
$base = 'http://127.0.0.1:' . $port . '/';
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) { break; }
    usleep(100000);
}

/** A tiny browser: one cookie jar, follows nothing, returns [status, body]. */
$browser = static function () use ($base): object {
    return new class($base) {
        public array $cookies = [];
        public string $csrf = '';
        public function __construct(private string $base) {}
        public function request(string $method, string $route, ?string $body = null, array $headers = []): array
        {
            $cookie = implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
            $lines = array_merge($headers, $cookie !== '' ? ['Cookie: ' . $cookie] : []);
            $context = stream_context_create(['http' => [
                'method' => $method, 'header' => implode("\r\n", $lines), 'content' => $body ?? '',
                'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20,
            ]]);
            $out = (string) @file_get_contents($this->base . '?r=' . $route, false, $context);
            $status = 0;
            foreach ($http_response_header ?? [] as $header) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) { $status = (int) $m[1]; }
                if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $m)) {
                    if ($m[2] === '' || $m[2] === 'deleted') { unset($this->cookies[$m[1]]); }
                    else { $this->cookies[$m[1]] = $m[2]; }
                }
            }
            return [$status, $out];
        }
        public function login(string $user, string $password): bool
        {
            [, $html] = $this->request('GET', 'login');
            preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
            [$status] = $this->request('POST', 'login', http_build_query(['_csrf' => $m[1] ?? '', 'username' => $user, 'password' => $password]),
                ['Content-Type: application/x-www-form-urlencoded']);
            if ($status !== 302) { return false; }
            return $this->refreshCsrf();
        }
        public function refreshCsrf(): bool
        {
            [$status, $html] = $this->request('GET', 'settings');
            if ($status !== 200 || !preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m)) { return false; }
            $this->csrf = $m[1];
            return true;
        }
        public function api(string $endpoint, ?array $json = null): array
        {
            $headers = ['Accept: application/json'];
            if ($json !== null) { $headers[] = 'Content-Type: application/json'; $headers[] = 'X-CSRF-Token: ' . $this->csrf; }
            [$status, $out] = $this->request($json === null ? 'GET' : 'POST', 'api/' . $endpoint, $json === null ? null : json_encode($json), $headers);
            $data = json_decode($out, true);
            return ['status' => $status] + (is_array($data) ? $data : ['_raw' => $out]);
        }
    };
};

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok' : 'not ok') . " - $message\n";
    if (!$ok) { $failures++; }
};

$a = $browser();
$b = $browser();
$check($a->login('flow-admin', $password) && $b->login('flow-admin', $password), 'two browsers sign in');

// 1. Changing your own password keeps this session and ends the others.
$newPassword = 'Correct horse battery 2';
$res = $a->api('account', ['action' => 'password', 'current' => $password, 'new' => $newPassword]);
$check(($res['ok'] ?? false) === true, 'own password change succeeds');
$check(($a->api('metrics')['ok'] ?? false) === true, 'the session that changed the password stays signed in');
$check(($b->api('metrics')['status'] ?? 0) === 401, 'other sessions of the account are signed out');

// 2. Permissions accept the "0755" form the UI shows, but never special bits.
file_put_contents($fmRoot . '/site/a.txt', 'a');
$res = $a->api('file-chmod', ['path' => 'site/a.txt', 'mode' => '0600']);
$check(($res['ok'] ?? false) === true && (fileperms($fmRoot . '/site/a.txt') & 0777) === 0600, 'chmod accepts a four-digit 0-prefixed mode');
$res = $a->api('file-chmod', ['path' => 'site/a.txt', 'mode' => '4755']);
$check(($res['ok'] ?? true) === false, 'chmod still rejects setuid modes');

// 3. Restoring over a name clash keeps dotfiles hidden and never overwrites.
file_put_contents($fmRoot . '/site/.env', 'old');
$res = $a->api('file-delete', ['path' => 'site/.env', 'permanent' => false]);
$check(($res['ok'] ?? false) === true, 'a dotfile moves to the trash');
file_put_contents($fmRoot . '/site/.env', 'new');
$trash = $a->api('file-trash');
$id = $trash['items'][0]['id'] ?? '';
$res = $a->api('file-trash', ['action' => 'restore', 'id' => $id]);
$check(($res['ok'] ?? false) === true && ($res['path'] ?? '') === 'site/.env (restored)', 'a clashing dotfile restores as ".env (restored)"');
$check((string) @file_get_contents($fmRoot . '/site/.env') === 'new', 'the existing file is left untouched');

// 4. Invalid names explain the rule instead of a bare "Invalid name".
$res = $a->api('file-mkdir', ['dir' => 'site', 'name' => 'bad*name']);
$check(($res['ok'] ?? true) === false && str_contains((string) ($res['error'] ?? ''), 'letters, numbers'), 'an invalid name explains the allowed characters');

if ($failures) {
    fwrite(STDERR, "$failures HTTP flow regression(s) failed.\n");
    exit(1);
}
echo "HTTP flow regressions passed.\n";
