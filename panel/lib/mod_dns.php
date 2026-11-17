<?php
/** Authoritative DNS zones managed by Nebula and published to BIND 9. */

function dns_store_file(): string { return DATA_DIR . '/dns-zones.json'; }
function dns_nameservers(): array
{
    global $config;
    $host = strtolower((string) (gethostname() ?: 'nebula.local'));
    return array_values(array_filter((array) ($config['nameservers'] ?? ['ns1.' . $host, 'ns2.' . $host])));
}
function dns_zones(): array
{
    $data = @json_decode((string) @file_get_contents(dns_store_file()), true);
    return is_array($data) && isset($data['zones']) && is_array($data['zones']) ? $data['zones'] : [];
}
function dns_zone_records(string $domain): array { return array_values(dns_zones()[$domain]['records'] ?? []); }
function dns_name_labels_ok(string $name, bool $allowWildcard = false, bool $allowUnderscore = false): bool
{
    $name = strtolower(rtrim($name, '.'));
    if ($allowWildcard && str_starts_with($name, '*.')) $name = substr($name, 2);
    if ($name === '' || strlen($name) > 253 || str_contains($name, '..')) return false;
    foreach (explode('.', $name) as $label) {
        if (strlen($label) < 1 || strlen($label) > 63) return false;
        $pattern = $allowUnderscore ? '/^[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?$/' : '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';
        if (!preg_match($pattern, $label)) return false;
    }
    return true;
}
function dns_record_name_ok(string $name): bool
{
    return $name === '@' || dns_name_labels_ok($name, true, true);
}
function dns_target_ok(string $value): bool { return dns_name_labels_ok($value); }

function dns_validate_record(array $input): array
{
    $type = strtoupper(trim((string) ($input['type'] ?? 'A'))); $name = trim((string) ($input['name'] ?? '@')) ?: '@';
    $value = trim((string) ($input['value'] ?? '')); $ttl = (int) ($input['ttl'] ?? 3600); $priority = (int) ($input['priority'] ?? 10);
    if (!in_array($type, ['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA'], true)) return ['ok'=>false,'error'=>'Unsupported record type.'];
    if (!dns_record_name_ok($name)) return ['ok'=>false,'error'=>'Invalid record name.'];
    if ($ttl < 60 || $ttl > 604800) return ['ok'=>false,'error'=>'TTL must be between 60 and 604800 seconds.'];
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\r\n\x00]/', $value)) return ['ok'=>false,'error'=>'Invalid record value.'];
    if ($type === 'A' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return ['ok'=>false,'error'=>'Enter a valid IPv4 address.'];
    if ($type === 'AAAA' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) return ['ok'=>false,'error'=>'Enter a valid IPv6 address.'];
    if (in_array($type, ['CNAME','MX','NS'], true) && !dns_target_ok($value)) return ['ok'=>false,'error'=>'Enter a valid DNS target.'];
    if ($type === 'CAA' && (!preg_match('/^(\d+)\s+(issue|issuewild|iodef)\s+"[^"]+"$/', $value, $caa) || (int) $caa[1] > 255)) return ['ok'=>false,'error'=>'CAA value must look like: 0 issue "letsencrypt.org"'];
    if ($type === 'SRV') {
        if (!preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+([A-Za-z0-9.-]+\.?)$/', $value, $srv)
            || (int) $srv[1] > 65535 || (int) $srv[2] > 65535 || (int) $srv[3] > 65535
            || !dns_target_ok($srv[4])) {
            return ['ok'=>false,'error'=>'SRV value must be: priority weight port target (numeric fields 0-65535).'];
        }
    }
    if ($type === 'MX' && ($priority < 0 || $priority > 65535)) return ['ok'=>false,'error'=>'MX priority must be between 0 and 65535.'];
    return ['ok'=>true,'record'=>['id'=>bin2hex(random_bytes(6)),'type'=>$type,'name'=>$name,'value'=>$value,'ttl'=>$ttl,'priority'=>$type==='MX'?$priority:null]];
}

/**
 * Format a TXT value as one or more quoted character-strings. A single BIND
 * character-string may not exceed 255 bytes, so long values (e.g. 2048-bit
 * DKIM public keys) are split into several adjacent quoted strings, which
 * resolvers concatenate back together.
 */
