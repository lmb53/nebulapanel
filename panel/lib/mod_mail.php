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
    // The FQDN mail-setup issued the TLS certificate for. Webmail must use this
    // name, not Postfix's myhostname, which is the system hostname when Postfix
    // was installed as an unrelated package's dependency.
    $s['hostname']  = is_string($s['hostname'] ?? null) ? $s['hostname'] : '';
    $s['webmail']   = is_array($s['webmail'] ?? null) ? $s['webmail'] : null;
    return $s;
}

function mail_save(array $state): bool
{
    return write_json_file(mail_file(), $state, 0600);
}

function with_mail_state_lock(callable $callback)
{
    $handle = @fopen(DATA_DIR . '/mail-state.lock', 'c');
    if ($handle === false || !@flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        return ['ok'=>false,'error'=>'Could not lock mail state.'];
    }
    try { return $callback(); }
    finally { @flock($handle,LOCK_UN);fclose($handle); }
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

/** Explicit public address configured by the installer. */
function mail_server_ip(): string
{
    $ip = trim((string) @file_get_contents('/etc/nebula-panel/public-ip'));
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
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

// --- Statistics -------------------------------------------------------------

/**
 * Aggregate mail activity for the last $days days.
 *
 * The helper hands back the raw (root-readable) Postfix/Dovecot log plus maildir
 * sizes; the counting happens here so the privileged surface stays a dumb reader.
 * Postfix logs one `status=` line per recipient, and the queue-id links it back
 * to the `from=` line, which is how senders/recipients are attributed.
 */
function mail_stats(int $days = 30): array
{
    $days = max(1, min(365, $days));
    $out = [
        'ok'          => true,
        'days'        => $days,
        'source'      => '',
        'generated'   => date('c'),
        'totals'      => ['sent' => 0, 'received' => 0, 'bounced' => 0, 'deferred' => 0, 'rejected' => 0,
                          'logins' => 0, 'auth_failed' => 0, 'bytes_sent' => 0, 'bytes_received' => 0],
        'queue'       => ['total' => 0, 'deferred' => 0],
        'timeline'    => [],   // [ ['date'=>'YYYY-MM-DD','sent'=>n,'received'=>n,'rejected'=>n], … ]
        'top_senders' => [],
        'top_recipients' => [],
        'mailboxes'   => [],
        'recent'      => [],
        'warning'     => '',
    ];
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed. Re-run install.sh.'];
    }
    [$code, $raw] = helper_cmd('mail-stats ' . $days, 120);
    if ($code !== 0) {
        $err = trim($raw);
        if (stripos($err, 'unknown command') !== false) {
            $err = 'The privileged helper on this server is out of date. Update the panel (Panel Updates) or re-run install.sh, then try again.';
        }
        return ['ok' => false, 'error' => $err ?: 'Could not collect mail statistics.'];
    }

    // Seed the timeline so quiet days still show up on the chart.
    $buckets = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $buckets[$d] = ['date' => $d, 'sent' => 0, 'received' => 0, 'rejected' => 0];
    }
    $cutoff = strtotime("-$days days");

    $senders = $recipients = [];
    $qidFrom = $qidSize = [];
    $recent = [];
    $inLog = false;

    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if (!$inLog) {
            if ($line === '== log ==') { $inLog = true; continue; }
            if (strpos($line, "mailbox\t") === 0) {
                $c = explode("\t", $line);
                $out['mailboxes'][] = [
                    'email'    => $c[1] ?? '',
                    'bytes'    => (int) ($c[2] ?? 0),
                    'messages' => (int) ($c[3] ?? 0),
                    'unread'   => (int) ($c[4] ?? 0),
                ];
                continue;
            }
            $kv = explode('=', $line, 2);
            if (count($kv) === 2) {
                if ($kv[0] === 'source') { $out['source'] = $kv[1]; }
                elseif ($kv[0] === 'queue_total') { $out['queue']['total'] = (int) $kv[1]; }
                elseif ($kv[0] === 'queue_deferred') { $out['queue']['deferred'] = (int) $kv[1]; }
            }
            continue;
        }
        if ($line === '') { continue; }

        $ts = mail_log_time($line);
        if ($ts !== null && $ts < $cutoff) { continue; }
        $day = $ts !== null ? date('Y-m-d', $ts) : null;
        $bump = static function (string $key) use (&$buckets, $day): void {
            if ($day !== null && isset($buckets[$day])) { $buckets[$day][$key]++; }
        };

        // qmgr publishes the envelope sender + size once per queue id.
        if (preg_match('/: ([0-9A-F]{6,}): from=<([^>]*)>, size=(\d+)/i', $line, $m)) {
            $qidFrom[$m[1]] = strtolower($m[2]);
            $qidSize[$m[1]] = (int) $m[3];
            continue;
        }

        if (preg_match('/: ([0-9A-F]{6,}): to=<([^>]*)>.*?status=(\w+)/i', $line, $m)) {
            [$all, $qid, $to, $status] = $m;
            $to = strtolower($to);
            $status = strtolower($status);
            $size = $qidSize[$qid] ?? 0;
            $from = $qidFrom[$qid] ?? '';
            // Local delivery goes through the virtual/dovecot transport; anything
            // else left this server for a remote MTA.
            $inbound = (bool) preg_match('/relay=(virtual|dovecot|local)|delivered to (maildir|mailbox)/i', $line);
            if ($status === 'sent') {
                if ($inbound) {
                    $out['totals']['received']++;
                    $out['totals']['bytes_received'] += $size;
                    $bump('received');
                    if ($to !== '') { $recipients[$to] = ($recipients[$to] ?? 0) + 1; }
                } else {
                    $out['totals']['sent']++;
                    $out['totals']['bytes_sent'] += $size;
                    $bump('sent');
                    if ($from !== '') { $senders[$from] = ($senders[$from] ?? 0) + 1; }
                }
            } elseif ($status === 'bounced' || $status === 'expired') {
                $out['totals']['bounced']++;
                if (count($recent) < 40) {
                    $recent[] = ['time' => $ts ? date('Y-m-d H:i', $ts) : '', 'kind' => 'bounced', 'address' => $to, 'detail' => mail_log_reason($line)];
                }
            } elseif ($status === 'deferred') {
                $out['totals']['deferred']++;
                if (count($recent) < 40) {
                    $recent[] = ['time' => $ts ? date('Y-m-d H:i', $ts) : '', 'kind' => 'deferred', 'address' => $to, 'detail' => mail_log_reason($line)];
                }
            }
            continue;
        }

        if (stripos($line, 'NOQUEUE: reject') !== false || stripos($line, 'reject: RCPT') !== false) {
            $out['totals']['rejected']++;
            $bump('rejected');
            if (count($recent) < 40 && preg_match('/to=<([^>]*)>/', $line, $m)) {
                $recent[] = ['time' => $ts ? date('Y-m-d H:i', $ts) : '', 'kind' => 'rejected', 'address' => strtolower($m[1]), 'detail' => mail_log_reason($line)];
            }
            continue;
        }

        if (preg_match('/(imap|pop3)-login: Login: user=<([^>]*)>/i', $line, $m)) {
            $out['totals']['logins']++;
            continue;
        }
        if (stripos($line, 'auth failed') !== false || stripos($line, 'authentication failure') !== false) {
            $out['totals']['auth_failed']++;
        }
    }

    arsort($senders);
    arsort($recipients);
    $top = static function (array $counts): array {
        $list = [];
        foreach (array_slice($counts, 0, 8, true) as $addr => $n) {
            $list[] = ['address' => $addr, 'count' => $n];
        }
        return $list;
    };
    $out['top_senders'] = $top($senders);
    $out['top_recipients'] = $top($recipients);
    $out['timeline'] = array_values($buckets);
    usort($out['mailboxes'], fn($a, $b) => $b['bytes'] <=> $a['bytes']);
    $out['recent'] = $recent;
    if ($out['totals']['sent'] + $out['totals']['received'] === 0) {
        $out['warning'] = 'No delivery activity found in the mail log yet' . ($out['source'] !== '' ? ' (' . $out['source'] . ')' : '') . '.';
    }
    return $out;
}

