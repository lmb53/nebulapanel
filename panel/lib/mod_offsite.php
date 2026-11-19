<?php
/**
 * Off-site backups: copy archives to S3-compatible object storage (AWS S3,
 * Backblaze B2, Wasabi, Cloudflare R2, MinIO, ...), encrypted on this server
 * before they leave it.
 *
 * Encryption is standard OpenSSL so an archive can be recovered without the
 * panel:
 *
 *   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in X.tar.gz.enc -out X.tar.gz
 *
 * (it asks for the passphrase). The plaintext SHA-256 travels in a small
 * manifest object next to each archive and is checked after download.
 * Credentials and the passphrase are sealed with the panel key at rest.
 */

require_once APP_ROOT . '/lib/mod_backups.php';

const OFFSITE_CIPHER_ARGS = '-aes-256-cbc -salt -pbkdf2 -iter 200000';
const OFFSITE_MAX_BYTES = 5 * 1024 * 1024 * 1024; // single PUT limit on S3

function offsite_file(): string { return DATA_DIR . '/offsite.json'; }

function offsite_defaults(): array
{
    return ['enabled' => false, 'endpoint' => '', 'region' => 'us-east-1', 'bucket' => '', 'prefix' => 'nebula',
        'path_style' => true, 'access_key' => '', 'secret_key' => '', 'passphrase' => '', 'keep' => 14,
        'last_run' => 0, 'last_result' => null];
}

/** Stored settings with secrets opened (internal use only). */
function offsite_config(): array
{
    $saved = @json_decode((string) @file_get_contents(offsite_file()), true);
    $cfg = array_merge(offsite_defaults(), is_array($saved) ? $saved : []);
    $cfg['secret_key'] = $cfg['secret_key'] !== '' ? secret_open((string) $cfg['secret_key']) : '';
    $cfg['passphrase'] = $cfg['passphrase'] !== '' ? secret_open((string) $cfg['passphrase']) : '';
    return $cfg;
}

/** Settings safe to show in the browser. */
function offsite_public(): array
{
    $cfg = offsite_config();
    $out = $cfg;
    unset($out['secret_key'], $out['passphrase']);
    $out['has_secret'] = $cfg['secret_key'] !== '';
    $out['has_passphrase'] = $cfg['passphrase'] !== '';
    $out['configured'] = offsite_ready($cfg);
    return $out;
}

function offsite_ready(array $cfg): bool
{
    return $cfg['endpoint'] !== '' && $cfg['bucket'] !== '' && $cfg['access_key'] !== '' && $cfg['secret_key'] !== '' && $cfg['passphrase'] !== '';
}

function offsite_store(array $cfg): bool
{
    $cfg['secret_key'] = $cfg['secret_key'] !== '' ? secret_seal((string) $cfg['secret_key']) : '';
    $cfg['passphrase'] = $cfg['passphrase'] !== '' ? secret_seal((string) $cfg['passphrase']) : '';
    return write_json_file(offsite_file(), array_intersect_key($cfg, offsite_defaults()));
}

