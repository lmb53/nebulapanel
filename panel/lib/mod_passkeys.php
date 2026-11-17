<?php
/**
 * Passkeys (WebAuthn) as a second sign-in factor next to the authenticator
 * app. They live in the user's mfa record, so turning two-factor sign-in off
 * (or an administrator reset) removes them with the app secret and the
 * recovery codes.
 *
 * Attestation is not requested ("none"): the panel trusts the key the
 * person registers while signed in and re-authenticated, which is the
 * standard for passkeys. Supported algorithms: ES256, EdDSA (Ed25519), RS256.
 */

require_once APP_ROOT . '/lib/mod_mfa.php';

const PASSKEY_MAX = 10;
const PASSKEY_CHALLENGE_TTL = 300;

function b64url_encode(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }

function b64url_decode(string $text): ?string
{
    if (!preg_match('/^[A-Za-z0-9_-]*$/', $text)) { return null; }
    $raw = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    return $raw === false ? null : $raw;
}

// ---- Relying party ----------------------------------------------------------

/** The relying-party ID (the panel's host name), or null when WebAuthn cannot work here (IP addresses). */
function passkey_rp_id(): ?string
{
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === 'localhost') { return $host; }
    if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || !preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/', $host)) { return null; }
    return $host;
}

function passkey_origin(): string
{
    $https = defined('REQUEST_HTTPS') ? REQUEST_HTTPS : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return ($https ? 'https' : 'http') . '://' . strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
}

function passkey_supported(): bool
{
    $rp = passkey_rp_id();
    return $rp !== null && ($rp === 'localhost' || str_starts_with(passkey_origin(), 'https://'));
}

// ---- CBOR (the subset WebAuthn uses) -----------------------------------------

/** Decode one CBOR item at $offset; advances $offset. Throws on malformed input. */
function cbor_decode(string $data, int &$offset = 0, int $depth = 0)
{
    if ($depth > 16) { throw new RuntimeException('CBOR nesting too deep'); }
    if ($offset >= strlen($data)) { throw new RuntimeException('CBOR truncated'); }
    $initial = ord($data[$offset++]);
    $major = $initial >> 5;
    $info = $initial & 0x1f;
    if ($major === 7) {
        return match ($info) { 20 => false, 21 => true, 22, 23 => null, default => throw new RuntimeException('Unsupported CBOR simple value') };
    }
    if ($info < 24) { $value = $info; }
    elseif ($info >= 24 && $info <= 27) {
        $len = 1 << ($info - 24);
        if ($offset + $len > strlen($data)) { throw new RuntimeException('CBOR truncated'); }
        $value = 0;
        for ($i = 0; $i < $len; $i++) { $value = ($value << 8) | ord($data[$offset + $i]); }
        $offset += $len;
        if ($value < 0) { throw new RuntimeException('CBOR integer too large'); }
    } else { throw new RuntimeException('Indefinite-length CBOR is not supported'); }
    switch ($major) {
        case 0: return $value;
        case 1: return -1 - $value;
        case 2:
        case 3:
            if ($value > strlen($data) - $offset) { throw new RuntimeException('CBOR truncated'); }
            $out = substr($data, $offset, $value); $offset += $value;
            return $out;
        case 4:
            if ($value > 1000) { throw new RuntimeException('CBOR array too long'); }
            $arr = [];
            for ($i = 0; $i < $value; $i++) { $arr[] = cbor_decode($data, $offset, $depth + 1); }
            return $arr;
        case 5:
            if ($value > 1000) { throw new RuntimeException('CBOR map too long'); }
            $map = [];
            for ($i = 0; $i < $value; $i++) {
                $key = cbor_decode($data, $offset, $depth + 1);
                if (!is_int($key) && !is_string($key)) { throw new RuntimeException('Unsupported CBOR map key'); }
                $map[$key] = cbor_decode($data, $offset, $depth + 1);
            }
            return $map;
        default: throw new RuntimeException('Unsupported CBOR type');
    }
}

// ---- Keys ---------------------------------------------------------------------