/** Parse a syslog-style timestamp ("Jul 26 12:00:01") into a unix time. */
function mail_log_time(string $line): ?int
{
    if (preg_match('/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})/', $line, $m)) {
        $t = strtotime($m[1]);
        return $t === false ? null : $t;
    }
    if (!preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})/', $line, $m)) {
        return null;
    }
    $t = strtotime($m[1] . ' ' . date('Y'));
    if ($t === false) {
        return null;
    }
    // Syslog omits the year: a "future" stamp is really last year's log.
    if ($t > time() + 86400) {
        $t = strtotime($m[1] . ' ' . (date('Y') - 1));
    }
    return $t === false ? null : $t;
}

/** The human-readable tail of a Postfix status line (the SMTP reason). */
function mail_log_reason(string $line): string
{
    if (preg_match('/status=\w+ \((.*)\)\s*$/', $line, $m)) {
        return mb_substr(trim($m[1]), 0, 180);
    }
    if (preg_match('/reject: RCPT[^:]*: (.*)$/', $line, $m)) {
        return mb_substr(trim($m[1]), 0, 180);
    }
    return mb_substr(trim(substr($line, strpos($line, ']:') !== false ? strpos($line, ']:') + 2 : 0)), 0, 180);
}

/**
 * Install or repair the mail stack for an explicit public hostname.
 *
 * The helper verifies or obtains the certificate before installing packages,
 * avoiding a half-configured mail stack when DNS is not ready yet.
 */
