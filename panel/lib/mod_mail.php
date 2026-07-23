<?php
/**
 * Email module — virtual mail domains, mailboxes and aliases served by a
 * Postfix + Dovecot + OpenDKIM stack, plus a one-click Roundcube webmail.
 *
 * Design: the panel owns all metadata in data/mail.json. Every change is
 * pushed to the running MTA in one shot via `nebula-helper mail-apply`, which
 * regenerates the Postfix/Dovecot maps and OpenDKIM tables from scratch — so
 * the panel's view and the live server can never drift apart. Passwords are
 * hashed to SHA-512 crypt here and only the hash ever leaves the panel; the
 * plaintext is never written to disk or passed on a command line.
 */

/** Path to the mail state file. */
function mail_file(): string
{
    return DATA_DIR . '/mail.json';
}

/** Decoded state, normalised to the expected shape. */
function mail_state(): array
{
    $s = json_decode((string) @file_get_contents(mail_file()), true);
    if (!is_array($s)) {
        $s = [];
    }
    $s['domains']   = is_array($s['domains'] ?? null) ? $s['domains'] : [];
    $s['accounts']  = is_array($s['accounts'] ?? null) ? array_values($s['accounts']) : [];
    $s['aliases']   = is_array($s['aliases'] ?? null) ? array_values($s['aliases']) : [];
    $s['roundcube'] = is_array($s['roundcube'] ?? null) ? $s['roundcube'] : null;
    $s['webmail']   = is_array($s['webmail'] ?? null) ? $s['webmail'] : null;
    return $s;
}

function mail_save(array $state): bool
{
    return write_json_file(mail_file(), $state, 0600);
}

/** Ordered list of configured mail domains. */
function mail_domains(): array
{
    return array_keys(mail_state()['domains']);
}

/** Live status of the mail stack (services, hostname, public IP). */
function mail_status(): array
{
    $status = [
        'installed' => false,
        'postfix'   => 'unknown',
        'dovecot'   => 'unknown',
        'opendkim'  => 'unknown',
        'hostname'  => (string) (gethostname() ?: ''),
        'ip'        => mail_server_ip(),
        'helper'    => helper_available(),
    ];
    if (!helper_available()) {
        return $status;
    }
    [$code, $out] = helper_cmd('mail-status', 15);
    if ($code === 0) {
        foreach (explode("\n", trim($out)) as $line) {
            $kv = explode('=', $line, 2);
            if (count($kv) === 2 && array_key_exists($kv[0], $status)) {
                $status[$kv[0]] = $kv[0] === 'installed' ? ($kv[1] === 'yes') : $kv[1];
            }
        }
    }
    return $status;
}

/** Best-effort primary public IPv4 of this host. */
function mail_server_ip(): string
{
    [$code, $out] = run_cmd('hostname -I 2>/dev/null');
    if ($code === 0) {
        foreach (preg_split('/\s+/', trim($out)) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }
    }
    return '';
}

/** Human-readable login/auth diagnostics from the mail server. */
function mail_diag(): array
{
    // Panel-side state first — reveals whether the passdb file being empty is
    // because the panel has no account, or because mail-apply skipped/dropped
    // it (e.g. an invalid stored hash) when pushing to Dovecot.
    $state = mail_state();
    $lines = ["== panel state (data/mail.json) =="];
    $lines[] = 'domains: ' . (implode(', ', array_keys($state['domains'])) ?: '(none)');
    $lines[] = 'mailboxes: ' . count($state['accounts']);
    foreach ($state['accounts'] as $a) {
        $email  = (string) ($a['email'] ?? '');
        $hash   = (string) ($a['hash'] ?? '');
        $domain = strpos($email, '@') !== false ? substr($email, strpos($email, '@') + 1) : '';
        $hashOk = (bool) preg_match('/^\{SHA512-CRYPT\}\$6\$[.\/A-Za-z0-9]{1,32}\$[.\/A-Za-z0-9]{1,200}$/', $hash);
        $domOk  = $domain !== '' && isset($state['domains'][$domain]);
        $notes  = [];
        if (!$hashOk) { $notes[] = 'BAD HASH (would be skipped)'; }
        if (!$domOk)  { $notes[] = 'domain not in list (would be skipped)'; }
        $lines[] = '  - ' . ($email ?: '(no email)') . '  ' . ($notes ? '<< ' . implode('; ', $notes) : 'ok');
    }
    $summary = implode("\n", $lines) . "\n\n";

    if (!helper_available()) {
        return ['ok' => true, 'output' => $summary . 'Privileged helper not installed — re-run install.sh for the rest.'];
    }
    [$code, $out] = helper_cmd('mail-diag', 30);
    $out = trim($out);
    if ($out === '') {
        $out = 'Could not run server-side mail diagnostics. Re-run install.sh to update the helper.';
    }
    return ['ok' => true, 'output' => $summary . $out];
}