function dns_txt_quote(string $value): string
{
    if (strlen($value) <= 255) {
        return '"' . addcslashes($value, "\\\"") . '"';
    }
    $parts = [];
    foreach (str_split($value, 255) as $chunk) {
        $parts[] = '"' . addcslashes($chunk, "\\\"") . '"';
    }
    return implode(' ', $parts);
}

function dns_fqdn(string $name, string $domain): string
{
    return $name === '@' ? '@' : (str_ends_with($name, '.') ? $name : $name);
}
function dns_zone_text(string $domain, array $records, ?int $serial = null): string
{
    $ns = dns_nameservers(); $primary = rtrim((string) ($ns[0] ?? 'ns1.' . $domain), '.') . '.';
    $mailbox = 'hostmaster.' . rtrim($domain, '.') . '.'; $serial = (string) ($serial ?? time());
    $lines = ['$TTL 3600', '@ IN SOA ' . $primary . ' ' . $mailbox . ' (', '  ' . $serial . ' 3600 900 1209600 300', ')'];
    foreach ($ns as $server) $lines[] = '@ 86400 IN NS ' . rtrim($server, '.') . '.';
    foreach ($records as $record) {
        $name = dns_fqdn((string) $record['name'], $domain); $ttl=(int)$record['ttl']; $type=(string)$record['type']; $value=(string)$record['value'];
        if (in_array($type, ['CNAME','MX','NS'], true)) $value = rtrim($value, '.') . '.';
        // SRV targets are host names too: without the trailing dot BIND
        // appends the zone ("mail.example.com.example.com").
        if ($type === 'SRV' && preg_match('/^(\d+\s+\d+\s+\d+)\s+(\S+)$/', $value, $srv)) $value = $srv[1] . ' ' . rtrim($srv[2], '.') . '.';
        if ($type === 'TXT') $value = dns_txt_quote($value);
        if ($type === 'MX') $value = (int) ($record['priority'] ?? 10) . ' ' . $value;
        $lines[] = $name . ' ' . $ttl . ' IN ' . $type . ' ' . $value;
    }
    return implode("\n", $lines) . "\n";
}

function dns_publish_zone(string $domain, array $records, ?int $serial = null): array
{
    if (!helper_available()) return ['ok'=>true,'published'=>false,'warning'=>'Saved in Nebula; re-run install.sh to enable authoritative BIND publishing.'];
    [$code,$out] = helper_cmd('dns-zone-put ' . escapeshellarg($domain) . ' ' . escapeshellarg(base64_encode(dns_zone_text($domain, $records, $serial))), 300);
    return $code === 0 ? ['ok'=>true,'published'=>true] : ['ok'=>false,'error'=>sudo_error($out,$code)];
}
function dns_record_set_error(array $records): ?string
{
    $byName = [];
    $seen = [];
    foreach ($records as $record) {
        $name = strtolower((string) ($record['name'] ?? '@'));
        $type = strtoupper((string) ($record['type'] ?? ''));
        $fingerprint = $name . "\0" . $type . "\0" . strtolower((string) ($record['value'] ?? '')) . "\0" . (int) ($record['priority'] ?? 0);
        if (isset($seen[$fingerprint])) return 'Duplicate DNS records are not allowed.';
        $seen[$fingerprint] = true;
        $byName[$name][$type] = true;
        if ($type === 'CNAME' && $name === '@') return 'The zone apex cannot be a CNAME.';
    }
    foreach ($byName as $types) {
        if (isset($types['CNAME']) && count($types) > 1) return 'A CNAME cannot coexist with another record at the same name.';
    }
    return null;
}
function dns_save_records(string $domain, array $records): array
{
    require_once APP_ROOT . '/lib/mod_sites.php';
    $domain = strtolower(rtrim($domain, '.'));
    if (!dns_name_labels_ok($domain)) return ['ok'=>false,'error'=>'Invalid canonical domain name.'];
    $allowed = array_map(fn($site)=>(string)($site['domain']??''), sites_list());
    if (!in_array($domain, $allowed, true)) return ['ok'=>false,'error'=>'Domain is not managed by this panel.'];
    if (($setError = dns_record_set_error($records)) !== null) return ['ok'=>false,'error'=>$setError];
    $zones=dns_zones();$previous=$zones[$domain]??null;
    $serial=max(time(),(int)($previous['serial']??0)+1);
    $published = dns_publish_zone($domain, $records, $serial);
    if (empty($published['ok'])) return $published;
    $zones[$domain]=['updated'=>date('c'),'serial'=>$serial,'records'=>array_values($records)];
    if (!write_json_file(dns_store_file(), ['version'=>1,'zones'=>$zones])) {
        if (is_array($previous)) dns_publish_zone($domain,(array)($previous['records']??[]),(int)($previous['serial']??time()));
        return ['ok'=>false,'error'=>'Could not save DNS records; the previous published zone was restored.'];
    }
    return $published;
}
/** Zones are stored under the canonical lower-case name without a trailing dot. */
function dns_canonical_domain(string $domain): string
{
    return strtolower(rtrim(trim($domain), '.'));
}