function mail_setup(string $hostname, string $certEmail = '', ?callable $onOutput = null): array
{
    $hostname = strtolower(rtrim(trim($hostname), '.'));
    $certEmail = trim($certEmail);
    if (!domain_name_ok($hostname)) {
        return ['ok' => false, 'error' => 'Enter a valid public mail hostname such as mail.example.com.'];
    }
    if ($certEmail !== '' && !filter_var($certEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid certificate notification email address.'];
    }
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed. Re-run install.sh.'];
    }
    $args = 'mail-setup ' . escapeshellarg($hostname) . ' ' . escapeshellarg($certEmail);
    [$code, $out] = $onOutput
        ? helper_cmd_stream($args, $onOutput, 900)
        : helper_cmd($args, 900);
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    audit('mail.setup');
    // Record the hostname so later steps (webmail) use the name that actually
    // has a certificate rather than re-deriving one from the running MTA.
    $state = mail_state();
    $state['hostname'] = $hostname;
    mail_save($state);
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

/**
 * Save a mail-state mutation and apply it. If the runtime apply fails, restore
 * both the previous state and the previous generated maps before returning.
 */
function mail_commit_state(array $previous, array $next, string $label): array
{
    return with_mail_state_lock(static function () use ($previous,$next,$label): array {
        if (hash('sha256',serialize(mail_state())) !== hash('sha256',serialize($previous))) {
            return ['ok'=>false,'conflict'=>true,'error'=>'Mail state changed in another session; reload and retry.'];
        }
        if (!mail_save($next)) {
            return ['ok' => false, 'error' => 'Could not save ' . $label . '.'];
        }
        $applied = mail_apply();
        if (!empty($applied['ok'])) {
            return ['ok' => true];
        }
        $stateRestored = mail_save($previous);
        $runtimeRestored = $stateRestored ? mail_apply() : ['ok' => false];
        $suffix = (!empty($runtimeRestored['ok']))
            ? ' The previous configuration was restored.'
            : ' Automatic rollback also failed; run the mail repair action.';
        return ['ok' => false, 'error' => 'The mail server rejected the change: '
            . ($applied['error'] ?? 'unknown error') . $suffix];
    });
}

/** SHA-512 crypt hash in the scheme Dovecot's passwd-file expects. */
function mail_hash_password(string $password): string
{
    $salt = '$6$' . substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16) . '$';
    return '{SHA512-CRYPT}' . crypt($password, $salt);
}

