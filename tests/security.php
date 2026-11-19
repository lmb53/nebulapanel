<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/nebula-security-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
define('APP_ROOT', $root . '/panel');
define('DATA_DIR', $tmp);
define('WEBAPPS_PORT_FILE', $tmp . '/webapps-port');
define('PMA_TICKETS_DIR', $tmp . '/pma-tickets');
file_put_contents($tmp . '/webapps-port', "2096\n");
mkdir($tmp . '/pma-tickets', 0700);
$config = require APP_ROOT . '/config.php';
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/sys.php';
require APP_ROOT . '/lib/mod_sites.php';
require APP_ROOT . '/lib/mod_git.php';
require APP_ROOT . '/lib/mod_compose.php';
require APP_ROOT . '/lib/mod_api.php';
require APP_ROOT . '/lib/mod_logs.php';
require APP_ROOT . '/lib/mod_dns.php';
require_once APP_ROOT . '/lib/mod_docker.php';
require APP_ROOT . '/lib/mod_backups.php';
request_id();

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) {
        echo "ok - $message\n";
    } else {
        $failures++;
        echo "not ok - $message\n";
    }
};

$check(!role_can('cron.manage', 'operator'), 'operators cannot schedule arbitrary commands');
$check(!role_can('websites.manage', 'developer'), 'developers cannot reach global website management');
$check(!role_can('databases.manage', 'developer'), 'developers cannot reach global databases');
$check(role_can('services.control', 'operator'), 'operators retain scoped service control');
$check(panel_password_error('password1234', 'admin') !== null, 'common panel passwords are rejected');
$check(panel_password_error('correct horse battery staple', 'admin') === null, 'strong passphrases are accepted');

$id = str_repeat('a', 32);
$check(sv_managed_path_ok('/srv/nebula/sites/' . $id . '/public', $id), 'exact managed site path is accepted');
$check(!sv_managed_path_ok('/etc', $id), '/etc is rejected as a site path');
$check(!sv_managed_path_ok('/srv/nebula/sites/' . $id . '/public/../other', $id), 'site sibling traversal is rejected');
$check(!sv_managed_path_ok('/srv/nebula/sites/' . str_repeat('b', 32) . '/public', $id), 'mismatched site IDs are rejected');
$check(sv_domain_ok('app.example.com') && !sv_domain_ok('bad..example.com') && !sv_domain_ok(str_repeat('a',64).'.example'), 'domains are validated label-by-label');

$check(git_url_ok('https://github.com/example/project.git'), 'credential-free HTTPS Git URL is accepted');
$check(!git_url_ok('https://user:token@example.com/project.git'), 'Git URL userinfo is rejected');
$check(git_url_ok('git@github.com:owner/repo.git') && !git_url_ok('ssh://root@host/x.git') && !git_url_ok('git@host:repo; id') && !git_url_ok('ext::sh -c id'),
    'SSH Git URLs are limited to git@host deploy-key form');
$check(strpos(git_redact_url('https://user:secret@example.com/repo.git'), 'secret') === false, 'legacy Git URL credentials are redacted');

$danger = compose_save('danger', "services:\n  x:\n    image: alpine:3\n    privileged: true\n", true);
$check(empty($danger['ok']), 'privileged Compose service is rejected');
$publicPort = compose_save('public-port', "services:\n  x:\n    image: nginx:1\n    ports:\n      - \"8080:80\"\n", true);
$check(empty($publicPort['ok']), 'public Compose bind is rejected');
$safe = compose_save('safe', "services:\n  x:\n    image: nginx:1\n    ports:\n      - \"127.0.0.1:8080:80\"\n", true);
$check(!empty($safe['ok']), 'loopback-only Compose bind is accepted');
$hostCompose = compose_save('host-mount', "services:\n  x:\n    image: nginx:1\n    volumes:\n      - /etc/passwd:/host/passwd:ro\n", true);
$check(empty($hostCompose['ok']), 'Compose host-path mounts are rejected');
$buildCompose = compose_save('build-context', "services:\n  x:\n    build: /etc\n", true);
$check(empty($buildCompose['ok']), 'Compose host build contexts are rejected');
$floatingCompose = compose_save('floating-image', "services:\n  x:\n    image: nginx:latest\n", true);
$check(empty($floatingCompose['ok']), 'Compose mutable image tags are rejected');
$check(!dk_pinned_image_ok('nginx:latest') && dk_pinned_image_ok('nginx:1.27.4-alpine'), 'Docker images require a non-latest version');
$hostMount = dk_container_create(['name'=>'test','image'=>'alpine:3.20','volumes'=>"/:/host:ro"]);
$check(empty($hostMount['ok']) && str_contains((string)($hostMount['error']??''), 'named-volume'), 'Docker host-path mounts are rejected');