/** Install the mail stack (streamed). */
function mail_setup(?callable $onOutput = null): array
{
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed. Re-run install.sh.'];
    }
    [$code, $out] = $onOutput
        ? helper_cmd_stream('mail-setup', $onOutput, 900)
        : helper_cmd('mail-setup', 900);
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    audit('mail.setup');
    // Push whatever is already configured so the maps exist immediately.
    mail_apply();
    return ['ok' => true];
}

/**
 * Regenerate the running MTA from panel state via the helper. Called after
 * every domain/account/alias change.
 */
function mail_apply(): array
{
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed.'];
    }
    $state = mail_state();
    $domains = implode("\n", array_map('strval', array_keys($state['domains'])));
    $accounts = [];
    foreach ($state['accounts'] as $a) {
        $email = (string) ($a['email'] ?? '');
        $hash  = (string) ($a['hash'] ?? '');
        if ($email !== '' && $hash !== '') {
            $accounts[] = $email . "\t" . $hash;
        }
    }
    $aliases = [];
    foreach ($state['aliases'] as $a) {
        $from = (string) ($a['from'] ?? '');
        $to   = (string) ($a['to'] ?? '');
        if ($from !== '' && $to !== '') {
            $aliases[] = $from . "\t" . $to;
        }
    }
    $args = 'mail-apply '
        . escapeshellarg(base64_encode($domains)) . ' '
        . escapeshellarg(base64_encode(implode("\n", $accounts))) . ' '
        . escapeshellarg(base64_encode(implode("\n", $aliases)));
    [$code, $out] = helper_cmd($args, 120);
    return $code === 0 ? ['ok' => true] : ['ok' => false, 'error' => sudo_error($out, $code)];
}

/** SHA-512 crypt hash in the scheme Dovecot's passwd-file expects. */
function mail_hash_password(string $password): string
{
    $salt = '$6$' . substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16) . '$';
    return '{SHA512-CRYPT}' . crypt($password, $salt);
}

function mail_valid_domain(string $domain): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$/', $domain) && strpos($domain, '.') !== false;
}

function mail_valid_localpart(string $local): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9._%+-]{1,64}$/', $local);
}

function mail_valid_email(string $email): bool
{
    $parts = explode('@', $email);
    return count($parts) === 2 && mail_valid_localpart($parts[0]) && mail_valid_domain($parts[1]);
}

// --- Domains ----------------------------------------------------------------

function mail_domain_add(string $domain): array
{
    $domain = strtolower(trim($domain));
    if (!mail_valid_domain($domain)) {
        return ['ok' => false, 'error' => 'Enter a valid domain name.'];
    }
    $state = mail_state();
    if (isset($state['domains'][$domain])) {
        return ['ok' => false, 'error' => 'That mail domain already exists.'];
    }
    $state['domains'][$domain] = ['created' => date('c'), 'selector' => 'mail'];
    if (!mail_save($state)) {
        return ['ok' => false, 'error' => 'Could not save the mail domain.'];
    }
    $applied = mail_apply();
    if (empty($applied['ok'])) {
        return ['ok' => true, 'warning' => 'Domain saved, but the mail server could not be updated: ' . ($applied['error'] ?? 'unknown error')];
    }
    audit('mail.domain.add', $domain);
    return ['ok' => true];
}

