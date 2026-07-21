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
    return helper_available();
}

function ssl_certbot_available(): bool
{
    return has_cmd('certbot') && helper_available();
}

/** Validate a certificate name / domain. */
function ssl_domain_ok($d): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$/', (string) $d);
}

function ssl_custom_file(): string
{
    return APP_ROOT . '/data/custom-certificates.json';
}

function ssl_custom_list(): array
{
    $items = @json_decode((string) @file_get_contents(ssl_custom_file()), true);
    return is_array($items) ? array_values($items) : [];
}

/**
 * List certificates by parsing `certbot certificates` output.
 * Returns ['ok'=>bool, 'error'=>?string, 'certs'=>[[name,domains,expiry,days,valid]]].
 */
function ssl_list(): array
{
    if (!ssl_certbot_available()) {
        return ['ok' => true, 'certs' => ssl_custom_list()];
    }
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

    foreach (ssl_custom_list() as $custom) {
        $certs[] = $custom;
    }
    return ['ok' => true, 'certs' => $certs];
}

/** Validate and install a user-supplied PEM certificate/private-key pair. */
function ssl_upload(string $domain, string $certificate, string $privateKey, string $chain = ''): array
{
    if (!ssl_domain_ok($domain)) { return ['ok' => false, 'error' => 'Invalid domain.']; }
    foreach ([$certificate, $privateKey, $chain] as $pem) {
        if (strlen($pem) > 65536) { return ['ok' => false, 'error' => 'Each PEM file must be smaller than 64 KB.']; }
    }
    if (!str_contains($certificate, '-----BEGIN CERTIFICATE-----') || !str_contains($privateKey, 'PRIVATE KEY-----')) {
        return ['ok' => false, 'error' => 'Certificate and private key must be PEM encoded.'];
    }
    $cert = @openssl_x509_read($certificate);
    $key = @openssl_pkey_get_private($privateKey);
    if ($cert === false || $key === false) { return ['ok' => false, 'error' => 'Could not parse the certificate or private key.']; }
    if (!@openssl_x509_check_private_key($cert, $key)) { return ['ok' => false, 'error' => 'Certificate and private key do not match.']; }
    if (function_exists('openssl_x509_check_host') && @openssl_x509_check_host($cert, $domain) !== 1) {
        return ['ok' => false, 'error' => 'The certificate does not cover ' . $domain . '.'];
    }
    $parsed = @openssl_x509_parse($cert);
    if (!is_array($parsed) || (int) ($parsed['validTo_time_t'] ?? 0) <= time()) {
        return ['ok' => false, 'error' => 'The certificate is expired or has no valid expiry.'];
    }
    require_once APP_ROOT . '/lib/mod_sites.php';
    $siteFound = false;
    foreach (sites_list() as $site) {
        if (($site['domain'] ?? '') === $domain) { $siteFound = true; break; }
    }
    if (!$siteFound) { return ['ok' => false, 'error' => 'Create the website before uploading its certificate.']; }

    [$c, $o] = helper_cmd('cert-upload ' . escapeshellarg($domain) . ' '
        . escapeshellarg(base64_encode($certificate)) . ' '
        . escapeshellarg(base64_encode($privateKey)) . ' '
        . escapeshellarg($chain !== '' ? base64_encode($chain) : ''), 120);
    if ($c !== 0) { return ['ok' => false, 'error' => trim($o) ?: 'Certificate installation failed.']; }

    $expiry = (int) $parsed['validTo_time_t'];
    $issuer = (string) ($parsed['issuer']['CN'] ?? $parsed['issuer']['O'] ?? 'Custom / Uploaded');
    $items = ssl_custom_list();
    $items = array_values(array_filter($items, fn($item) => ($item['name'] ?? '') !== $domain));
    $items[] = [
        'name' => $domain,
        'domains' => $domain,
        'expiry' => date('Y-m-d H:i:s O', $expiry),
        'days' => max(0, (int) floor(($expiry - time()) / 86400)),
        'valid' => true,
        'issuer' => $issuer,
        'custom' => true,
    ];
    if (!write_json_file(ssl_custom_file(), $items)) { return ['ok' => false, 'error' => 'Certificate installed, but its panel metadata could not be saved.']; }
    $sites = sites_list();
    foreach ($sites as &$site) { if (($site['domain'] ?? '') === $domain) { $site['ssl'] = true; } }
    unset($site); sites_save($sites);
    audit('ssl.upload', $domain);
    return ['ok' => true];
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
    $custom = false;
    foreach (ssl_custom_list() as $item) { if (($item['name'] ?? '') === $name) { $custom = true; break; } }
    [$c, $o] = helper_cmd(($custom ? 'cert-custom-delete ' : 'cert-delete ') . escapeshellarg($name));
    audit('ssl.delete', $name);
    if ($c !== 0) {
        return ['ok' => false, 'error' => trim($o) ?: 'cert-delete failed'];
    }
    if ($custom) {
        $items = array_values(array_filter(ssl_custom_list(), fn($item) => ($item['name'] ?? '') !== $name));
        write_json_file(ssl_custom_file(), $items);
        require_once APP_ROOT . '/lib/mod_sites.php';
        $sites = sites_list();
        foreach ($sites as &$site) { if (($site['domain'] ?? '') === $name) { $site['ssl'] = false; } }
        unset($site); sites_save($sites);
    }
    return ['ok' => true];
}