$check(dns_record_set_error([
    ['name'=>'www','type'=>'CNAME','value'=>'app.example.com'],
    ['name'=>'www','type'=>'A','value'=>'192.0.2.1'],
]) !== null, 'DNS CNAME coexistence is rejected');
$check(dns_record_set_error([
    ['name'=>'@','type'=>'A','value'=>'192.0.2.1'],
]) === null, 'valid DNS record sets are accepted');

$plain = 'nbp_' . str_repeat('1', 48);
api_tokens_save([[
    'id' => str_repeat('2', 16),
    'label' => 'test-bot',
    'hash' => hash('sha256', $plain),
    'role' => 'auditor',
    'scopes' => ['get:health'],
    'created_at' => date('c'),
    'expires_at' => date('c', time() + 3600),
]]);
$check(api_token_authenticate($plain), 'unexpired bearer token authenticates');
$check(current_role() === 'auditor', 'bearer token role is enforced');
$check(api_token_scope_allows('health', 'GET'), 'bearer token endpoint scope is enforced');
$check(!api_token_scope_allows('sites', 'POST'), 'bearer token cannot exceed its scope');
$restricted = 'nbp_' . str_repeat('3', 48);
api_tokens_save([[
    'id'=>str_repeat('4',16),'label'=>'restricted','hash'=>hash('sha256',$restricted),
    'role'=>'auditor','scopes'=>['get:health'],'allowed_ips'=>['203.0.113.8'],
    'created_at'=>date('c'),'expires_at'=>date('c',time()+3600),
]]);
$check(!api_token_authenticate($restricted), 'bearer token source-IP restrictions are enforced');

// Per-user tokens: never above the owner, dead once the owner is disabled.
save_panel_users([
    ['id' => 7, 'username' => 'ops', 'hash' => panel_password_hash('ops password long 1'), 'role' => 'operator', 'enabled' => true],
    ['id' => 8, 'username' => 'boss', 'hash' => panel_password_hash('boss password long 1'), 'role' => 'admin', 'enabled' => true],
]);
$opsOwner = ['id' => 7, 'username' => 'ops', 'role' => 'operator'];
$check(empty(api_token_generate('escalate', 'admin', ['get:health'], 30, [], $opsOwner)['ok']), 'a user cannot mint a token above their own role');
$owned = api_token_generate('ops-monitor', 'operator', ['get:health'], 30, [], $opsOwner);
$check(!empty($owned['ok']) && ($owned['record']['owner'] ?? '') === 'ops', 'tokens record their owner');
$bossToken = api_token_generate('boss-bot', 'admin', ['get:health'], 30, [], ['id' => 8, 'username' => 'boss', 'role' => 'admin']);
$check(empty(api_token_revoke((string) $bossToken['record']['id'], 7)['ok']), 'a user cannot revoke someone else\'s token');
$users = panel_users(); $users[1]['role'] = 'auditor'; save_panel_users($users);
$check(api_token_authenticate((string) $bossToken['token']) && current_role() === 'auditor', 'a demoted owner\'s token is demoted with them');
$users = panel_users(); $users[0]['enabled'] = false; save_panel_users($users);
$check(!api_token_authenticate((string) $owned['token']), 'a disabled owner\'s tokens stop working');
$check(!empty(api_token_revoke((string) $owned['record']['id'], 7)['ok']), 'owners can revoke their own tokens');
api_tokens_save([]);
// Passkeys: a real P-256 key signs assertions; anything off must be refused.
require_once APP_ROOT . '/lib/mod_passkeys.php';
$_SERVER['HTTP_HOST'] = 'panel.example.com'; $_SERVER['HTTPS'] = 'on';
$cborHead = static fn(int $major, int $n): string => $n < 24 ? chr(($major << 5) | $n) : ($n < 256 ? chr(($major << 5) | 24) . chr($n) : chr(($major << 5) | 25) . pack('n', $n));
$cborInt = static fn(int $v): string => $v >= 0 ? $cborHead(0, $v) : $cborHead(1, -1 - $v);
$cborBytes = static fn(string $b): string => $cborHead(2, strlen($b)) . $b;
$ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$ecd = openssl_pkey_get_details($ec)['ec'];
$cose = $cborHead(5, 5) . $cborInt(1) . $cborInt(2) . $cborInt(3) . $cborInt(-7) . $cborInt(-1) . $cborInt(1)
    . $cborInt(-2) . $cborBytes(str_pad($ecd['x'], 32, "\0", STR_PAD_LEFT)) . $cborInt(-3) . $cborBytes(str_pad($ecd['y'], 32, "\0", STR_PAD_LEFT));
