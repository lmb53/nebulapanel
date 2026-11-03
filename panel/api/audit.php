<?php
/**
 * GET api/audit — search the audit log.
 *   q, actor, action, ip, from, to (YYYY-MM-DD), limit, offset
 *   format=csv|json downloads every match (up to 50,000).
 */
require APP_ROOT . '/lib/mod_settings.php';
$filters = [
    'q' => substr((string) ($_GET['q'] ?? ''), 0, 200),
    'actor' => substr((string) ($_GET['actor'] ?? ''), 0, 120),
    'action' => substr((string) ($_GET['action'] ?? ''), 0, 120),
    'ip' => substr((string) ($_GET['ip'] ?? ''), 0, 64),
    'from' => (string) ($_GET['from'] ?? ''),
    'to' => (string) ($_GET['to'] ?? ''),
];
$format = (string) ($_GET['format'] ?? '');
if ($format === 'csv' || $format === 'json') {
    audit_export($filters, $format);
}
json_out(audit_search($filters, (int) ($_GET['limit'] ?? 100), (int) ($_GET['offset'] ?? 0)));
