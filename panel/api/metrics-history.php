<?php
/** GET api/metrics-history&range=6h|24h|7d — downsampled resource history. */
require APP_ROOT . '/lib/mod_history.php';
json_out(history_series((string) ($_GET['range'] ?? '24h')));