$credId = random_bytes(32);
$regAuth = hash('sha256', 'panel.example.com', true) . chr(0x45) . pack('N', 0) . str_repeat("\0", 16) . pack('n', 32) . $credId . $cose;
$parsed = passkey_parse_auth_data($regAuth, true);
$check($parsed['cred_id'] === $credId && $parsed['key']['alg'] === -7 && openssl_pkey_get_public($parsed['key']['pem']) !== false, 'a COSE P-256 key converts to a usable public key');
$threw = false; try { $o = 0; cbor_decode("\x9f\x01\xff", $o); } catch (RuntimeException) { $threw = true; }
$check($threw, 'indefinite-length CBOR is rejected');
save_panel_users([['id' => 9, 'username' => 'pk', 'hash' => panel_password_hash('pk password long 1'), 'role' => 'admin', 'enabled' => true, 'session_version' => 1,
    'mfa' => ['secret' => 'JBSWY3DPEHPK3PXP', 'enabled_at' => date('c'), 'last_step' => 0, 'recovery' => [],
        'passkeys' => [['id' => b64url_encode($credId), 'name' => 'test', 'key' => $parsed['key'], 'count' => 0, 'created_at' => date('c')]]]]]);
$assert = static function (array $tweak = []) use ($ec, $credId): array {
    $_SESSION['mfa_pending'] = ['uid' => 9, 'version' => 1, 'at' => time(), 'tries' => 0];
    $options = passkey_login_options(9);
    $client = json_encode(['type' => 'webauthn.get', 'challenge' => $tweak['challenge'] ?? $options['challenge'], 'origin' => $tweak['origin'] ?? 'https://panel.example.com']);
    $auth = hash('sha256', $tweak['rp'] ?? 'panel.example.com', true) . chr(0x05) . pack('N', $tweak['count'] ?? 5);
    openssl_sign($auth . hash('sha256', $client, true), $sig, $ec, OPENSSL_ALGO_SHA256);
    if (!empty($tweak['badsig'])) { $sig[10] = chr(ord($sig[10]) ^ 1); }
    return passkey_login_verify(9, 1, ['id' => $tweak['id'] ?? b64url_encode($credId), 'clientDataJSON' => b64url_encode($client), 'authenticatorData' => b64url_encode($auth), 'signature' => b64url_encode($sig)]);
};
$check(empty($assert(['badsig' => true])['ok']), 'a passkey assertion with a bad signature is refused');
$check(empty($assert(['rp' => 'evil.example.com'])['ok']), 'a passkey assertion for another site is refused');
$check(empty($assert(['origin' => 'https://evil.example.com'])['ok']), 'a passkey assertion from another origin is refused');
$check(empty($assert(['challenge' => b64url_encode(random_bytes(32))])['ok']), 'a passkey assertion with a stale challenge is refused');
$check(empty($assert(['id' => b64url_encode(random_bytes(32))])['ok']), 'an unregistered passkey is refused');
$good = $assert(['count' => 5]);
$check(!empty($good['ok']) && ($good['user']['username'] ?? '') === 'pk', 'a valid passkey assertion signs in');
$check(empty($assert(['count' => 5])['ok']), 'a passkey whose counter does not advance (possible clone) is refused');
$_SERVER['HTTP_HOST'] = '203.0.113.5';
$check(!passkey_supported(), 'passkeys are not offered when the panel is opened by IP address');
unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
$_SESSION = [];
$check(backup_resolve('../not-a-backup') === null, 'backup filenames are strictly confined');

