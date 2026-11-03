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

// 5. Active sessions: list them, end one remotely, end all others.
$c = $browser();
$d = $browser();
$check($c->login('flow-admin', $newPassword) && $d->login('flow-admin', $newPassword), 'two more browsers sign in');
$sessions = $a->api('account')['sessions'] ?? [];
$current = array_values(array_filter($sessions, fn($s) => !empty($s['current'])));
$others = array_values(array_filter($sessions, fn($s) => empty($s['current'])));
$check(count($current) === 1 && count($others) === 2, 'the account page lists this session and the two others');
$res = $a->api('account', ['action' => 'revoke-session', 'id' => $others[0]['id'] ?? '']);
$check(($res['ok'] ?? false) === true && count($res['sessions'] ?? []) === 2, 'one other session can be signed out');
$statuses = [$c->api('metrics')['status'] ?? 0, $d->api('metrics')['status'] ?? 0];
sort($statuses);
$check($statuses === [200, 401], 'exactly the revoked browser is signed out on its next request');
$res = $a->api('account', ['action' => 'revoke-other-sessions']);
$check(($res['ok'] ?? false) === true && ($res['ended'] ?? 0) === 1, 'sign out other sessions ends the remaining one');
$check(($c->api('metrics')['status'] ?? 0) === 401 && ($d->api('metrics')['status'] ?? 0) === 401, 'no other session survives');
$check(($a->api('metrics')['ok'] ?? false) === true, 'the current session is never signed out by its own revoke');

// 6. Two-factor sign-in.
require_once $app . '/lib/mod_mfa.php';
$setup = $a->api('account', ['action' => 'mfa-setup']);
$secret = str_replace(' ', '', (string) ($setup['secret'] ?? ''));
$check(($setup['ok'] ?? false) === true && strlen($secret) === 32 && str_contains((string) ($setup['qr_svg'] ?? ''), '<svg'), 'setup returns a secret and a QR code');
$res = $a->api('account', ['action' => 'mfa-confirm', 'code' => '000000']);
$check(($res['ok'] ?? true) === false, 'a wrong confirmation code is rejected');
$step = intdiv(time(), MFA_PERIOD);
$res = $a->api('account', ['action' => 'mfa-confirm', 'code' => mfa_code($secret, $step)]);
$recovery = $res['recovery_codes'] ?? [];
$check(($res['ok'] ?? false) === true && count($recovery) === 10, 'confirming turns 2FA on and returns ten recovery codes');
$check(($a->api('account')['mfa']['enabled'] ?? false) === true, 'the account reports 2FA as on');

$mfaLogin = static function ($b, string $code) use ($newPassword): array {
    [, $html] = $b->request('GET', 'login');
    preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
    [$status] = $b->request('POST', 'login', http_build_query(['_csrf' => $m[1] ?? '', 'username' => 'flow-admin', 'password' => $newPassword]), ['Content-Type: application/x-www-form-urlencoded']);
    $pendingStatus = $b->api('metrics')['status'] ?? 0;
    [, $html] = $b->request('GET', 'login-2fa');
    preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
    [$final] = $b->request('POST', 'login-2fa', http_build_query(['_csrf' => $m[1] ?? '', 'code' => $code]), ['Content-Type: application/x-www-form-urlencoded']);
    return [$status, $pendingStatus, $final, $b->api('metrics')['status'] ?? 0];
};
$e = $browser();
[$status, $pending, $final, $after] = $mfaLogin($e, '123456');
$check($status === 302 && $pending === 401, 'a correct password alone does not sign in when 2FA is on');
$check($final === 200 && $after === 401, 'a wrong code keeps the session signed out');
$f = $browser();
[, , $final, $after] = $mfaLogin($f, mfa_code($secret, $step + 1));
$check($final === 302 && $after === 200, 'the next authenticator code completes sign-in');
$g = $browser();
[, , $final, $after] = $mfaLogin($g, mfa_code($secret, $step + 1));
$check($after === 401, 'an authenticator code cannot be replayed');
$h = $browser();
[, , $final, $after] = $mfaLogin($h, strtoupper($recovery[0]));
$check($final === 302 && $after === 200, 'a recovery code signs in');
$i = $browser();
[, , , $after] = $mfaLogin($i, $recovery[0]);
$check($after === 401, 'a recovery code works only once');
$check(($a->api('account')['mfa']['recovery_left'] ?? 0) === 9, 'the used recovery code is removed');
$res = $a->api('account', ['action' => 'mfa-disable', 'password' => 'wrong password value', 'code' => $recovery[1]]);
$check(($res['ok'] ?? true) === false, 'turning 2FA off requires the password');
$res = $a->api('account', ['action' => 'mfa-disable', 'password' => $newPassword, 'code' => $recovery[1]]);
$check(($res['ok'] ?? false) === true && ($a->api('account')['mfa']['enabled'] ?? true) === false, 'password plus a code turns 2FA off');