/** Validate and save. Empty secret fields keep the stored value; a passphrase is generated on first save. */
function offsite_save(array $input): array
{
    $cfg = offsite_config();
    $endpoint = rtrim(trim((string) ($input['endpoint'] ?? $cfg['endpoint'])), '/');
    if ($endpoint !== '' && !preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?$#', $endpoint)
        && !preg_match('#^http://(127\.0\.0\.1|localhost|\[::1\])(:[0-9]{1,5})?$#', $endpoint)) {
        return ['ok' => false, 'error' => 'The endpoint must be an https:// address with no path, e.g. https://s3.eu-central-1.amazonaws.com (plain http only for storage on this server).'];
    }
    $region = trim((string) ($input['region'] ?? $cfg['region']));
    if (!preg_match('/^[a-z0-9-]{1,32}$/', $region)) { return ['ok' => false, 'error' => 'Region looks wrong (for example us-east-1, or auto for R2).']; }
    $bucket = trim((string) ($input['bucket'] ?? $cfg['bucket']));
    if ($bucket !== '' && !preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket)) { return ['ok' => false, 'error' => 'Bucket names are 3–63 lower-case letters, digits, dots or dashes.']; }
    $prefix = trim(trim((string) ($input['prefix'] ?? $cfg['prefix'])), '/');
    if (!preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$|^$#', $prefix) || strlen($prefix) > 100) { return ['ok' => false, 'error' => 'The folder prefix may use letters, digits, . _ - and /.']; }
    $access = trim((string) ($input['access_key'] ?? $cfg['access_key']));
    if ($access !== '' && !preg_match('/^[\x21-\x7E]{1,128}$/', $access)) { return ['ok' => false, 'error' => 'The access key looks wrong.']; }
    $secret = (string) ($input['secret_key'] ?? '');
    if ($secret !== '' && !preg_match('/^[\x21-\x7E]{1,256}$/', $secret)) { return ['ok' => false, 'error' => 'The secret key looks wrong.']; }
    $passphrase = (string) ($input['passphrase'] ?? '');
    if ($passphrase !== '' && (strlen($passphrase) < 16 || strlen($passphrase) > 256 || preg_match('/[\x00-\x1F\x7F]/', $passphrase))) {
        return ['ok' => false, 'error' => 'Use an encryption passphrase of at least 16 characters.'];
    }
    $keep = (int) ($input['keep'] ?? $cfg['keep']);
    if ($keep < 1 || $keep > 365) { return ['ok' => false, 'error' => 'Keep between 1 and 365 off-site copies per website.']; }
    $next = $cfg;
    $next['endpoint'] = $endpoint; $next['region'] = $region; $next['bucket'] = $bucket; $next['prefix'] = $prefix;
    $next['access_key'] = $access; $next['keep'] = $keep;
    $next['path_style'] = (bool) ($input['path_style'] ?? $cfg['path_style']);
    $next['enabled'] = (bool) ($input['enabled'] ?? $cfg['enabled']);
    if ($secret !== '') { $next['secret_key'] = $secret; }
    $generated = false;
    if ($passphrase !== '') {
        $next['passphrase'] = $passphrase;
    } elseif ($next['passphrase'] === '') {
        $next['passphrase'] = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
        $generated = true;
    }
    if ($next['enabled'] && !offsite_ready($next)) { return ['ok' => false, 'error' => 'Fill in the endpoint, bucket and both keys before turning off-site copies on.']; }
    if (!offsite_store($next)) { return ['ok' => false, 'error' => 'Could not save the off-site settings.']; }
    audit('backup.offsite.save', $next['enabled'] ? 'enabled ' . $endpoint . '/' . $bucket : 'disabled');
    $res = ['ok' => true, 'settings' => offsite_public()];
    if ($generated) {
        $res['passphrase'] = $next['passphrase'];
        $res['message'] = 'An encryption passphrase was generated. Store it somewhere safe outside this server — without it the off-site copies cannot be decrypted.';
    }
    return $res;
}

/** The passphrase, shown again only after the account password is re-entered. */
function offsite_reveal_passphrase(string $password): array
{
    $username = (string) current_user();
    foreach (panel_users() as $user) {
        if (strcasecmp((string) $user['username'], $username) === 0) {
            if (empty($user['hash']) || !password_verify($password, (string) $user['hash'])) { return ['ok' => false, 'error' => 'Your password is incorrect.']; }
            audit('backup.offsite.reveal');
            return ['ok' => true, 'passphrase' => offsite_config()['passphrase']];
        }
    }
    return ['ok' => false, 'error' => 'Your account was not found.'];
}

// ---- S3 (Signature Version 4) ---------------------------------------------------

function offsite_key(array $cfg, string $name): string
{
    return ($cfg['prefix'] !== '' ? $cfg['prefix'] . '/' : '') . $name;
}

function offsite_uri_encode(string $value, bool $slash = true): string
{
    $encoded = rawurlencode($value);
    return $slash ? $encoded : str_replace('%2F', '/', $encoded);
}

/**
 * One signed S3 request. $upload streams a file as the body, $download writes
 * the response body to a file. Returns [status, body, error].
 */