function mail_domain_delete(string $domain): array
{
    $domain = strtolower(trim($domain));
    $state = mail_state();
    if (!isset($state['domains'][$domain])) {
        return ['ok' => false, 'error' => 'Mail domain not found.'];
    }
    unset($state['domains'][$domain]);
    $state['accounts'] = array_values(array_filter($state['accounts'], fn($a) => !str_ends_with((string) ($a['email'] ?? ''), '@' . $domain)));
    $state['aliases']  = array_values(array_filter($state['aliases'], fn($a) => !str_ends_with((string) ($a['from'] ?? ''), '@' . $domain)));
    if (!mail_save($state)) {
        return ['ok' => false, 'error' => 'Could not save mail state.'];
    }
    mail_apply();
    audit('mail.domain.delete', $domain);
    return ['ok' => true];
}

// --- Accounts ---------------------------------------------------------------

function mail_account_add(string $email, string $password): array
{
    $email = strtolower(trim($email));
    if (!mail_valid_email($email)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }
    if (strlen($password) < 8 || strlen($password) > 1024) {
        return ['ok' => false, 'error' => 'Mailbox password must be between 8 and 1024 characters.'];
    }
    $domain = substr($email, strpos($email, '@') + 1);
    $state = mail_state();
    if (!isset($state['domains'][$domain])) {
        return ['ok' => false, 'error' => 'Add the mail domain before creating mailboxes on it.'];
    }
    foreach ($state['accounts'] as $a) {
        if (strcasecmp((string) ($a['email'] ?? ''), $email) === 0) {
            return ['ok' => false, 'error' => 'That mailbox already exists.'];
        }
    }
    $state['accounts'][] = ['email' => $email, 'hash' => mail_hash_password($password), 'created' => date('c')];
    if (!mail_save($state)) {
        return ['ok' => false, 'error' => 'Could not save the mailbox.'];
    }
    $applied = mail_apply();
    if (empty($applied['ok'])) {
        return ['ok' => true, 'warning' => 'Mailbox saved, but the mail server could not be updated: ' . ($applied['error'] ?? 'unknown error')];
    }
    audit('mail.account.add', $email);
    return ['ok' => true];
}

function mail_account_passwd(string $email, string $password): array
{
    $email = strtolower(trim($email));
    if (strlen($password) < 8 || strlen($password) > 1024) {
        return ['ok' => false, 'error' => 'Mailbox password must be between 8 and 1024 characters.'];
    }
    $state = mail_state();
    $found = false;
    foreach ($state['accounts'] as &$a) {
        if (strcasecmp((string) ($a['email'] ?? ''), $email) === 0) {
            $a['hash'] = mail_hash_password($password);
            $found = true;
            break;
        }
    }
    unset($a);
    if (!$found) {
        return ['ok' => false, 'error' => 'Mailbox not found.'];
    }
    if (!mail_save($state)) {
        return ['ok' => false, 'error' => 'Could not save the mailbox.'];
    }
    mail_apply();
    audit('mail.account.passwd', $email);
    return ['ok' => true];
}

function mail_account_delete(string $email): array
{
    $email = strtolower(trim($email));
    $state = mail_state();
    $next = array_values(array_filter($state['accounts'], fn($a) => strcasecmp((string) ($a['email'] ?? ''), $email) !== 0));
    if (count($next) === count($state['accounts'])) {
        return ['ok' => false, 'error' => 'Mailbox not found.'];
    }
    $state['accounts'] = $next;
    if (!mail_save($state)) {
        return ['ok' => false, 'error' => 'Could not save mail state.'];
    }
    mail_apply();
    audit('mail.account.delete', $email);
    return ['ok' => true];
}

