<?php
/** GET api/health — actionable operational summary for the dashboard. */
require APP_ROOT . '/lib/mod_health.php';
json_out(cache_remember('health-summary', 30, 'health_summary'));