function offsite_request(array $cfg, string $method, string $key = '', array $query = [], ?string $upload = null, ?string $download = null, array $headers = [], int $timeout = 3600): array
{
    $scheme = parse_url($cfg['endpoint'], PHP_URL_SCHEME) === 'http' ? 'http' : 'https';
    $host = (string) parse_url($cfg['endpoint'], PHP_URL_HOST);
    $port = parse_url($cfg['endpoint'], PHP_URL_PORT);
    $hostHeader = $host . ($port ? ':' . $port : '');
    if (!empty($cfg['path_style'])) {
        $path = '/' . $cfg['bucket'] . ($key !== '' ? '/' . offsite_uri_encode($key, false) : '');
    } else {
        $hostHeader = $cfg['bucket'] . '.' . $hostHeader;
        $path = '/' . ($key !== '' ? offsite_uri_encode($key, false) : '');
    }
    ksort($query);
    $canonicalQuery = implode('&', array_map(static fn($k, $v) => offsite_uri_encode((string) $k) . '=' . offsite_uri_encode((string) $v), array_keys($query), $query));
    $now = gmdate('Ymd\THis\Z');
    $date = substr($now, 0, 8);
    $headers = array_change_key_case($headers + ['host' => $hostHeader, 'x-amz-date' => $now, 'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD'], CASE_LOWER);
    ksort($headers);
    $canonicalHeaders = '';
    foreach ($headers as $name => $value) { $canonicalHeaders .= $name . ':' . trim((string) $value) . "\n"; }
    $signed = implode(';', array_keys($headers));
    $canonical = implode("\n", [$method, $path, $canonicalQuery, $canonicalHeaders, $signed, 'UNSIGNED-PAYLOAD']);
    $scope = $date . '/' . $cfg['region'] . '/s3/aws4_request';
    $toSign = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonical);
    $kDate = hash_hmac('sha256', $date, 'AWS4' . $cfg['secret_key'], true);
    $kRegion = hash_hmac('sha256', $cfg['region'], $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $toSign, $kSigning);
    $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$cfg['access_key']}/$scope, SignedHeaders=$signed, Signature=$signature";
    $url = $scheme . '://' . $hostHeader . $path . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
    $ch = curl_init($url);
    $lines = [];
    foreach ($headers as $name => $value) { if ($name !== 'host') { $lines[] = $name . ': ' . $value; } }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $lines, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_PROTOCOLS => $scheme === 'http' ? CURLPROTO_HTTP : CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => true]);
    $in = $out = null;
    if ($upload !== null) {
        $in = fopen($upload, 'rb');
        if ($in === false) { return [0, '', 'Could not open the file to upload.']; }
        curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $in, CURLOPT_INFILESIZE => (int) filesize($upload)]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    if ($download !== null) {
        $out = fopen($download, 'wb');
        if ($out === false) { if ($in) { fclose($in); } return [0, '', 'Could not write the download.']; }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, string $data) use ($out, &$errorBody): int {
            if (curl_getinfo($handle, CURLINFO_RESPONSE_CODE) >= 300) { $errorBody = ($errorBody ?? '') . $data; return strlen($data); }
            return (int) fwrite($out, $data);
        });
    }
    if ($method === 'HEAD') { curl_setopt($ch, CURLOPT_NOBODY, true); }
    $errorBody = null;
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($in) { fclose($in); }
    if ($out) { fclose($out); }
    if ($download !== null) { $body = (string) ($errorBody ?? ''); }
    if ($body === false) { $body = ''; }
    if ($error !== '') { return [$status, (string) $body, 'Could not reach the storage: ' . $error]; }
    if ($status >= 300) {
        $message = preg_match('#<Message>(.*?)</Message>#s', (string) $body, $m) ? html_entity_decode($m[1]) : '';
        $code = preg_match('#<Code>(.*?)</Code>#s', (string) $body, $m) ? $m[1] : '';
        return [$status, (string) $body, 'Storage answered HTTP ' . $status . ($code !== '' ? ' ' . $code : '') . ($message !== '' ? ': ' . $message : '')];
    }
    return [$status, (string) $body, ''];
}

// ---- Encryption -----------------------------------------------------------------

/** Run openssl enc in either direction with the passphrase passed through the environment. */
function offsite_crypt(bool $encrypt, string $in, string $out, string $passphrase): ?string
{
    $cmd = 'openssl enc ' . ($encrypt ? '-e' : '-d') . ' ' . OFFSITE_CIPHER_ARGS . ' -in ' . escapeshellarg($in) . ' -out ' . escapeshellarg($out) . ' -pass env:NEBULA_OFFSITE_PASS';
    $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'NEBULA_OFFSITE_PASS' => $passphrase];
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) { return 'Could not start openssl.'; }
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) { @unlink($out); return $encrypt ? 'Encryption failed: ' . trim((string) $err) : 'Decryption failed — wrong passphrase or a damaged download.'; }
    @chmod($out, 0600);
    return null;
}