function der_length(int $len): string
{
    if ($len < 0x80) { return chr($len); }
    $bytes = ltrim(pack('N', $len), "\0");
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function der_item(int $tag, string $content): string { return chr($tag) . der_length(strlen($content)) . $content; }

function der_uint(string $bytes): string
{
    $bytes = ltrim($bytes, "\0");
    if ($bytes === '' || (ord($bytes[0]) & 0x80)) { $bytes = "\0" . $bytes; }
    return der_item(0x02, $bytes);
}

/**
 * Turn a COSE public key into what the verifier needs:
 * ['alg' => -7|-257|-8, 'pem' => ...] or ['alg' => -8, 'raw' => b64].
 */
function passkey_cose_to_key(array $cose): array
{
    $kty = $cose[1] ?? null; $alg = $cose[3] ?? null;
    if ($kty === 2 && $alg === -7 && ($cose[-1] ?? null) === 1 && strlen((string) ($cose[-2] ?? '')) === 32 && strlen((string) ($cose[-3] ?? '')) === 32) {
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004') . $cose[-2] . $cose[-3];
        return ['alg' => -7, 'pem' => "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n"];
    }
    if ($kty === 3 && $alg === -257 && is_string($cose[-1] ?? null) && is_string($cose[-2] ?? null) && strlen($cose[-1]) >= 256) {
        $rsaKey = der_item(0x30, der_uint($cose[-1]) . der_uint($cose[-2]));
        $algId = der_item(0x30, der_item(0x06, hex2bin('2a864886f70d010101')) . "\x05\x00");
        $spki = der_item(0x30, $algId . der_item(0x03, "\0" . $rsaKey));
        return ['alg' => -257, 'pem' => "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n"];
    }
    if ($kty === 1 && $alg === -8 && ($cose[-1] ?? null) === 6 && strlen((string) ($cose[-2] ?? '')) === 32 && function_exists('sodium_crypto_sign_verify_detached')) {
        return ['alg' => -8, 'raw' => base64_encode($cose[-2])];
    }
    throw new RuntimeException('This passkey uses an unsupported key type.');
}

function passkey_verify_signature(array $key, string $signed, string $signature): bool
{
    if (($key['alg'] ?? 0) === -8) {
        $raw = base64_decode((string) ($key['raw'] ?? ''), true);
        return $raw !== false && strlen($signature) === 64 && sodium_crypto_sign_verify_detached($signature, $signed, $raw);
    }
    $pub = openssl_pkey_get_public((string) ($key['pem'] ?? ''));
    return $pub !== false && openssl_verify($signed, $signature, $pub, OPENSSL_ALGO_SHA256) === 1;
}

// ---- Authenticator data and client data ---------------------------------------------

/** Parse authenticatorData; with $withCredential also the attested credential (registration). */
function passkey_parse_auth_data(string $auth, bool $withCredential): array
{
    if (strlen($auth) < 37) { throw new RuntimeException('Authenticator data is too short.'); }
    $out = ['rp_hash' => substr($auth, 0, 32), 'flags' => ord($auth[32]), 'count' => unpack('N', substr($auth, 33, 4))[1]];
    if ($withCredential) {
        if (!($out['flags'] & 0x40) || strlen($auth) < 55) { throw new RuntimeException('No credential in the authenticator response.'); }
        $len = unpack('n', substr($auth, 53, 2))[1];
        if ($len < 16 || $len > 1023 || strlen($auth) < 55 + $len) { throw new RuntimeException('Invalid credential ID.'); }
        $out['cred_id'] = substr($auth, 55, $len);
        $offset = 55 + $len;
        $cose = cbor_decode($auth, $offset);
        if (!is_array($cose)) { throw new RuntimeException('Invalid public key.'); }
        $out['key'] = passkey_cose_to_key($cose);
    }
    return $out;
}

/** Check clientDataJSON against the expected ceremony, challenge and origin. */
function passkey_check_client_data(string $json, string $type, string $challenge): ?string
{
    $client = json_decode($json, true);
    if (!is_array($client)) { return 'The browser response could not be read.'; }
    if (($client['type'] ?? '') !== $type) { return 'Unexpected passkey response.'; }
    $got = b64url_decode((string) ($client['challenge'] ?? ''));
    if ($got === null || !hash_equals($challenge, $got)) { return 'The passkey request expired. Try again.'; }
    if (($client['origin'] ?? '') !== passkey_origin()) { return 'The passkey was used from a different address (' . (string) ($client['origin'] ?? '?') . ').'; }
    if (!empty($client['crossOrigin'])) { return 'Cross-origin passkey requests are not allowed.'; }
    return null;
}

/** Shared flag checks: the right site, and a person was present. */
function passkey_check_flags(array $auth): ?string
{
    if (!hash_equals(hash('sha256', (string) passkey_rp_id(), true), $auth['rp_hash'])) { return 'The passkey belongs to a different site.'; }
    if (!($auth['flags'] & 0x01)) { return 'The passkey did not confirm that you were present.'; }
    return null;
}

// ---- Registration ----------------------------------------------------------------

function passkey_list(array $user): array
{
    return array_map(static fn($k) => ['id' => $k['id'], 'name' => $k['name'], 'created_at' => $k['created_at'], 'last_used_at' => $k['last_used_at'] ?? null],
        array_values((array) ($user['mfa']['passkeys'] ?? [])));
}

/** Step 1 of adding a passkey, after the password and an app code were re-checked. */
function passkey_register_options(int $uid, string $password, string $code): array
{
    if (!passkey_supported()) { return ['ok' => false, 'error' => 'Passkeys need the panel to be opened by its host name over HTTPS (not an IP address).']; }
    $options = null;
    $res = mfa_update_user($uid, static function (array &$user) use ($password, $code, &$options) {
        if (!mfa_user_enabled($user)) { return 'Turn on two-factor sign-in with an authenticator app first; passkeys are added next to it.'; }
        if (($error = mfa_reauth($user, $password, $code)) !== null) { return $error; }
        if (count((array) ($user['mfa']['passkeys'] ?? [])) >= PASSKEY_MAX) { return 'You already have ' . PASSKEY_MAX . ' passkeys. Remove one first.'; }
        $challenge = random_bytes(32);
        // API requests release the session early; write the challenge back explicitly.
        session_persist(['passkey_register' => ['challenge' => $challenge, 'at' => time(), 'uid' => (int) $user['id']]]);
        $options = [
            'challenge' => b64url_encode($challenge),
            'rp' => ['id' => passkey_rp_id(), 'name' => 'Nebula Panel'],
            'user' => ['id' => b64url_encode(hash('sha256', 'nebula-user:' . $user['id'], true)), 'name' => (string) $user['username'], 'displayName' => (string) $user['username']],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -8], ['type' => 'public-key', 'alg' => -257]],
            'excludeCredentials' => array_map(static fn($k) => ['type' => 'public-key', 'id' => $k['id']], array_values((array) ($user['mfa']['passkeys'] ?? []))),
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'preferred'],
            'attestation' => 'none',
            'timeout' => PASSKEY_CHALLENGE_TTL * 1000,
        ];
        return null;
    });
    if (empty($res['ok'])) { usleep(300000); return $res; }
    return ['ok' => true, 'options' => $options];
}