// --- Aliases ----------------------------------------------------------------

function mail_alias_add(string $from, string $to): array
{
    $from = strtolower(trim($from));
    $to   = strtolower(trim($to));
    if (!mail_valid_email($from)) {
        return ['ok' => false, 'error' => 'Enter a valid alias address.'];
    }
    if (!mail_valid_email($to)) {
        return ['ok' => false, 'error' => 'Enter a valid destination address.'];
    }
    $domain = substr($from, strpos($from, '@') + 1);
    $state = mail_state();
    if (!isset($state['domains'][$domain])) {
        return ['ok' => false, 'error' => 'Add the alias domain before creating aliases on it.'];
    }
    foreach ($state['aliases'] as $a) {
        if (strcasecmp((string) ($a['from'] ?? ''), $from) === 0 && strcasecmp((string) ($a['to'] ?? ''), $to) === 0) {
            return ['ok' => false, 'error' => 'That alias already exists.'];
        }
    }
    $state['aliases'][] = ['from' => $from, 'to' => $to, 'created' => date('c')];
    if (!mail_save($state)) {
        return ['ok' => false, 'error' => 'Could not save the alias.'];
    }
    $applied = mail_apply();
    if (empty($applied['ok'])) {
        return ['ok' => true, 'warning' => 'Alias saved, but the mail server could not be updated: ' . ($applied['error'] ?? 'unknown error')];
    }
    audit('mail.alias.add', $from . ' -> ' . $to);
    return ['ok' => true];
}

function mail_alias_delete(string $from, string $to): array
{
    $from = strtolower(trim($from));
    $to   = strtolower(trim($to));
    $state = mail_state();
    $next = array_values(array_filter($state['aliases'], fn($a) => !(strcasecmp((string) ($a['from'] ?? ''), $from) === 0 && strcasecmp((string) ($a['to'] ?? ''), $to) === 0)));
    if (count($next) === count($state['aliases'])) {
        return ['ok' => false, 'error' => 'Alias not found.'];
    }
    $state['aliases'] = $next;
    if (!mail_save($state)) {
        return ['ok' => false, 'error' => 'Could not save mail state.'];
    }
    mail_apply();
    audit('mail.alias.delete', $from);
    return ['ok' => true];
}

// --- DKIM + recommended DNS -------------------------------------------------

/** Fetch the DKIM selector + public-key TXT body for a domain. */
function mail_dkim(string $domain): array
{
    if (!isset(mail_state()['domains'][$domain])) {
        return ['ok' => false, 'error' => 'Mail domain not found.'];
    }
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed.'];
    }
    [$code, $out] = helper_cmd('mail-dkim ' . escapeshellarg($domain), 20);
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    $selector = 'mail';
    $value = '';
    foreach (explode("\n", trim($out)) as $line) {
        if (str_starts_with($line, 'selector=')) {
            $selector = substr($line, 9);
        } elseif (str_starts_with($line, 'value=')) {
            $value = substr($line, 6);
        }
    }
    return ['ok' => true, 'selector' => $selector, 'value' => $value];
}

/**
 * Recommended DNS records for a mail domain. `dkim` is filled from the live
 * key when available. These map 1:1 onto the panel DNS record shape.
 */
