<?php
/**
 * GET api/search — things the command palette can jump to besides pages:
 * websites, DNS zones, mail domains and mailboxes, databases and Docker
 * stacks. Read from the panel's own state files only (no helper calls), and
 * limited to the pages the caller's role may open.
 */
$items = [];
$add = static function (string $route, array $params, string $title, string $section, string $icon, string $keywords = '') use (&$items): void {
    if (!role_route_allowed($route) || count($items) >= 2000) { return; }
    $items[] = ['href' => url($route, $params), 'title' => $title, 'section' => $section, 'icon' => $icon,
        'label' => strtolower($title . ' ' . $section . ' ' . $keywords)];
};

require_once APP_ROOT . '/lib/mod_sites.php';
foreach (sites_stored() as $site) {
    if (!empty($site['domain']) && preg_match('/^[a-f0-9]{32}$/', (string) ($site['id'] ?? ''))) {
        $add('site', ['id' => $site['id']], (string) $site['domain'], 'Website', 'globe', 'site domain php ' . ($site['php'] ?? ''));
    }
}
$zones = @json_decode((string) @file_get_contents(DATA_DIR . '/dns-zones.json'), true);
foreach (array_keys(is_array($zones) ? $zones : []) as $zone) {
    $add('dns', ['domain' => $zone], (string) $zone, 'DNS zone', 'network', 'records zone');
}
$mail = @json_decode((string) @file_get_contents(DATA_DIR . '/mail.json'), true);
if (is_array($mail)) {
    foreach (array_keys((array) ($mail['domains'] ?? [])) as $domain) {
        $add('mail', ['domain' => $domain], (string) $domain, 'Mail domain', 'mail', 'email');
    }
    foreach ((array) ($mail['accounts'] ?? []) as $account) {
        $email = (string) ($account['email'] ?? '');
        if ($email !== '') {
            $add('mail', ['domain' => substr($email, strpos($email, '@') + 1)], $email, 'Mailbox', 'inbox', 'email account');
        }
    }
}
$links = @json_decode((string) @file_get_contents(DATA_DIR . '/database-links.json'), true);
foreach ((is_array($links) ? $links : []) as $database => $website) {
    $add('databases', ['website' => $website], (string) $database, 'Database', 'database', 'mysql ' . $website);
}
foreach (glob(DATA_DIR . '/stacks/*/docker-compose.yml') ?: [] as $file) {
    $stack = basename(dirname($file));
    $add('docker', [], $stack, 'Docker stack', 'layers-3', 'compose container app');
}
json_out(['ok' => true, 'items' => $items]);