function dns_record_add(string $domain, array $input): array
{
    // Read and write under the same key: reading records for "Example.com"
    // but saving to "example.com" would replace the zone with one record.
    $domain=dns_canonical_domain($domain);
    $valid=dns_validate_record($input);if(empty($valid['ok']))return $valid;$records=dns_zone_records($domain);$records[]=$valid['record'];$res=dns_save_records($domain,$records);if(!empty($res['ok']))audit('dns.record.add',$domain.' '.($valid['record']['type']??''));return $res;
}
function dns_record_delete(string $domain, string $id): array
{
    $domain=dns_canonical_domain($domain);
    $records=dns_zone_records($domain);$next=array_values(array_filter($records,fn($r)=>(string)($r['id']??'')!==$id));if(count($next)===count($records))return ['ok'=>false,'error'=>'Record not found.'];$res=dns_save_records($domain,$next);if(!empty($res['ok']))audit('dns.record.delete',$domain.' '.$id);return $res;
}

function dns_forget_zone(string $domain): void
{
    $domain=dns_canonical_domain($domain);
    $zones=dns_zones();if(!isset($zones[$domain]))return;unset($zones[$domain]);write_json_file(dns_store_file(),['version'=>1,'zones'=>$zones]);
    if(helper_available())helper_cmd('dns-zone-delete '.escapeshellarg($domain),30);
    audit('dns.zone.delete',$domain);
}

/** Replace one record (keeping its id). */
function dns_record_update(string $domain, string $id, array $input): array
{
    $domain = dns_canonical_domain($domain);
    $valid = dns_validate_record($input);
    if (empty($valid['ok'])) { return $valid; }
    $records = dns_zone_records($domain);
    $found = false;
    foreach ($records as &$record) {
        if ((string) ($record['id'] ?? '') === $id) {
            $record = ['id' => $id] + $valid['record'];
            $found = true;
            break;
        }
    }
    unset($record);
    if (!$found) { return ['ok' => false, 'error' => 'Record not found.']; }
    $res = dns_save_records($domain, $records);
    if (!empty($res['ok'])) { audit('dns.record.update', $domain . ' ' . $id); }
    return $res;
}