function mail_dns_records(string $domain): array
{
    $ip = mail_server_ip();
    $mailHost = 'mail.' . $domain;
    // Pin the sending server's IP into SPF so mail is authorised even when the
    // MX host resolves elsewhere (or DNS hasn't propagated yet). Fall back to a
    // plain `mx` mechanism only when we can't determine the public IP.
    $spf = $ip !== '' ? ('v=spf1 mx ip4:' . $ip . ' ~all') : 'v=spf1 mx ~all';
    $records = [
        ['type' => 'A',   'name' => 'mail', 'value' => $ip ?: 'YOUR.SERVER.IP', 'ttl' => 3600, 'priority' => null,
         'note' => 'Points the mail host at this server.'],
        ['type' => 'MX',  'name' => '@', 'value' => $mailHost, 'ttl' => 3600, 'priority' => 10,
         'note' => 'Routes inbound mail for the domain to this server.'],
        ['type' => 'TXT', 'name' => '@', 'value' => $spf, 'ttl' => 3600, 'priority' => null,
         'note' => 'SPF — authorises this server' . ($ip !== '' ? ' (' . $ip . ')' : '') . ' to send for the domain.'],
        ['type' => 'TXT', 'name' => '_dmarc', 'value' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@' . $domain . '; adkim=s; aspf=s', 'ttl' => 3600, 'priority' => null,
         'note' => 'DMARC — policy for mail that fails SPF/DKIM.'],
    ];
    $dkim = mail_dkim($domain);
    if (!empty($dkim['ok']) && $dkim['value'] !== '') {
        $records[] = ['type' => 'TXT', 'name' => $dkim['selector'] . '._domainkey', 'value' => $dkim['value'], 'ttl' => 3600, 'priority' => null,
            'note' => 'DKIM — the public key that verifies this server\'s signatures.'];
    }
    return $records;
}

/**
 * Publish the recommended records into the panel's authoritative DNS zone,
 * when the domain is one the panel manages. Existing equivalents are replaced.
 */