function mail_valid_domain(string $domain): bool
{
    return domain_name_ok($domain);
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
    $previous = $state;
    $state['domains'][$domain] = ['created' => date('c'), 'selector' => 'mail'];
    $committed = mail_commit_state($previous, $state, 'the mail domain');
    if (empty($committed['ok'])) return $committed;
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
    $previous = $state;
    unset($state['domains'][$domain]);
    $state['accounts'] = array_values(array_filter($state['accounts'], fn($a) => !str_ends_with((string) ($a['email'] ?? ''), '@' . $domain)));
    $state['aliases']  = array_values(array_filter($state['aliases'], fn($a) => !str_ends_with((string) ($a['from'] ?? ''), '@' . $domain)));
    $committed = mail_commit_state($previous, $state, 'mail state');
    if (empty($committed['ok'])) return $committed;
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
    if (($passwordError = panel_password_error($password, strstr($email, '@', true) ?: '')) !== null) {
        return ['ok' => false, 'error' => 'Mailbox ' . lcfirst($passwordError)];
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
    $previous = $state;
    $state['accounts'][] = ['email' => $email, 'hash' => mail_hash_password($password), 'created' => date('c')];
    $committed = mail_commit_state($previous, $state, 'the mailbox');
    if (empty($committed['ok'])) return $committed;
    audit('mail.account.add', $email);
    return ['ok' => true];
}

function mail_account_passwd(string $email, string $password): array
{
    $email = strtolower(trim($email));
    if (($passwordError = panel_password_error($password, strstr($email, '@', true) ?: '')) !== null) {
        return ['ok' => false, 'error' => 'Mailbox ' . lcfirst($passwordError)];
    }
    $state = mail_state();
    $previous = $state;
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
    $committed = mail_commit_state($previous, $state, 'the mailbox');
    if (empty($committed['ok'])) return $committed;
    audit('mail.account.passwd', $email);
    return ['ok' => true];
}

function mail_account_delete(string $email): array
{
    $email = strtolower(trim($email));
    $state = mail_state();
    $previous = $state;
    $next = array_values(array_filter($state['accounts'], fn($a) => strcasecmp((string) ($a['email'] ?? ''), $email) !== 0));
    if (count($next) === count($state['accounts'])) {
        return ['ok' => false, 'error' => 'Mailbox not found.'];
    }
    $state['accounts'] = $next;
    $committed = mail_commit_state($previous, $state, 'mail state');
    if (empty($committed['ok'])) return $committed;
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
    $previous = $state;
    $state['aliases'][] = ['from' => $from, 'to' => $to, 'created' => date('c')];
    $committed = mail_commit_state($previous, $state, 'the alias');
    if (empty($committed['ok'])) return $committed;
    audit('mail.alias.add', $from . ' -> ' . $to);
    return ['ok' => true];
}

function mail_alias_delete(string $from, string $to): array
{
    $from = strtolower(trim($from));
    $to   = strtolower(trim($to));
    $state = mail_state();
    $previous = $state;
    $next = array_values(array_filter($state['aliases'], fn($a) => !(strcasecmp((string) ($a['from'] ?? ''), $from) === 0 && strcasecmp((string) ($a['to'] ?? ''), $to) === 0)));
    if (count($next) === count($state['aliases'])) {
        return ['ok' => false, 'error' => 'Alias not found.'];
    }
    $state['aliases'] = $next;
    $committed = mail_commit_state($previous, $state, 'mail state');
    if (empty($committed['ok'])) return $committed;
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
    $mechanism = filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6) ? 'ip6:' : 'ip4:';
    $spf = $ip !== '' ? ('v=spf1 mx ' . $mechanism . $ip . ' ~all') : 'v=spf1 mx ~all';
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

// --- Webmail (Roundcube) ----------------------------------------------------
// Roundcube is the only webmail client the panel installs. State lives under the
// 'webmail' key; older installs keep their record under 'roundcube', which is
// read transparently here. Installs made by earlier panel versions that chose
// SnappyMail are still recognised and removable — just no longer installable.

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
    // 'snappymail' only ever appears for installs made by an older panel build.
    return $kind === 'snappymail' ? 'SnappyMail (legacy)' : ($kind === 'roundcube' ? 'Roundcube' : 'Webmail');
}

function mail_webmail_installed(): bool
{
    $w = mail_webmail();
    return is_array($w) && !empty($w['dir']) && is_dir($w['dir']) && is_file($w['dir'] . '/index.php');
}

/**
 * The hostname the mail stack was set up with. Falls back to the live MTA value
 * for stacks configured before the panel started recording it.
 */
function mail_hostname(): string
{
    $recorded = (string) (mail_state()['hostname'] ?? '');
    if ($recorded !== '') {
        return $recorded;
    }
    $status = mail_status();
    return !empty($status['installed']) ? (string) ($status['hostname'] ?? '') : '';
}

/** Install the webmail client. Roundcube is the only supported client. */
function mail_webmail_install(string $kind = 'roundcube', ?callable $onOutput = null): array
{
    if ($kind !== 'roundcube') {
        return ['ok' => false, 'error' => 'Roundcube is the only webmail client this panel installs.'];
    }
    // Webmail talks to IMAP/SMTP over verified TLS, so it cannot be installed
    // before the mail stack owns a hostname with a certificate. Catching it here
    // gives a fixable message instead of a bare failure from the installer.
    $hostname = mail_hostname();
    if ($hostname === '') {
        return ['ok' => false, 'error' => 'Set up the mail stack first — webmail needs a mail hostname with a TLS '
                                        . 'certificate before it can connect to IMAP and SMTP.'];
    }
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed.'];
    }
    if (mail_webmail_installed()) {
        return ['ok' => false, 'error' => 'A webmail client is already installed. Remove it first.'];
    }
    $name = 'webmail-' . bin2hex(random_bytes(4));
    $target = dirname(APP_ROOT) . '/' . $name;
    $args = $kind . '-install ' . escapeshellarg($target) . ' ' . escapeshellarg($hostname);
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