/** Step 2: verify the browser's response and store the key. */
function passkey_register_finish(int $uid, string $name, string $clientDataB64, string $attestationB64): array
{
    $pending = $_SESSION['passkey_register'] ?? null;
    session_persist(['passkey_register' => null]); // single use
    if (!is_array($pending) || (int) $pending['uid'] !== $uid || time() - (int) $pending['at'] > PASSKEY_CHALLENGE_TTL) {
        return ['ok' => false, 'error' => 'The passkey request expired. Try again.'];
    }
    $name = trim($name) !== '' ? trim($name) : 'Passkey';
    if (strlen($name) > 60 || preg_match('/[\x00-\x1F\x7F]/', $name)) { return ['ok' => false, 'error' => 'Use a name of up to 60 characters.']; }
    $clientData = b64url_decode($clientDataB64); $attestation = b64url_decode($attestationB64);
    if ($clientData === null || $attestation === null || strlen($attestation) > 16384 || strlen($clientData) > 4096) { return ['ok' => false, 'error' => 'The browser response could not be read.']; }
    if (($error = passkey_check_client_data($clientData, 'webauthn.create', (string) $pending['challenge'])) !== null) { return ['ok' => false, 'error' => $error]; }
    try {
        $offset = 0;
        $object = cbor_decode($attestation, $offset);
        if (!is_array($object) || !is_string($object['authData'] ?? null)) { throw new RuntimeException('Invalid attestation.'); }
        $auth = passkey_parse_auth_data($object['authData'], true);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'The passkey response is malformed.'];
    }
    if (($error = passkey_check_flags($auth)) !== null) { return ['ok' => false, 'error' => $error]; }
    $record = ['id' => b64url_encode($auth['cred_id']), 'name' => $name, 'key' => $auth['key'], 'count' => (int) $auth['count'], 'created_at' => date('c'), 'last_used_at' => null];
    $res = mfa_update_user($uid, static function (array &$user) use ($record) {
        if (!mfa_user_enabled($user)) { return 'Two-factor sign-in was turned off.'; }
        foreach ((array) ($user['mfa']['passkeys'] ?? []) as $existing) {
            if (hash_equals((string) $existing['id'], $record['id'])) { return 'This passkey is already registered.'; }
        }
        if (count((array) ($user['mfa']['passkeys'] ?? [])) >= PASSKEY_MAX) { return 'Too many passkeys.'; }
        $user['mfa']['passkeys'][] = $record;
        return ['passkeys' => passkey_list($user)];
    });
    if (!empty($res['ok'])) { audit('mfa.passkey_add', $name); }
    return $res;
}