// 7. Audit log search and export.
$res = $a->api('audit&action=login&limit=5');
$check(($res['ok'] ?? false) === true && ($res['total'] ?? 0) >= 3 && count($res['events'] ?? []) <= 5, 'the audit log filters by action group');
$check(!array_filter($res['events'] ?? [], fn($ev) => !str_starts_with((string) ($ev['action'] ?? ''), 'login')), 'filtered events all match the action');
$res = $a->api('audit&q=recovery_regenerated_does_not_exist');
$check(($res['total'] ?? -1) === 0, 'a text search with no match returns nothing');
$check(in_array('flow-admin', $res['actors'] ?? [], true) && in_array('mfa', $res['actions'] ?? [], true), 'the audit log offers actors and action groups as filters');
[$status, $csv] = $a->request('GET', 'api/audit&format=csv&action=mfa');
$check($status === 200 && str_starts_with($csv, 'time,actor,role,action') && str_contains($csv, 'mfa.enable'), 'the filtered audit log exports as CSV');

// 8. Backup schedule validation and next-run calculation.
$res = $a->api('backups', ['action' => 'schedule', 'enabled' => true, 'frequency' => 'daily', 'hour' => 25, 'keep' => 7]);
$check(($res['ok'] ?? true) === false, 'an invalid backup hour is rejected');
$res = $a->api('backups', ['action' => 'schedule', 'enabled' => true, 'frequency' => 'weekly', 'hour' => 3, 'weekday' => 1, 'keep' => 4, 'include_databases' => true, 'sites' => []]);
$next = (int) ($res['next_run'] ?? 0);
$check(($res['ok'] ?? false) === true && $next > time() && (int) date('w', $next) === 1 && (int) date('G', $next) === 3, 'a weekly schedule runs next on the chosen day and hour');
$list = $a->api('backups');
$check(($list['schedule']['keep'] ?? 0) === 4 && ($list['schedule']['enabled'] ?? false) === true, 'the schedule is saved');

// 9. Resource history is recorded by the metrics poll and served by range.
$res = $a->api('metrics-history&range=24h');
$check(($res['ok'] ?? false) === true && ($res['samples'] ?? 0) >= 1 && is_array($res['points'] ?? null), 'resource history has samples after a metrics poll');
$res = $a->api('metrics-history&range=bogus');
$check(($res['range'] ?? '') === '24h', 'an unknown history range falls back to 24 hours');

// 10. Alerts: validation and delivery to a generic JSON webhook.
$res = $a->api('settings', ['action' => 'alerts', 'enabled' => true, 'emails' => '', 'webhooks' => []]);
$check(($res['ok'] ?? true) === false, 'alerts cannot be turned on without a channel');
$res = $a->api('settings', ['action' => 'alerts', 'enabled' => true, 'emails' => 'not-an-email', 'webhooks' => []]);
$check(($res['ok'] ?? true) === false, 'an invalid alert email is rejected');
$res = $a->api('settings', ['action' => 'alerts', 'enabled' => true, 'emails' => '', 'webhooks' => [['type' => 'generic', 'url' => 'file:///etc/passwd']]]);
$check(($res['ok'] ?? true) === false, 'non-HTTP webhook URLs are rejected');
$hookDir = $root . '/hook';
mkdir($hookDir, 0700, true);
file_put_contents($hookDir . '/index.php', '<?php file_put_contents(__DIR__ . "/received.json", file_get_contents("php://input")); http_response_code(204);');
$probe = stream_socket_server('tcp://127.0.0.1:0');
$hookPort = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$hookServer = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $hookPort, '-t', $hookDir], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $hookPipes, $hookDir);
for ($n = 0; $n < 50 && !@fsockopen('127.0.0.1', $hookPort); $n++) { usleep(100000); }
$res = $a->api('settings', ['action' => 'alerts', 'enabled' => true, 'emails' => '', 'webhooks' => [['type' => 'generic', 'url' => 'http://127.0.0.1:' . $hookPort . '/']],
    'events' => ['resources' => true, 'services' => true, 'backups' => true, 'ssl' => true, 'panel' => true, 'updates' => false], 'remind_hours' => 24, 'notify_resolved' => true]);
$check(($res['ok'] ?? false) === true, 'alert settings with a webhook are saved');
$res = $a->api('settings', ['action' => 'alerts-test']);
$payload = json_decode((string) @file_get_contents($hookDir . '/received.json'), true);
$check(($res['ok'] ?? false) === true && str_contains((string) ($payload['subject'] ?? ''), 'Test alert'), 'a test alert reaches the webhook as JSON');
proc_terminate($hookServer);
proc_close($hookServer);

// 11. New pages render; a missing site is explained instead of erroring.
[$status, $html] = $a->request('GET', 'site&id=' . str_repeat('a', 32));
$check($status === 200 && str_contains($html, 'Website not found'), 'an unknown website shows a not-found state');
[$status, $html] = $a->request('GET', 'account');
$check($status === 200 && str_contains($html, 'Two-factor sign-in') && str_contains($html, 'Where you’re signed in'), 'the account page shows 2FA and sessions');
[$status, $html] = $a->request('GET', 'backups');
$check($status === 200 && str_contains($html, 'Schedule') && str_contains($html, 'Keep the last'), 'the backups page shows the schedule');
[$status, $html] = $a->request('GET', 'dashboard');
$check($status === 200 && str_contains($html, 'data-range="7d"'), 'the dashboard offers 7-day history');
$check(str_contains($html, 'helper-banner') && str_contains($html, '/usr/local/bin/nebula-helper'), 'a missing helper is explained once, with the fix command');

if ($failures) {
    fwrite(STDERR, "$failures HTTP flow regression(s) failed.\n");
    exit(1);
}
echo "HTTP flow regressions passed.\n";
