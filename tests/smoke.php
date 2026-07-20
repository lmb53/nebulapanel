<?php
/** Lightweight cross-platform checks. Run: php tests/smoke.php */

$appRoot = dirname(__DIR__) . '/panel';
$testDir = sys_get_temp_dir() . '/nebula-smoke-' . bin2hex(random_bytes(6));
if (!mkdir($testDir, 0700, true)) {
    fwrite(STDERR, "Could not create test directory\n");
    exit(1);
}

define('APP_ROOT', $appRoot);
define('DATA_DIR', $testDir);
$config = require APP_ROOT . '/config.php';
$config['login_max_attempts'] = 3;
$config['login_window'] = 60;
$config['fm_root'] = $testDir . '/root';
$_SERVER['REMOTE_ADDR'] = '198.51.100.250';

require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/sys.php';
require APP_ROOT . '/lib/mod_api.php';
require APP_ROOT . '/lib/mod_updates.php';
require APP_ROOT . '/lib/modules.php';
require APP_ROOT . '/lib/files.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { $failures[] = $message; }
};

$jsonPath = $testDir . '/atomic.json';
$check(write_json_file($jsonPath, ['value' => 1]), 'initial JSON write failed');
$check(write_json_file($jsonPath, ['value' => 2]), 'replacement JSON write failed');
$json = json_decode((string) file_get_contents($jsonPath), true);
$check(($json['value'] ?? null) === 2, 'replacement JSON content was not preserved');

$attempts = [reserve_login_attempt(), reserve_login_attempt(), reserve_login_attempt(), reserve_login_attempt()];
$check($attempts[0] === 0 && $attempts[1] === 0 && $attempts[2] === 0, 'valid login attempts were blocked');
$check($attempts[3] > 0, 'login throttle did not activate');
record_login_attempt(true);
$check(login_retry_after() === 0, 'successful login did not clear the throttle');

mkdir($config['fm_root'], 0700, true);
mkdir($config['fm_root'] . '/site', 0700);
file_put_contents($config['fm_root'] . '/site/index.txt', 'ok');
$check(fm_resolve('site/index.txt') === realpath($config['fm_root'] . '/site/index.txt'), 'valid file-manager path was rejected');
$check(fm_resolve('../outside') === null, 'file-manager traversal was accepted');
$check(is_page_route('dashboard') && !is_page_route('../config'), 'page route whitelist failed');
$check(is_page_route('domains') && is_page_route('dns') && is_page_route('sshkeys') && is_page_route('notifications') && is_page_route('api'), 'mockup-backed module routes are missing');
$check(is_file(APP_ROOT . '/api/provision.php'), 'provisioning API endpoint is missing');
$check(human_bytes(1048576) === '1 MB', 'byte formatting failed');
$check(fm_link_path($config['fm_root'] . '/site') === 'site', 'absolute file-manager link conversion failed');

$chunks = '';
$streamCmd = escapeshellarg(PHP_BINARY) . ' --version';
[$streamCode, $streamOut] = run_cmd_stream($streamCmd, static function (string $chunk) use (&$chunks): void { $chunks .= $chunk; });
$check(
    $streamCode === 0 && strpos($streamOut, 'PHP') !== false && strpos($chunks, 'PHP') !== false,
    'streaming command runner failed (code=' . $streamCode . ', out=' . json_encode($streamOut) . ', chunks=' . json_encode($chunks) . ')'
);

$generated = api_token_generate('smoke test');
$check(!empty($generated['ok']) && strncmp((string) ($generated['token'] ?? ''), 'nbp_', 4) === 0, 'API token generation failed');
$check(api_token_authenticate((string) ($generated['token'] ?? '')), 'API token authentication failed');
$tokenId = (string) ($generated['record']['id'] ?? '');
$check(!empty(api_token_revoke($tokenId)['ok']), 'API token revocation failed');
$check(empty(upd_install_package('../bad')['ok']), 'unsafe package name was accepted');

@unlink($config['fm_root'] . '/site/index.txt');
@rmdir($config['fm_root'] . '/site');
@rmdir($config['fm_root']);
@unlink($jsonPath);
@unlink(login_attempts_file());
@unlink(api_tokens_file());
@unlink(DATA_DIR . '/audit.log');
@rmdir($testDir);

if ($failures) {
    foreach ($failures as $failure) { fwrite(STDERR, "FAIL: $failure\n"); }
    exit(1);
}

echo "Nebula smoke tests passed.\n";