/** The public IPv4 address records should point at (installer setting, else the first global address). */
function dns_server_ipv4(): string
{
    $configured = trim((string) @file_get_contents('/etc/nebula-panel/public-ip'));
    if (filter_var($configured, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { return $configured; }
    require_once APP_ROOT . '/lib/mod_domains.php';
    foreach (domain_server_ips() as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return $ip; }
    }
    return '';
}

/** Ready-made record sets. "replace" lists record kinds the template supersedes. */
function dns_templates(string $domain): array
{
    $ip = dns_server_ipv4();
    $msHost = str_replace('.', '-', $domain) . '.mail.protection.outlook.com';
    return [
        'website' => ['label' => 'Website on this server', 'needs_ip' => true, 'replace' => [], 'records' => [
            ['type' => 'A', 'name' => '@', 'value' => $ip],
            ['type' => 'CNAME', 'name' => 'www', 'value' => $domain],
        ]],
        'local-mail' => ['label' => 'Email on this server', 'needs_ip' => true, 'replace' => ['MX', 'SPF'], 'records' => [
            ['type' => 'A', 'name' => 'mail', 'value' => $ip],
            ['type' => 'MX', 'name' => '@', 'value' => 'mail.' . $domain, 'priority' => 10],
            ['type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 mx a -all'],
            ['type' => 'TXT', 'name' => '_dmarc', 'value' => 'v=DMARC1; p=quarantine; adkim=s; aspf=s'],
        ]],
        'google' => ['label' => 'Google Workspace email', 'needs_ip' => false, 'replace' => ['MX', 'SPF'], 'records' => [
            ['type' => 'MX', 'name' => '@', 'value' => 'smtp.google.com', 'priority' => 1],
            ['type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 include:_spf.google.com ~all'],
        ]],
        'microsoft' => ['label' => 'Microsoft 365 email', 'needs_ip' => false, 'replace' => ['MX', 'SPF'], 'records' => [
            ['type' => 'MX', 'name' => '@', 'value' => $msHost, 'priority' => 0],
            ['type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 include:spf.protection.outlook.com -all'],
            ['type' => 'CNAME', 'name' => 'autodiscover', 'value' => 'autodiscover.outlook.com'],
        ]],
        'letsencrypt-caa' => ['label' => 'Only Let’s Encrypt may issue certificates (CAA)', 'needs_ip' => false, 'replace' => [], 'records' => [
            ['type' => 'CAA', 'name' => '@', 'value' => '0 issue "letsencrypt.org"'],
        ]],
    ];
}

/** Add a template's records, replacing the apex MX/SPF records it supersedes. */
function dns_apply_template(string $domain, string $key): array
{
    $domain = dns_canonical_domain($domain);
    $template = dns_templates($domain)[$key] ?? null;
    if ($template === null) { return ['ok' => false, 'error' => 'Unknown template.']; }
    if ($template['needs_ip'] && dns_server_ipv4() === '') {
        return ['ok' => false, 'error' => 'This server’s public IPv4 address is unknown. Set PUBLIC_IP when running install.sh, or add the records by hand.'];
    }
    $records = dns_zone_records($domain);
    $records = array_values(array_filter($records, static function (array $record) use ($template): bool {
        $apex = ($record['name'] ?? '@') === '@';
        if (in_array('MX', $template['replace'], true) && $apex && $record['type'] === 'MX') { return false; }
        if (in_array('SPF', $template['replace'], true) && $apex && $record['type'] === 'TXT' && stripos((string) $record['value'], 'v=spf1') === 0) { return false; }
        return true;
    }));
    $added = 0;
    foreach ($template['records'] as $input) {
        $valid = dns_validate_record($input + ['ttl' => 3600]);
        if (empty($valid['ok'])) { return $valid; }
        foreach ($records as $record) {
            if (strcasecmp((string) $record['name'], (string) $valid['record']['name']) === 0 && $record['type'] === $valid['record']['type']
                && ($record['type'] === 'CNAME' || strcasecmp(rtrim((string) $record['value'], '.'), rtrim((string) $valid['record']['value'], '.')) === 0)) {
                continue 2; // already there (or a CNAME already holds the name)
            }
        }
        $records[] = $valid['record'];
        $added++;
    }
    $res = dns_save_records($domain, $records);
    if (!empty($res['ok'])) { audit('dns.template', $domain . ' ' . $key); $res['added'] = $added; }
    return $res;
}

/**
 * Import records from BIND zone-file text (what most registrars export).
 * SOA and apex NS records are skipped (Nebula generates them); records
 * already in the zone are left alone. Returns how many were added and which
 * lines could not be read.
 */
function dns_import(string $domain, string $text): array
{
    $domain = dns_canonical_domain($domain);
    if (strlen($text) > 262144) { return ['ok' => false, 'error' => 'The zone file is too large (256 KB at most).']; }
    $origin = $domain . '.';
    $defaultTtl = 3600;
    $lastName = '@';
    $records = dns_zone_records($domain);
    $added = 0;
    $skipped = [];
    // Drop comments outside quotes, then join parenthesised continuations.
    $clean = '';
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $out = '';
        $quoted = false;
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $c = $line[$i];
            if ($c === '"' && ($i === 0 || $line[$i - 1] !== '\\')) { $quoted = !$quoted; }
            if ($c === ';' && !$quoted) { break; }
            $out .= $c;
        }
        $clean .= $out . "\n";
    }
    // Keep the line count so skipped-line numbers match the pasted text.
    $clean = preg_replace_callback('/\(([^)]*)\)/s', static fn($m) => str_replace("\n", ' ', $m[1]) . str_repeat("\n", substr_count($m[1], "\n")), $clean) ?? $clean;
    foreach (explode("\n", $clean) as $number => $line) {
        if (trim($line) === '') { continue; }
        if (preg_match('/^\$ORIGIN\s+(\S+)/i', trim($line), $m)) { $origin = rtrim($m[1], '.') . '.'; continue; }
        if (preg_match('/^\$TTL\s+(\d+)/i', trim($line), $m)) { $defaultTtl = (int) $m[1]; continue; }
        if (str_starts_with(trim($line), '$')) { continue; }
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|\S+/', $line, $tokens);
        $tokens = $tokens[0];
        $name = ctype_space($line[0] ?? '') ? $lastName : array_shift($tokens);
        $ttl = $defaultTtl;
        $type = '';
        while ($tokens) {
            $token = array_shift($tokens);
            if (ctype_digit($token)) { $ttl = (int) $token; continue; }
            if (in_array(strtoupper($token), ['IN', 'CH', 'HS'], true)) { continue; }
            $type = strtoupper($token);
            break;
        }
        $lastName = $name;
        $fqdn = $name === '@' ? $origin : (str_ends_with($name, '.') ? $name : $name . '.' . $origin);
        $fqdn = strtolower($fqdn);
        if ($fqdn !== $domain . '.' && !str_ends_with($fqdn, '.' . $domain . '.')) { $skipped[] = 'line ' . ($number + 1) . ': outside ' . $domain; continue; }
        $relative = $fqdn === $domain . '.' ? '@' : substr($fqdn, 0, -strlen('.' . $domain . '.'));
        if ($type === 'SOA' || ($type === 'NS' && $relative === '@')) { continue; }
        $priority = 10;
        if ($type === 'MX') { $priority = (int) array_shift($tokens); }
        if ($type === 'TXT') {
            $value = implode('', array_map(static fn($t) => stripcslashes(trim($t, '"')), $tokens));
        } else {
            $value = implode(' ', $tokens);
            if (in_array($type, ['CNAME', 'MX', 'NS'], true) && $value !== '' && !str_ends_with($value, '.')) {
                $value = $value . '.' . rtrim($origin, '.'); // relative target
            }
            $value = in_array($type, ['CNAME', 'MX', 'NS'], true) ? rtrim($value, '.') : $value;
            if ($type === 'SRV' && preg_match('/^(\d+\s+\d+\s+\d+)\s+(\S+)$/', $value, $srv)) { $value = $srv[1] . ' ' . rtrim($srv[2], '.'); }
        }
        $valid = dns_validate_record(['type' => $type, 'name' => $relative, 'value' => $value, 'ttl' => max(60, min(604800, $ttl)), 'priority' => $priority]);
        if (empty($valid['ok'])) { $skipped[] = 'line ' . ($number + 1) . ': ' . $valid['error']; continue; }
        foreach ($records as $record) {
            if ($record['type'] === $valid['record']['type'] && strcasecmp((string) $record['name'], (string) $valid['record']['name']) === 0
                && (string) $record['value'] === (string) $valid['record']['value']) {
                continue 2;
            }
        }
        $records[] = $valid['record'];
        $added++;
    }
    if (!$added) {
        return ['ok' => false, 'error' => $skipped ? 'No records could be imported. ' . implode('; ', array_slice($skipped, 0, 5)) : 'No new records were found.', 'skipped' => $skipped];
    }
    $res = dns_save_records($domain, $records);
    if (!empty($res['ok'])) {
        audit('dns.import', $domain . ' ' . $added . ' records');
        $res['added'] = $added;
        $res['skipped'] = $skipped;
    }
    return $res;
}
