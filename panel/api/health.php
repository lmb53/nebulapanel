<?php
/** GET api/health — actionable operational summary for the dashboard. */
require APP_ROOT . '/lib/mod_health.php';
json_out(health_summary());