$redacted = log_redact("Authorization: Bearer abc.def\npassword=hunter2\nhttps://u:p@example.test/repo");
$check(strpos($redacted, 'hunter2') === false && strpos($redacted, 'abc.def') === false && strpos($redacted, 'u:p') === false, 'log secrets are redacted');

// --- Proxy, throttle and secret handling ---------------------------------------
$config['trusted_proxies'] = ['10.0.0.1', '10.0.0.2'];
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 198.51.100.7, 10.0.0.2';
$check(client_ip() === '198.51.100.7', 'X-Forwarded-For takes the last untrusted hop, not the client-chosen first entry');
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
$check(client_ip() === '198.51.100.9', 'X-Forwarded-For is ignored from untrusted peers');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$config['trusted_proxies'] = [];
$check(login_throttle_ip('2001:db8:1:2:aaaa::1') === login_throttle_ip('2001:db8:1:2:bbbb::9'), 'IPv6 clients are throttled per /64');
$check(login_throttle_ip('2001:db8:1:3::1') !== login_throttle_ip('2001:db8:1:2::1'), 'different IPv6 /64 networks are throttled separately');
@unlink(login_attempts_file());
$spread = [];
for ($i = 1; $i <= 31; $i++) { $spread[] = reserve_login_attempt('192.0.2.' . $i, 'target-admin'); }
$check($spread[29] === 0 && $spread[30] > 0, 'one account is throttled across many addresses');
@unlink(login_attempts_file());
$check(str_contains(js_json('a</script><script>alert(1)</script>'), 'u003C') &&!str_contains(js_json('</script>'), '</'), 'inline JSON cannot close a script element');
$sealedNoKey = secret_seal('JBSWY3DPEHPK3PXP');
$check(secret_open($sealedNoKey) === 'JBSWY3DPEHPK3PXP', 'secrets round-trip without a key');
$check(secret_open('enc:v1:!!!') === '', 'a corrupt sealed secret opens to nothing');
save_panel_users([['id' => 1, 'username' => 'CaseAdmin', 'hash' => panel_password_hash('case insensitive password 1'), 'role' => 'admin', 'enabled' => true]]);
$check(attempt_login('caseadmin', 'case insensitive password 1') === true, 'sign-in names are matched case-insensitively');
$_SESSION = [];
$check(attempt_login('nobody-here', 'whatever password 1') === false, 'unknown accounts are refused');
$check(is_file(DATA_DIR . '/login-dummy.hash'), 'unknown accounts are verified against a dummy hash');
$_SESSION = [];

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') { $remove($path . DIRECTORY_SEPARATOR . $entry); }
    }
    @rmdir($path);
};

// Bundled web apps live on their own origin (same host, separate port).
$_SERVER['HTTP_HOST'] = 'panel.example.com';
$check(webapps_url('/dbadmin-1a2b3c4d/') === 'https://panel.example.com:2096/dbadmin-1a2b3c4d/', 'web-app links use the web-app port');
$_SERVER['HTTP_HOST'] = 'panel.example.com:8443';
$check(webapps_url('webmail-1a2b3c4d/') === 'https://panel.example.com:2096/webmail-1a2b3c4d/', 'the panel port is replaced, not appended');
$_SERVER['HTTP_HOST'] = '[2001:db8::1]:443';
$check(webapps_url('/x/') === 'https://[2001:db8::1]:2096/x/', 'IPv6 hosts keep their brackets');
$_SERVER['HTTP_HOST'] = 'evil.example/"><script>';
$check(webapps_url('/x/') === 'https://localhost:2096/x/', 'a malformed Host header never reaches a web-app link');

