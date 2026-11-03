<?php
/**
 * Two-factor sign-in: RFC 6238 time-based one-time passwords (Google
 * Authenticator, 1Password, Authy, Bitwarden…) plus single-use recovery
 * codes. State lives on the panel user record:
 *
 *   mfa         => [secret, enabled_at, last_step, recovery => [sha256…]]
 *   mfa_pending => [secret, created]   (between "Set up" and "Confirm")
 */

const MFA_PERIOD = 30;
const MFA_DIGITS = 6;
const MFA_DRIFT_STEPS = 1;
const MFA_RECOVERY_COUNT = 10;
const MFA_PENDING_TTL = 600;
const MFA_LOGIN_TTL = 300;
const MFA_LOGIN_MAX_TRIES = 5;

function mfa_base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $char) { $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT); }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) { $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))]; }
    return $out;
}

function mfa_base32_decode(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text = strtoupper(preg_replace('/[\s=-]+/', '', $text) ?? '');
    $bits = '';
    foreach (str_split($text) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) { return ''; }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) { $out .= chr(bindec($byte)); }
    }
    return $out;
}

function mfa_new_secret(): string
{
    return mfa_base32_encode(random_bytes(20)); // 160 bits, RFC 4226 recommendation
}

/** The code for one 30-second time step. */
function mfa_code(string $secret, int $step): string
{
    $key = mfa_base32_decode($secret);
    $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $counter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($value % (10 ** MFA_DIGITS)), MFA_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Check a code within ±1 step of now. Returns the matched step, or null.
 * A step at or before $lastStep is rejected so a code cannot be replayed.
 */
function mfa_verify_code(string $secret, string $code, int $lastStep = 0, ?int $now = null): ?int
{
    $code = preg_replace('/\s+/', '', $code) ?? '';
    if (!preg_match('/^\d{' . MFA_DIGITS . '}$/', $code) || $secret === '') { return null; }
    $current = intdiv($now ?? time(), MFA_PERIOD);
    for ($step = $current - MFA_DRIFT_STEPS; $step <= $current + MFA_DRIFT_STEPS; $step++) {
        if ($step > $lastStep && hash_equals(mfa_code($secret, $step), $code)) { return $step; }
    }
    return null;
}

function mfa_otpauth_uri(string $secret, string $username): string
{
    global $config;
    $issuer = (string) ($config['panel_name'] ?? 'Nebula Panel');
    $host = gethostname() ?: '';
    $label = rawurlencode($issuer) . ':' . rawurlencode($username . ($host !== '' ? '@' . $host : ''));
    return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=' . MFA_DIGITS . '&period=' . MFA_PERIOD;
}

/** Fresh recovery codes: [plain codes for display, hashes for storage]. */
function mfa_new_recovery_codes(): array
{
    $plain = [];
    $hashes = [];
    for ($i = 0; $i < MFA_RECOVERY_COUNT; $i++) {
        $raw = strtolower(mfa_base32_encode(random_bytes(7))); // 56 bits → 12 chars
        $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 3);
        $plain[] = $code;
        $hashes[] = mfa_recovery_hash($code);
    }
    return [$plain, $hashes];
}

function mfa_recovery_hash(string $code): string
{
    return hash('sha256', 'nebula-recovery:' . strtolower(preg_replace('/[^a-z0-9]/i', '', $code) ?? ''));
}

function mfa_user_enabled(array $user): bool
{
    return !empty($user['mfa']['secret']) && !empty($user['mfa']['enabled_at']);
}

/** Public status for the account page. */
function mfa_status(int $uid): array
{
    foreach (panel_users() as $user) {
        if ((int) ($user['id'] ?? 0) !== $uid) { continue; }
        return [
            'enabled' => mfa_user_enabled($user),
            'enabled_at' => (string) ($user['mfa']['enabled_at'] ?? ''),
            'recovery_left' => count((array) ($user['mfa']['recovery'] ?? [])),
        ];
    }
    return ['enabled' => false, 'enabled_at' => '', 'recovery_left' => 0];
}

/** Update one panel user under the store lock. $mutator returns an error string or null. */
function mfa_update_user(int $uid, callable $mutator): array
{
    return with_panel_users_lock(static function () use ($uid, $mutator): array {
        $users = panel_users();
        foreach ($users as $i => $user) {
            if ((int) ($user['id'] ?? 0) !== $uid) { continue; }
            $result = $mutator($users[$i]);
            if (is_string($result)) { return ['ok' => false, 'error' => $result]; }
            if (!save_panel_users($users)) { return ['ok' => false, 'error' => 'Could not save the panel user store.']; }
            return ['ok' => true] + (is_array($result) ? $result : []);
        }
        return ['ok' => false, 'error' => 'Panel user not found.'];
    });
}

/** Step 1 of setup: create a pending secret and return what the app needs. */
function mfa_begin_setup(int $uid, string $username): array
{
    require_once APP_ROOT . '/lib/qrcode.php';
    $secret = mfa_new_secret();
    $res = mfa_update_user($uid, static function (array &$user) use ($secret) {
        if (mfa_user_enabled($user)) { return 'Two-factor sign-in is already on.'; }
        $user['mfa_pending'] = ['secret' => $secret, 'created' => time()];
        return null;
    });
    if (empty($res['ok'])) { return $res; }
    $uri = mfa_otpauth_uri($secret, $username);
    return ['ok' => true, 'secret' => trim(chunk_split($secret, 4, ' ')), 'uri' => $uri,
        'qr_svg' => NebulaQr::svg($uri, 4, 'Scan with your authenticator app')];
}

