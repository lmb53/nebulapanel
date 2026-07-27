<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/nebula-security-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
define('APP_ROOT', $root . '/panel');
define('DATA_DIR', $tmp);
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
$check(!git_url_ok('git@example.com:project/repo.git'), 'SSH Git URL is rejected pending deploy-key support');
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

$check(backup_resolve('../not-a-backup') === null, 'backup filenames are strictly confined');

$redacted = log_redact("Authorization: Bearer abc.def\npassword=hunter2\nhttps://u:p@example.test/repo");
$check(strpos($redacted, 'hunter2') === false && strpos($redacted, 'abc.def') === false && strpos($redacted, 'u:p') === false, 'log secrets are redacted');

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') { $remove($path . DIRECTORY_SEPARATOR . $entry); }
    }
    @rmdir($path);
};
$remove($tmp);

if ($failures > 0) {
    fwrite(STDERR, "$failures security regression(s) failed.\n");
    exit(1);
}
echo "All security regressions passed.\n";