// phpMyAdmin sign-on tickets: single files, readable by the web-app group only.
require_once APP_ROOT . '/lib/mod_pma.php';
$ticket = pma_write_ticket(['user' => 'u', 'password' => 'p', 'db' => 'd', 'exp' => time() + 60]);
$ticketFile = PMA_TICKETS_DIR . '/' . $ticket . '.json';
$check(is_string($ticket) && preg_match('/^[a-f0-9]{48}$/', $ticket) === 1 && is_file($ticketFile), 'a sign-on ticket is written under a random name');
$check((fileperms($ticketFile) & 0777) === 0640, 'sign-on tickets are not world-readable');
$check(pma_write_ticket(['x' => 1]) !== $ticket, 'every sign-on gets its own ticket');

// Deploy webhooks: HMAC or GitLab token; legacy URL tokens only for legacy hooks.
$secret = str_repeat('ab', 24);
$signed = ['hook_secret' => $secret, 'hook_hash' => hash('sha256', $secret)];
$body = '{"ref":"refs/heads/main"}';
$check(git_hook_verify($signed, ['x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, $secret)], $body), 'a GitHub-style signature verifies');
$check(git_hook_verify($signed, ['x-gitea-signature' => hash_hmac('sha256', $body, $secret)], $body), 'a Gitea-style signature verifies');
$check(!git_hook_verify($signed, ['x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'other')], $body), 'a signature under another secret is refused');
$check(!git_hook_verify($signed, [], $body, $secret), 'a signed webhook ignores URL tokens');
$check(git_hook_verify(['hook_hash' => hash('sha256', $secret)], [], $body, $secret), 'a legacy webhook keeps its URL token');
$check(!git_hook_verify([], ['x-gitlab-token' => $secret], $body), 'a site without a webhook accepts nothing');
$check(git_public_meta($signed + ['hook_created' => 'now']) === ['hook_created' => 'now', 'hook_signed' => true], 'webhook secrets are removed from API metadata');

// Off-site manifests are authenticated: any edit breaks the MAC.
require_once APP_ROOT . '/lib/mod_offsite.php';
$key = offsite_mac_key('a long passphrase', str_repeat("\x01", 16));
$manifest = ['file' => 'site-20260101-000000.tar.gz', 'sha256' => str_repeat('a', 64), 'databases' => ['b', 'a'], 'format' => 2, 'mac' => str_repeat('c', 64)];
$manifest['manifest_mac'] = hash_hmac('sha256', offsite_manifest_canonical($manifest), $key);
$roundTrip = json_decode((string) json_encode($manifest, JSON_PRETTY_PRINT), true);
$check(hash_equals($manifest['manifest_mac'], hash_hmac('sha256', offsite_manifest_canonical($roundTrip), $key)), 'a stored manifest verifies after a JSON round trip');
$tampered = $roundTrip; $tampered['sha256'] = str_repeat('b', 64);
$check(!hash_equals($manifest['manifest_mac'], hash_hmac('sha256', offsite_manifest_canonical($tampered), $key)), 'changing the checksum in the bucket is detected');
$reordered = array_reverse($roundTrip, true);
$check(hash_equals($manifest['manifest_mac'], hash_hmac('sha256', offsite_manifest_canonical($reordered), $key)), 'key order does not change the manifest MAC');
$check(!hash_equals($manifest['manifest_mac'], hash_hmac('sha256', offsite_manifest_canonical($roundTrip), offsite_mac_key('another passphrase', str_repeat("\x01", 16)))), 'another passphrase cannot produce the MAC');

$remove($tmp);

if ($failures > 0) {
    fwrite(STDERR, "$failures security regression(s) failed.\n");
    exit(1);
}
echo "All security regressions passed.\n";