/** Step 2: confirm the first code; turns 2FA on and returns recovery codes once. */
function mfa_confirm_setup(int $uid, string $code): array
{
    [$plain, $hashes] = mfa_new_recovery_codes();
    $res = mfa_update_user($uid, static function (array &$user) use ($code, $hashes) {
        $pending = $user['mfa_pending'] ?? null;
        if (!is_array($pending) || time() - (int) ($pending['created'] ?? 0) > MFA_PENDING_TTL) {
            return 'The setup expired. Start again.';
        }
        $step = mfa_verify_code((string) $pending['secret'], $code);
        if ($step === null) { return 'That code is not valid. Check the time on your phone and try the next code.'; }
        $user['mfa'] = ['secret' => (string) $pending['secret'], 'enabled_at' => date('c'), 'last_step' => $step, 'recovery' => $hashes];
        unset($user['mfa_pending']);
        return null;
    });
    if (empty($res['ok'])) { usleep(300000); return $res; }
    audit('mfa.enable');
    return ['ok' => true, 'recovery_codes' => $plain];
}

/** Check the account password (and, when 2FA is on, a code) for sensitive changes. */
function mfa_reauth(array &$user, string $password, string $code): ?string
{
    if (empty($user['hash']) || !password_verify($password, (string) $user['hash'])) { return 'Your password is incorrect.'; }
    if (mfa_user_enabled($user) && mfa_check_and_consume($user, $code) === null) {
        return 'The authentication code is not valid.';
    }
    return null;
}

/**
 * Verify a TOTP or recovery code against a user record that is being updated
 * under lock. Consumes recovery codes and advances last_step. Returns
 * 'totp', 'recovery', or null.
 */
function mfa_check_and_consume(array &$user, string $code): ?string
{
    $secret = (string) ($user['mfa']['secret'] ?? '');
    $step = mfa_verify_code($secret, $code, (int) ($user['mfa']['last_step'] ?? 0));
    if ($step !== null) {
        $user['mfa']['last_step'] = $step;
        return 'totp';
    }
    $hash = mfa_recovery_hash($code);
    $codes = (array) ($user['mfa']['recovery'] ?? []);
    foreach ($codes as $i => $stored) {
        if (preg_match('/^[a-z0-9]{4}-?[a-z0-9]{4}-?[a-z0-9]{3}$/i', trim($code)) && hash_equals((string) $stored, $hash)) {
            unset($codes[$i]);
            $user['mfa']['recovery'] = array_values($codes);
            return 'recovery';
        }
    }
    return null;
}

function mfa_disable(int $uid, string $password, string $code): array
{
    $res = mfa_update_user($uid, static function (array &$user) use ($password, $code) {
        if (!mfa_user_enabled($user)) { return 'Two-factor sign-in is not on.'; }
        if (($error = mfa_reauth($user, $password, $code)) !== null) { return $error; }
        unset($user['mfa'], $user['mfa_pending']);
        return null;
    });
    if (empty($res['ok'])) { usleep(300000); return $res; }
    audit('mfa.disable');
    return ['ok' => true];
}

function mfa_regenerate_recovery(int $uid, string $password, string $code): array
{
    [$plain, $hashes] = mfa_new_recovery_codes();
    $res = mfa_update_user($uid, static function (array &$user) use ($password, $code, $hashes) {
        if (!mfa_user_enabled($user)) { return 'Two-factor sign-in is not on.'; }
        if (($error = mfa_reauth($user, $password, $code)) !== null) { return $error; }
        $user['mfa']['recovery'] = $hashes;
        return null;
    });
    if (empty($res['ok'])) { usleep(300000); return $res; }
    audit('mfa.recovery_regenerated');
    return ['ok' => true, 'recovery_codes' => $plain];
}

/** Administrator reset for a locked-out user (Users page). */
function mfa_admin_reset(int $uid): array
{
    $res = mfa_update_user($uid, static function (array &$user) {
        if (!mfa_user_enabled($user) && empty($user['mfa_pending'])) { return 'Two-factor sign-in is not on for this user.'; }
        unset($user['mfa'], $user['mfa_pending']);
        $user['session_version'] = max(1, (int) ($user['session_version'] ?? 1)) + 1;
        return null;
    });
    if (!empty($res['ok'])) { audit('mfa.admin_reset', 'id ' . $uid); }
    return $res;
}

/**
 * Second sign-in step. Returns the fresh user record on success, or an
 * error string. Throttled per account and address by the caller.
 */
function mfa_login_verify(int $uid, int $expectedVersion, string $code): array
{
    $method = null;
    $res = mfa_update_user($uid, static function (array &$user) use ($code, $expectedVersion, &$method) {
        if (empty($user['enabled']) || (int) ($user['session_version'] ?? 1) !== $expectedVersion) { return 'Sign in again.'; }
        if (!mfa_user_enabled($user)) { return 'Sign in again.'; }
        $method = mfa_check_and_consume($user, $code);
        if ($method === null) { return 'That code is not valid.'; }
        $user['last_login'] = date('c');
        return ['user' => $user];
    });
    if (!empty($res['ok'])) { $res['method'] = $method; }
    return $res;
}