function mail_dns_publish(string $domain): array
{
    require_once APP_ROOT . '/lib/mod_sites.php';
    require_once APP_ROOT . '/lib/mod_dns.php';
    if (!isset(mail_state()['domains'][$domain])) {
        return ['ok' => false, 'error' => 'Mail domain not found.'];
    }
    $managed = array_map(fn($site) => (string) ($site['domain'] ?? ''), sites_list());
    if (!in_array($domain, $managed, true)) {
        return ['ok' => false, 'error' => 'This domain is not a panel-managed DNS zone. Add the records at your DNS provider instead — they are shown on this page.'];
    }
    $records = dns_zone_records($domain);
    // Drop any prior mail-related records we are about to re-add.
    $isMailRecord = static function (array $r): bool {
        $name = (string) ($r['name'] ?? '');
        $type = (string) ($r['type'] ?? '');
        $value = (string) ($r['value'] ?? '');
        if ($type === 'MX') return true;
        if ($type === 'A' && $name === 'mail') return true;
        if ($type === 'TXT' && ($name === '_dmarc' || str_ends_with($name, '._domainkey'))) return true;
        if ($type === 'TXT' && $name === '@' && stripos($value, 'v=spf1') === 0) return true;
        return false;
    };
    $records = array_values(array_filter($records, fn($r) => !$isMailRecord($r)));
    foreach (mail_dns_records($domain) as $rec) {
        if ($rec['type'] === 'A' && !filter_var($rec['value'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            continue; // no known server IP yet — skip rather than write a placeholder
        }
        $records[] = [
            'id' => bin2hex(random_bytes(6)),
            'type' => $rec['type'],
            'name' => $rec['name'],
            'value' => $rec['value'],
            'ttl' => (int) $rec['ttl'],
            'priority' => $rec['type'] === 'MX' ? (int) ($rec['priority'] ?? 10) : null,
        ];
    }
    $res = dns_save_records($domain, $records);
    if (!empty($res['ok'])) {
        audit('mail.dns.publish', $domain);
    }
    return $res;
}

// --- Webmail (Roundcube or SnappyMail) --------------------------------------
// One webmail client is active at a time. State lives under the 'webmail' key;
// older installs that predate SnappyMail keep their record under 'roundcube',
// which is read transparently here.

/** The active webmail record, or null. Always carries a 'kind'. */
function mail_webmail(): ?array
{
    $s = mail_state();
    if (is_array($s['webmail'] ?? null) && !empty($s['webmail'])) {
        $w = $s['webmail'];
        $w['kind'] = (string) ($w['kind'] ?? 'roundcube');
        return $w;
    }
    if (is_array($s['roundcube'] ?? null) && !empty($s['roundcube'])) {
        $w = $s['roundcube'];
        $w['kind'] = 'roundcube';
        return $w;
    }
    return null;
}

/** Human label for the active webmail client. */
function mail_webmail_label(?string $kind = null): string
{
    $kind = $kind ?? (mail_webmail()['kind'] ?? '');
    return $kind === 'snappymail' ? 'SnappyMail' : ($kind === 'roundcube' ? 'Roundcube' : 'Webmail');
}

function mail_webmail_installed(): bool
{
    $w = mail_webmail();
    return is_array($w) && !empty($w['dir']) && is_dir($w['dir']) && is_file($w['dir'] . '/index.php');
}

/** Shared installer for either client. $kind selects the helper action. */
function mail_webmail_install(string $kind, ?callable $onOutput = null): array
{
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed.'];
    }
    if (!in_array($kind, ['roundcube', 'snappymail'], true)) {
        return ['ok' => false, 'error' => 'Unknown webmail client.'];
    }
    if (mail_webmail_installed()) {
        return ['ok' => false, 'error' => 'A webmail client is already installed. Remove it first.'];
    }
    $prefix = $kind === 'snappymail' ? 'snappymail-' : 'webmail-';
    $name = $prefix . bin2hex(random_bytes(4));
    $target = dirname(APP_ROOT) . '/' . $name;
    $args = $kind . '-install ' . escapeshellarg($target);
    [$code, $out] = $onOutput ? helper_cmd_stream($args, $onOutput, 900) : helper_cmd($args, 900);
    if ($code !== 0) {
        $err = trim($out);
        if (stripos($err, 'unknown command') !== false) {
            $err = 'The privileged helper on the server is out of date and does not support this webmail installer yet. '
                 . 'Re-run install.sh on the server to update the helper, then try again.';
        }
        return ['ok' => false, 'error' => $err ?: (mail_webmail_label($kind) . ' install failed.')];
    }
    $url = '/' . $name . '/';
    $state = mail_state();
    $state['webmail'] = ['kind' => $kind, 'dir' => $target, 'url' => $url, 'installed_at' => date('c')];
    unset($state['roundcube']); // migrate off the legacy key
    if (!mail_save($state)) {
        helper_cmd('webmail-remove ' . escapeshellarg($target));
        return ['ok' => false, 'error' => 'Could not save webmail state.'];
    }
    audit('mail.webmail.install', $kind . ' ' . $target);
    return ['ok' => true, 'url' => $url];
}

function mail_webmail_remove(): array
{
    $w = mail_webmail();
    if (!$w) {
        return ['ok' => true];
    }
    if (helper_available() && !empty($w['dir'])) {
        [$code, $out] = helper_cmd('webmail-remove ' . escapeshellarg((string) $w['dir']));
        if ($code !== 0) {
            // Fall back to the legacy remover for older Roundcube installs.
            [$code, $out] = helper_cmd('roundcube-remove ' . escapeshellarg((string) $w['dir']));
        }
        if ($code !== 0) {
            return ['ok' => false, 'error' => trim($out) ?: 'Could not remove webmail.'];
        }
    }
    $state = mail_state();
    $state['webmail'] = null;
    $state['roundcube'] = null;
    mail_save($state);
    audit('mail.webmail.remove', (string) ($w['kind'] ?? ''));
    return ['ok' => true];
}

// Backward-compatible thin wrappers (kept for any external callers).
function mail_roundcube(): ?array { $w = mail_webmail(); return ($w && $w['kind'] === 'roundcube') ? $w : null; }
function mail_roundcube_installed(): bool { $w = mail_webmail(); return $w !== null && $w['kind'] === 'roundcube' && mail_webmail_installed(); }
function mail_roundcube_install(?callable $onOutput = null): array { return mail_webmail_install('roundcube', $onOutput); }
function mail_roundcube_remove(): array { return mail_webmail_remove(); }
