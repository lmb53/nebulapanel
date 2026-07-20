<?php
/**
 * SSL Certificates module — inspects and manages Let's Encrypt / certbot
 * certificates through the privileged helper. certbot is the source of truth;
 * this module parses `certbot certificates` output for the UI. All shell
 * tokens are escapeshellarg()'d and inputs validated with a strict regex
 * before any helper_cmd() call. Reuses helpers from lib/sys.php and
 * lib/helpers.php; never redefines them.
 */

/** Is certbot installed and the privileged helper available? */
function ssl_available(): bool
{
    return has_cmd('certbot') && helper_available();
}

/** Validate a certificate name / domain. */
function ssl_domain_ok($d): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$/', (string) $d);
}

/**
 * List certificates by parsing `certbot certificates` output.
 * Returns ['ok'=>bool, 'error'=>?string, 'certs'=>[[name,domains,expiry,days,valid]]].
 */
function ssl_list(): array
{
    [$c, $o] = helper_cmd('cert-list');
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'cert-list failed', 'certs' => []];
    }

    $certs = [];
    // Split the report into per-certificate blocks on "Certificate Name:".
    $blocks = preg_split('/^\s*Certificate Name:\s*/m', (string) $o);
    // First chunk is the preamble before the first cert; skip it.
    for ($i = 1; $i < count($blocks); $i++) {
        $block = $blocks[$i];

        // The name is the remainder of the first line.
        $name = '';
        if (preg_match('/^(.*)$/m', $block, $m)) {
            $name = trim($m[1]);
        }

        $domains = '';
        if (preg_match('/^\s*Domains:\s*(.+)$/m', $block, $m)) {
            $domains = trim($m[1]);
        }

        $expiry = '';
        $days = null;
        $valid = true;
        if (preg_match('/^\s*Expiry Date:\s*(.+)$/m', $block, $m)) {
            $expiry = trim($m[1]);
            if (preg_match('/VALID:\s*(\d+)\s*day/i', $expiry, $dm)) {
                $days = (int) $dm[1];
            }
            $valid = (stripos($expiry, 'INVALID') === false && stripos($expiry, 'EXPIRED') === false);
        }

        if ($name === '') {
            continue;
        }
        $certs[] = [
            'name'    => $name,
            'domains' => $domains,
            'expiry'  => $expiry,
            'days'    => $days,
            'valid'   => $valid,
        ];
    }

    return ['ok' => true, 'certs' => $certs];
}

/** Issue a Let's Encrypt certificate for an existing nginx site. */
function ssl_issue(string $domain, string $email = ''): array
{
    if (!ssl_domain_ok($domain)) {
        return ['ok' => false, 'error' => 'Invalid domain.'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email.'];
    }
    $args = 'site-ssl ' . escapeshellarg($domain) . ($email !== '' ? (' ' . escapeshellarg($email)) : '');
    [$c, $o] = helper_cmd($args, 300);
    audit('ssl.issue', $domain);
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'site-ssl failed'];
    }
    return ['ok' => true];
}

/** Renew all certificates, or a single named one. */
function ssl_renew(string $name = ''): array
{
    if ($name !== '' && !ssl_domain_ok($name)) {
        return ['ok' => false, 'error' => 'Invalid certificate name.'];
    }
    $args = 'cert-renew' . ($name !== '' ? (' ' . escapeshellarg($name)) : '');
    [$c, $o] = helper_cmd($args, 300);
    audit('ssl.renew', $name);
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'cert-renew failed'];
    }
    return ['ok' => true];
}

/** Delete a certificate by name. */
function ssl_delete(string $name): array
{
    if (!ssl_domain_ok($name)) {
        return ['ok' => false, 'error' => 'Invalid certificate name.'];
    }
    [$c, $o] = helper_cmd('cert-delete ' . escapeshellarg($name));
    audit('ssl.delete', $name);
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'cert-delete failed'];
    }
    return ['ok' => true];
}
