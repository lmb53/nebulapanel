<?php
declare(strict_types=1);
/**
 * Off-site copies end to end against a minimal S3 stand-in: upload, download
 * and the integrity checks (tampered ciphertext, tampered manifest, a
 * stripped signature, and legacy archives from before signed copies).
 */
$root = sys_get_temp_dir() . '/nebula-offsite-' . bin2hex(random_bytes(6));
mkdir($root . '/data', 0700, true);
mkdir($root . '/bucket', 0700, true);
define('APP_ROOT', dirname(__DIR__) . '/panel');
define('DATA_DIR', $root . '/data');
define('PANEL_KEY_FILE', $root . '/no-key');
$config = require APP_ROOT . '/config.php';
$_SESSION = ['username' => 'test', 'role' => 'admin'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/sys.php';
require APP_ROOT . '/lib/mod_backups.php';
require APP_ROOT . '/lib/mod_offsite.php';

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok' : 'not ok') . " - $message\n";
    if (!$ok) { $failures++; }
};

// The S3 stand-in: PUT stores, GET returns, DELETE removes (no auth checks).
file_put_contents($root . '/s3.php', '<?php
$path = __DIR__ . "/bucket/" . str_replace("/", "_", rawurldecode(ltrim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/")));
switch ($_SERVER["REQUEST_METHOD"]) {
    case "PUT": file_put_contents($path, file_get_contents("php://input")); http_response_code(200); break;
    case "GET": if (is_file($path)) { readfile($path); } else { http_response_code(404); echo "<Error><Code>NoSuchKey</Code></Error>"; } break;
    case "DELETE": @unlink($path); http_response_code(204); break;
    default: http_response_code(405);
}');
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $root . '/s3.php'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
register_shutdown_function(static function () use ($server, $root): void {
    proc_terminate($server); proc_close($server);
    exec('rm -rf ' . escapeshellarg($root));
});

$cfg = offsite_defaults();
$cfg = array_merge($cfg, ['enabled' => true, 'endpoint' => 'http://127.0.0.1:' . $port, 'bucket' => 'b', 'prefix' => 'nebula',
    'access_key' => 'AKIATEST', 'secret_key' => 'secret', 'passphrase' => 'a long test passphrase']);
$check(offsite_store($cfg), 'off-site settings are saved');

// A real archive and its local manifest.
$name = 'shop.example.com-20260101-000000.tar.gz';
$src = $root . '/src';
mkdir($src . '/public', 0700, true);
file_put_contents($src . '/public/index.html', str_repeat('hello ', 500));
exec('tar -czf ' . escapeshellarg(backup_store() . '/' . $name) . ' -C ' . escapeshellarg($src) . ' public');
$archive = backup_store() . '/' . $name;
$original = hash_file('sha256', $archive);
write_json_file($archive . '.manifest.json', ['site_id' => str_repeat('a', 32), 'domain' => 'shop.example.com', 'sha256' => $original, 'databases' => []]);

$res = offsite_upload($name);
$check(!empty($res['ok']), 'an archive uploads');
$bucketKey = static fn(string $suffix): string => $root . '/bucket/b_nebula_' . $name . $suffix;
$remote = json_decode((string) @file_get_contents($bucketKey('.manifest.json')), true) ?: [];
$check(($remote['format'] ?? 0) === 2 && preg_match('/^[a-f0-9]{64}$/', (string) ($remote['mac'] ?? '')) === 1 && !empty($remote['manifest_mac']), 'the uploaded manifest carries the archive and manifest MACs');

$fetchFresh = static function () use ($archive, $name): array {
    @unlink($archive); @unlink($archive . '.manifest.json');
    return offsite_fetch($name);
};
$res = $fetchFresh();
$check(!empty($res['ok']) && hash_file('sha256', $archive) === $original, 'a signed archive downloads, verifies and decrypts to the original');

$enc = (string) file_get_contents($bucketKey('.enc'));
$tampered = $enc; $tampered[strlen($tampered) - 20] = chr(ord($tampered[strlen($tampered) - 20]) ^ 1);
file_put_contents($bucketKey('.enc'), $tampered);
$res = $fetchFresh();
$check(empty($res['ok']) && str_contains((string) ($res['error'] ?? ''), 'signature') && !is_file($archive), 'a modified archive in the bucket is refused before decryption');
file_put_contents($bucketKey('.enc'), $enc);

$forged = $remote; $forged['sha256'] = str_repeat('0', 64);
file_put_contents($bucketKey('.manifest.json'), json_encode($forged));
$res = $fetchFresh();
$check(empty($res['ok']) && str_contains((string) ($res['error'] ?? ''), 'manifest'), 'a rewritten manifest (checksum included) is refused');

$stripped = $remote; unset($stripped['format'], $stripped['mac'], $stripped['mac_salt'], $stripped['manifest_mac']);
file_put_contents($bucketKey('.manifest.json'), json_encode($stripped));
$res = $fetchFresh();
$check(empty($res['ok']) && str_contains((string) ($res['error'] ?? ''), 'tampered'), 'stripping the signature from an archive this server signed is refused');

@unlink(DATA_DIR . '/offsite-authenticated.json');
$res = $fetchFresh();
$check(!empty($res['ok']) && str_contains((string) ($res['message'] ?? ''), 'predates signed') && hash_file('sha256', $archive) === $original, 'a legacy unsigned archive still restores, with a warning');

if ($failures) { fwrite(STDERR, "$failures off-site round-trip check(s) failed.\n"); exit(1); }
echo "Off-site round-trip checks passed.\n";