// ---- Integrity --------------------------------------------------------------------
// Encrypt-then-MAC: the ciphertext and the manifest each carry an HMAC-SHA256
// under a key derived from the passphrase, so whoever controls the bucket can
// neither swap an archive nor rewrite its manifest (checksum included)
// without it being refused before anything is decrypted.

const OFFSITE_FORMAT = 2;

function offsite_mac_key(string $passphrase, string $salt): string
{
    return hash_pbkdf2('sha256', 'nebula-offsite-mac:' . $passphrase, $salt, 200000, 32, true);
}

/** Stable JSON of a manifest without its own MAC (keys sorted at every level). */
function offsite_manifest_canonical(array $manifest): string
{
    unset($manifest['manifest_mac']);
    $sort = static function ($value) use (&$sort) {
        if (!is_array($value)) { return $value; }
        if ($value === [] || array_keys($value) === range(0, count($value) - 1)) { return array_map($sort, $value); }
        ksort($value);
        return array_map($sort, $value);
    };
    return (string) json_encode($sort($manifest), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Names of archives this server uploaded with MACs (a stripped MAC is then a downgrade). */
function offsite_authenticated_names(): array
{
    $names = @json_decode((string) @file_get_contents(DATA_DIR . '/offsite-authenticated.json'), true);
    return is_array($names) ? $names : [];
}

function offsite_remember_authenticated(string $name): void
{
    $names = offsite_authenticated_names();
    $names[$name] = date('c');
    write_json_file(DATA_DIR . '/offsite-authenticated.json', $names);
}

// ---- Operations -----------------------------------------------------------------

/** Write, read back and delete a small object. */
function offsite_test(?array $cfg = null): array
{
    $cfg = $cfg ?? offsite_config();
    if (!offsite_ready($cfg)) { return ['ok' => false, 'error' => 'Fill in and save the off-site settings first.']; }
    $tmp = tempnam(sys_get_temp_dir(), 'nbo');
    $probe = 'nebula-test-' . bin2hex(random_bytes(6));
    file_put_contents($tmp, $probe);
    $key = offsite_key($cfg, '.nebula-test-' . bin2hex(random_bytes(4)));
    [, , $err] = offsite_request($cfg, 'PUT', $key, [], $tmp, null, [], 60);
    @unlink($tmp);
    if ($err !== '') { return ['ok' => false, 'error' => 'Upload test failed. ' . $err]; }
    [, $body, $err] = offsite_request($cfg, 'GET', $key, [], null, null, [], 60);
    offsite_request($cfg, 'DELETE', $key, [], null, null, [], 60);
    if ($err !== '' || $body !== $probe) { return ['ok' => false, 'error' => 'The test file was uploaded but could not be read back. ' . $err]; }
    return ['ok' => true, 'message' => 'Connected: a test file was written, read back and deleted.'];
}

/** Encrypt one local archive and upload it with its manifest. */
function offsite_upload(string $file, ?callable $onOutput = null, ?array $cfg = null): array
{
    $cfg = $cfg ?? offsite_config();
    if (!offsite_ready($cfg)) { return ['ok' => false, 'error' => 'Off-site storage is not configured.']; }
    $abs = backup_resolve($file);
    if ($abs === null) { return ['ok' => false, 'error' => 'Backup not found.']; }
    $name = basename($abs);
    if ((int) filesize($abs) > OFFSITE_MAX_BYTES) { return ['ok' => false, 'error' => $name . ' is larger than 5 GB, the single-upload limit.']; }
    $say = static function (string $text) use ($onOutput): void { if ($onOutput) { $onOutput('==> ' . $text . "\n", 'stdout'); } };
    $manifest = backup_manifest($name);
    if (empty($manifest['sha256'])) { $manifest['sha256'] = (string) hash_file('sha256', $abs); }
    $enc = backup_store() . '/.offsite-' . bin2hex(random_bytes(6)) . '.enc';
    $say('Encrypting ' . $name);
    if (($error = offsite_crypt(true, $abs, $enc, $cfg['passphrase'])) !== null) { return ['ok' => false, 'error' => $error]; }
    $salt = random_bytes(16);
    $macKey = offsite_mac_key($cfg['passphrase'], $salt);
    $mac = (string) hash_hmac_file('sha256', $enc, $macKey);
    $say('Uploading ' . $name . ' (' . human_bytes((int) filesize($enc)) . ')');
    [, , $err] = offsite_request($cfg, 'PUT', offsite_key($cfg, $name . '.enc'), [], $enc);
    @unlink($enc);
    if ($err !== '') { return ['ok' => false, 'error' => $err]; }
    $remote = $manifest;
    unset($remote['offsite_at']);
    $remote = array_merge($remote, ['file' => $name, 'cipher' => 'openssl ' . OFFSITE_CIPHER_ARGS,
        'format' => OFFSITE_FORMAT, 'mac_salt' => base64_encode($salt), 'mac' => $mac]);
    $remote['manifest_mac'] = hash_hmac('sha256', offsite_manifest_canonical($remote), $macKey);
    $meta = tempnam(sys_get_temp_dir(), 'nbm');
    file_put_contents($meta, json_encode($remote, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    [, , $err] = offsite_request($cfg, 'PUT', offsite_key($cfg, $name . '.manifest.json'), [], $meta, null, ['content-type' => 'application/json'], 120);
    @unlink($meta);
    if ($err !== '') { return ['ok' => false, 'error' => 'The archive was uploaded but its manifest was not: ' . $err]; }
    $local = backup_manifest($name);
    if ($local) { $local['offsite_at'] = date('c'); write_json_file(backup_store() . '/' . $name . '.manifest.json', $local); }
    offsite_remember_authenticated($name);
    audit('backup.offsite.upload', $name);
    return ['ok' => true, 'file' => $name];
}

/** Archives in the bucket, newest first. */
function offsite_list(?array $cfg = null): array
{
    $cfg = $cfg ?? offsite_config();
    if (!offsite_ready($cfg)) { return ['ok' => false, 'error' => 'Off-site storage is not configured.', 'items' => []]; }
    $items = []; $token = null;
    for ($page = 0; $page < 50; $page++) {
        $query = ['list-type' => '2', 'prefix' => $cfg['prefix'] !== '' ? $cfg['prefix'] . '/' : '', 'max-keys' => '1000'];
        if ($token !== null) { $query['continuation-token'] = $token; }
        [, $body, $err] = offsite_request($cfg, 'GET', '', $query, null, null, [], 60);
        if ($err !== '') { return ['ok' => false, 'error' => $err, 'items' => []]; }
        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) { return ['ok' => false, 'error' => 'The storage returned an unreadable listing.', 'items' => []]; }
        foreach ($xml->Contents ?? [] as $object) {
            $name = basename((string) $object->Key);
            if (!str_ends_with($name, '.enc') || !preg_match(BACKUP_NAME_PATTERN, substr($name, 0, -4))) { continue; }
            $items[] = ['file' => substr($name, 0, -4), 'size' => (int) $object->Size, 'mtime' => (int) strtotime((string) $object->LastModified),
                'local' => backup_resolve(substr($name, 0, -4)) !== null];
        }
        if ((string) ($xml->IsTruncated ?? '') !== 'true') { break; }
        $token = (string) $xml->NextContinuationToken;
    }
    usort($items, static fn($a, $b) => [$b['mtime'], $b['file']] <=> [$a['mtime'], $a['file']]);
    return ['ok' => true, 'items' => $items];
}

/** Download, decrypt and check an off-site archive into the local store so it can be restored. */
function offsite_fetch(string $file, ?callable $onOutput = null): array
{
    $cfg = offsite_config();
    if (!offsite_ready($cfg)) { return ['ok' => false, 'error' => 'Off-site storage is not configured.']; }
    $name = basename($file);
    if (!preg_match(BACKUP_NAME_PATTERN, $name)) { return ['ok' => false, 'error' => 'Invalid archive name.']; }
    if (backup_resolve($name) !== null) { return ['ok' => true, 'file' => $name, 'message' => 'This archive is already on the server.']; }
    $say = static function (string $text) use ($onOutput): void { if ($onOutput) { $onOutput('==> ' . $text . "\n", 'stdout'); } };
    [, $metaBody, $err] = offsite_request($cfg, 'GET', offsite_key($cfg, $name . '.manifest.json'), [], null, null, [], 60);
    $manifest = $err === '' ? json_decode($metaBody, true) : null;
    if (!is_array($manifest) || !preg_match('/^[a-f0-9]{64}$/', (string) ($manifest['sha256'] ?? ''))) { return ['ok' => false, 'error' => 'The archive’s manifest is missing in the bucket. ' . $err]; }
    $macKey = null;
    $legacy = (int) ($manifest['format'] ?? 1) < OFFSITE_FORMAT;
    if ($legacy) {
        if (isset(offsite_authenticated_names()[$name])) {
            return ['ok' => false, 'error' => 'The archive’s manifest in the bucket has lost its integrity signature. It was uploaded signed by this server, so it has been tampered with; it was not downloaded.'];
        }
    } else {
        $salt = base64_decode((string) ($manifest['mac_salt'] ?? ''), true);
        if ($salt === false || strlen($salt) < 16 || !preg_match('/^[a-f0-9]{64}$/', (string) ($manifest['mac'] ?? ''))) {
            return ['ok' => false, 'error' => 'The archive’s manifest is damaged.'];
        }
        $macKey = offsite_mac_key($cfg['passphrase'], $salt);
        if (!hash_equals(hash_hmac('sha256', offsite_manifest_canonical($manifest), $macKey), (string) ($manifest['manifest_mac'] ?? ''))) {
            return ['ok' => false, 'error' => 'The archive’s manifest does not verify with this passphrase: wrong passphrase, or it was altered in the bucket.'];
        }
    }
    $enc = backup_store() . '/.offsite-' . bin2hex(random_bytes(6)) . '.enc';
    $plain = backup_store() . '/.offsite-' . bin2hex(random_bytes(6)) . '.tar.gz';
    $say('Downloading ' . $name);
    [, , $err] = offsite_request($cfg, 'GET', offsite_key($cfg, $name . '.enc'), [], null, $enc);
    if ($err !== '') { @unlink($enc); return ['ok' => false, 'error' => $err]; }
    if ($macKey !== null) {
        $say('Verifying the download');
        if (!hash_equals((string) $manifest['mac'], (string) hash_hmac_file('sha256', $enc, $macKey))) {
            @unlink($enc);
            return ['ok' => false, 'error' => 'The downloaded archive does not match its signature; it was altered or damaged in the bucket, and was not decrypted.'];
        }
    }
    $say('Decrypting');
    $error = offsite_crypt(false, $enc, $plain, $cfg['passphrase']);
    @unlink($enc);
    if ($error !== null) { return ['ok' => false, 'error' => $error]; }
    $say('Checking the archive');
    if (!hash_equals((string) $manifest['sha256'], (string) hash_file('sha256', $plain))) { @unlink($plain); return ['ok' => false, 'error' => 'The downloaded archive does not match its checksum.']; }
    $dest = backup_store() . '/' . $name;
    if (!@rename($plain, $dest)) { @unlink($plain); return ['ok' => false, 'error' => 'Could not place the archive in the backup store.']; }
    unset($manifest['cipher'], $manifest['format'], $manifest['mac_salt'], $manifest['mac'], $manifest['manifest_mac']);
    $manifest['offsite_at'] = $manifest['offsite_at'] ?? date('c');
    write_json_file($dest . '.manifest.json', $manifest);
    audit('backup.offsite.fetch', $name . ($legacy ? ' (legacy, unsigned)' : ''));
    return ['ok' => true, 'file' => $name, 'message' => $legacy
        ? 'Downloaded and checked against its checksum. This archive predates signed off-site copies, so its origin cannot be proven; restore it only if you trust the bucket.'
        : 'Downloaded and verified. You can now restore it.'];
}

function offsite_delete(string $file, ?array $cfg = null): array
{
    $cfg = $cfg ?? offsite_config();
    $name = basename($file);
    if (!preg_match(BACKUP_NAME_PATTERN, $name)) { return ['ok' => false, 'error' => 'Invalid archive name.']; }
    [, , $err] = offsite_request($cfg, 'DELETE', offsite_key($cfg, $name . '.enc'), [], null, null, [], 60);
    if ($err !== '') { return ['ok' => false, 'error' => $err]; }
    offsite_request($cfg, 'DELETE', offsite_key($cfg, $name . '.manifest.json'), [], null, null, [], 60);
    audit('backup.offsite.delete', $name);
    return ['ok' => true];
}

/** Keep the newest $keep automatic copies per label (auto-* and system-*); manual uploads are kept. */
function offsite_apply_retention(array $cfg): array
{
    $list = offsite_list($cfg);
    if (empty($list['ok'])) { return []; }
    $groups = [];
    foreach ($list['items'] as $item) {
        if (preg_match('/^((?:auto-|system).*?)-[0-9]{8}-[0-9]{6}\.tar\.gz$/', $item['file'], $m)) { $groups[$m[1]][] = $item['file']; }
    }
    $deleted = [];
    foreach ($groups as $files) {
        foreach (array_slice($files, (int) $cfg['keep']) as $old) {
            if (!empty(offsite_delete($old, $cfg)['ok'])) { $deleted[] = $old; }
        }
    }
    return $deleted;
}

/** Upload freshly created archives (called after a scheduled run) and prune old copies. */
function offsite_sync(array $files, ?callable $onOutput = null): array
{
    $cfg = offsite_config();
    if (empty($cfg['enabled']) || !offsite_ready($cfg)) { return ['ok' => true, 'skipped' => true]; }
    $uploaded = []; $failed = [];
    foreach ($files as $file) {
        $res = offsite_upload((string) $file, $onOutput, $cfg);
        if (!empty($res['ok'])) { $uploaded[] = $file; } else { $failed[] = ['file' => $file, 'error' => (string) ($res['error'] ?? 'failed')]; }
    }
    $pruned = $failed ? [] : offsite_apply_retention($cfg);
    $result = ['at' => time(), 'ok' => !$failed, 'uploaded' => count($uploaded), 'failed' => $failed, 'pruned' => count($pruned)];
    $saved = @json_decode((string) @file_get_contents(offsite_file()), true);
    if (is_array($saved)) { $saved['last_run'] = time(); $saved['last_result'] = $result; write_json_file(offsite_file(), $saved); }
    return $result + ($failed ? ['error' => 'Off-site upload failed for ' . $failed[0]['file'] . ': ' . $failed[0]['error']] : []);
}

/** Dashboard/alert items. */
function offsite_health_items(): array
{
    $cfg = offsite_public();
    if (empty($cfg['enabled'])) { return []; }
    $last = $cfg['last_result'] ?? null;
    if (is_array($last) && empty($last['ok'])) {
        return [['level' => 'critical', 'key' => 'offsite_failed', 'title' => 'Off-site backup upload failed',
            'detail' => (string) ($last['failed'][0]['error'] ?? 'See Backups'), 'route' => 'backups', 'icon' => 'cloud-off']];
    }
    return [];
}

/** Back up the server configuration (not website files or mailboxes). */
function system_backup_create(?callable $onOutput = null, bool $scheduled = false): array
{
    $name = 'system-' . date('Ymd-His') . '.tar.gz';
    if (file_exists(backup_store() . '/' . $name)) { return ['ok' => false, 'error' => 'A system backup was just created; wait a second and retry.']; }
    [$code, $out] = $onOutput ? helper_cmd_stream('system-backup ' . escapeshellarg($name), $onOutput, 1800) : helper_cmd('system-backup ' . escapeshellarg($name), 1800);
    if ($code !== 0) { return ['ok' => false, 'error' => trim(preg_replace('/^==> .*$/m', '', (string) $out) ?? '') ?: 'System backup failed.']; }
    $meta = ['site_id' => '', 'domain' => '', 'file' => $name, 'created_at' => date('c'), 'kind' => 'system', 'scheduled' => $scheduled];
    foreach (preg_split('/\r?\n/', trim((string) $out)) as $line) {
        if (preg_match('/^(sha256|bytes|entries|paths)=(.*)$/', trim($line), $m)) { $meta[$m[1]] = $m[2]; }
    }
    write_json_file(backup_store() . '/' . $name . '.manifest.json', $meta);
    audit('backup.system', $name);
    if ($scheduled) {
        $keep = max(1, (int) backup_schedule()['keep']);
        $old = array_values(array_filter(backup_list(), static fn($b) => $b['kind'] === 'system' && $b['scheduled']));
        foreach (array_slice($old, $keep) as $stale) { backup_delete($stale['file']); }
    }
    return ['ok' => true, 'file' => $name];
}
