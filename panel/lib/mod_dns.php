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
function dns_record_name_ok(string $name): bool { return $name === '@' || (bool) preg_match('/^(\*\.)?[A-Za-z0-9_](?:[A-Za-z0-9_.-]{0,251}[A-Za-z0-9_])?$/', $name); }
function dns_target_ok(string $value): bool { return (bool) preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?\.?$/', $value); }

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
    if ($type === 'CAA' && !preg_match('/^\d+\s+(issue|issuewild|iodef)\s+"[^"]+"$/', $value)) return ['ok'=>false,'error'=>'CAA value must look like: 0 issue "letsencrypt.org"'];
    if ($type === 'SRV' && !preg_match('/^\d+\s+\d+\s+\d+\s+[A-Za-z0-9.-]+\.?$/', $value)) return ['ok'=>false,'error'=>'SRV value must be: priority weight port target'];
    return ['ok'=>true,'record'=>['id'=>bin2hex(random_bytes(6)),'type'=>$type,'name'=>$name,'value'=>$value,'ttl'=>$ttl,'priority'=>$type==='MX'?$priority:null]];
}

function dns_fqdn(string $name, string $domain): string
{
    return $name === '@' ? '@' : (str_ends_with($name, '.') ? $name : $name);
}
function dns_zone_text(string $domain, array $records): string
{
    $ns = dns_nameservers(); $primary = rtrim((string) ($ns[0] ?? 'ns1.' . $domain), '.') . '.';
    $mailbox = 'hostmaster.' . rtrim($domain, '.') . '.'; $serial = (string) time();
    $lines = ['$TTL 3600', '@ IN SOA ' . $primary . ' ' . $mailbox . ' (', '  ' . $serial . ' 3600 900 1209600 300', ')'];
    foreach ($ns as $server) $lines[] = '@ 86400 IN NS ' . rtrim($server, '.') . '.';
    foreach ($records as $record) {
        $name = dns_fqdn((string) $record['name'], $domain); $ttl=(int)$record['ttl']; $type=(string)$record['type']; $value=(string)$record['value'];
        if (in_array($type, ['CNAME','MX','NS'], true)) $value = rtrim($value, '.') . '.';
        if ($type === 'TXT') $value = '"' . addcslashes($value, "\\\"") . '"';
        if ($type === 'MX') $value = (int) ($record['priority'] ?? 10) . ' ' . $value;
        $lines[] = $name . ' ' . $ttl . ' IN ' . $type . ' ' . $value;
    }
    return implode("\n", $lines) . "\n";
}

function dns_publish_zone(string $domain, array $records): array
{
    if (!helper_available()) return ['ok'=>true,'published'=>false,'warning'=>'Saved in Nebula; re-run install.sh to enable authoritative BIND publishing.'];
    [$code,$out] = helper_cmd('dns-zone-put ' . escapeshellarg($domain) . ' ' . escapeshellarg(base64_encode(dns_zone_text($domain, $records))), 30);
    return $code === 0 ? ['ok'=>true,'published'=>true] : ['ok'=>false,'error'=>sudo_error($out,$code)];
}
function dns_save_records(string $domain, array $records): array
{
    require_once APP_ROOT . '/lib/mod_sites.php';
    $allowed = array_map(fn($site)=>(string)($site['domain']??''), sites_list());
    if (!in_array($domain, $allowed, true)) return ['ok'=>false,'error'=>'Domain is not managed by this panel.'];
    $zones=dns_zones();$zones[$domain]=['updated'=>date('c'),'records'=>array_values($records)];
    if (!write_json_file(dns_store_file(), ['version'=>1,'zones'=>$zones])) return ['ok'=>false,'error'=>'Could not save DNS records.'];
    $published = dns_publish_zone($domain, $records);
    if (empty($published['ok'])) return ['ok'=>true,'published'=>false,'warning'=>'Record saved, but BIND could not publish it: '.($published['error']??'unknown error')];
    return $published;
}
function dns_record_add(string $domain, array $input): array
{
    $valid=dns_validate_record($input);if(empty($valid['ok']))return $valid;$records=dns_zone_records($domain);$records[]=$valid['record'];$res=dns_save_records($domain,$records);if(!empty($res['ok']))audit('dns.record.add',$domain.' '.($valid['record']['type']??''));return $res;
}
function dns_record_delete(string $domain, string $id): array
{
    $records=dns_zone_records($domain);$next=array_values(array_filter($records,fn($r)=>(string)($r['id']??'')!==$id));if(count($next)===count($records))return ['ok'=>false,'error'=>'Record not found.'];$res=dns_save_records($domain,$next);if(!empty($res['ok']))audit('dns.record.delete',$domain.' '.$id);return $res;
}

function dns_forget_zone(string $domain): void
{
    $zones=dns_zones();if(!isset($zones[$domain]))return;unset($zones[$domain]);write_json_file(dns_store_file(),['version'=>1,'zones'=>$zones]);
    if(helper_available())helper_cmd('dns-zone-delete '.escapeshellarg($domain),30);
    audit('dns.zone.delete',$domain);
}
