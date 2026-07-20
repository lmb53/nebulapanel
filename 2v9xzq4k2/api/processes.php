<?php
/** api/processes — GET only; top processes + total count. */
require APP_ROOT . '/lib/mod_monitor.php';

json_out(['ok' => true, 'processes' => top_processes(20), 'count' => process_count()]);
