<?php
/** GET api/health — actionable operational summary for the dashboard. */
require APP_ROOT . '/lib/mod_health.php';
$summary = cache_remember('health-summary', 30, 'health_summary');
// The cache is shared by every role; links are filtered per request.
if (isset($summary['items']) && is_array($summary['items'])) {
    $summary['items'] = items_linkable_for_role($summary['items']);
}
json_out($summary);