function passkey_delete(int $uid, string $id, string $password, string $code): array
{
    $res = mfa_update_user($uid, static function (array &$user) use ($id, $password, $code) {
        if (($error = mfa_reauth($user, $password, $code)) !== null) { return $error; }
        $before = (array) ($user['mfa']['passkeys'] ?? []);
        $user['mfa']['passkeys'] = array_values(array_filter($before, static fn($k) => !hash_equals((string) $k['id'], $id)));
        if (count($before) === count($user['mfa']['passkeys'])) { return 'Passkey not found.'; }
        return ['passkeys' => passkey_list($user)];
    });
    if (empty($res['ok'])) { usleep(300000); return $res; }
    audit('mfa.passkey_remove');
    return $res;
}

// ---- Sign-in ------------------------------------------------------------------------

/** Options for the second sign-in step, or null when the account has no passkeys. */
function passkey_login_options(int $uid): ?array
{
    if (!passkey_supported()) { return null; }
    foreach (panel_users() as $user) {
        if ((int) ($user['id'] ?? 0) !== $uid) { continue; }
        $keys = (array) ($user['mfa']['passkeys'] ?? []);
        if (!$keys) { return null; }
        $challenge = random_bytes(32);
        $_SESSION['mfa_pending']['passkey_challenge'] = b64url_encode($challenge);
        $_SESSION['mfa_pending']['passkey_at'] = time();
        return ['challenge' => b64url_encode($challenge), 'rpId' => passkey_rp_id(), 'userVerification' => 'preferred',
            'timeout' => PASSKEY_CHALLENGE_TTL * 1000, 'allowCredentials' => array_map(static fn($k) => ['type' => 'public-key', 'id' => $k['id']], array_values($keys))];
    }
    return null;
}

/** Verify a sign-in assertion; returns the fresh user record like mfa_login_verify(). */
function passkey_login_verify(int $uid, int $expectedVersion, array $response): array
{
    $pending = $_SESSION['mfa_pending'] ?? [];
    $challenge = b64url_decode((string) ($pending['passkey_challenge'] ?? ''));
    unset($_SESSION['mfa_pending']['passkey_challenge']);
    if ($challenge === null || $challenge === '' || time() - (int) ($pending['passkey_at'] ?? 0) > PASSKEY_CHALLENGE_TTL) {
        return ['ok' => false, 'error' => 'The passkey request expired. Try again.'];
    }
    $credId = (string) ($response['id'] ?? '');
    $clientData = b64url_decode((string) ($response['clientDataJSON'] ?? ''));
    $authData = b64url_decode((string) ($response['authenticatorData'] ?? ''));
    $signature = b64url_decode((string) ($response['signature'] ?? ''));
    if ($clientData === null || $authData === null || $signature === null || strlen($clientData) > 4096 || strlen($authData) > 4096 || strlen($signature) > 1024) {
        return ['ok' => false, 'error' => 'The browser response could not be read.'];
    }
    if (($error = passkey_check_client_data($clientData, 'webauthn.get', $challenge)) !== null) { return ['ok' => false, 'error' => $error]; }
    try { $auth = passkey_parse_auth_data($authData, false); } catch (Throwable) { return ['ok' => false, 'error' => 'The passkey response is malformed.']; }
    if (($error = passkey_check_flags($auth)) !== null) { return ['ok' => false, 'error' => $error]; }
    return mfa_update_user($uid, static function (array &$user) use ($expectedVersion, $credId, $authData, $clientData, $signature, $auth) {
        if (empty($user['enabled']) || (int) ($user['session_version'] ?? 1) !== $expectedVersion || !mfa_user_enabled($user)) { return 'Sign in again.'; }
        foreach ((array) ($user['mfa']['passkeys'] ?? []) as $i => $key) {
            if (!hash_equals((string) $key['id'], $credId)) { continue; }
            if (!passkey_verify_signature((array) $key['key'], $authData . hash('sha256', $clientData, true), $signature)) { return 'The passkey signature is not valid.'; }
            // A counter that does not move forward means the key may have been cloned.
            if (((int) $key['count'] > 0 || $auth['count'] > 0) && $auth['count'] <= (int) $key['count']) { return 'This passkey reported an old use counter and was refused.'; }
            $user['mfa']['passkeys'][$i]['count'] = $auth['count'];
            $user['mfa']['passkeys'][$i]['last_used_at'] = date('c');
            $user['last_login'] = date('c');
            return ['user' => $user, 'passkey' => (string) $key['name']];
        }
        return 'This passkey is not registered to your account.';
    });
}
