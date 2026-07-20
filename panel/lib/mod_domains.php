<?php
/** Domain and public-DNS inspection for sites tracked by the panel. */

function domain_server_ips(): array
{
    $ips = [];
    [$code, $out] = run_cmd('hostname -I 2>/dev/null');
    if ($code === 0) {
        foreach (preg_split('/\s+/', trim($out)) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) { $ips[] = $ip; }
        }
    }
    return array_values(array_unique($ips));
}

function domain_dns_records(string $domain): array
{
    if (!sv_domain_ok($domain) || !function_exists('dns_get_record')) { return []; }
    $types = DNS_A | DNS_AAAA | DNS_CNAME | DNS_MX | DNS_NS | DNS_TXT;
    $records = @dns_get_record($domain, $types);
    return is_array($records) ? $records : [];
}

function domain_record_value(array $record): string
{
    foreach (['ip', 'ipv6', 'target', 'txt'] as $key) {
        if (isset($record[$key])) { return (string) $record[$key]; }
    }
    return '';
}

function domain_points_here(array $records, array $serverIps): ?bool
{
    if (!$serverIps) { return null; }
    $resolved = [];
    foreach ($records as $record) {
        if (isset($record['ip'])) { $resolved[] = $record['ip']; }
        if (isset($record['ipv6'])) { $resolved[] = $record['ipv6']; }
    }
    if (!$resolved) { return false; }
    return (bool) array_intersect($resolved, $serverIps);
}
