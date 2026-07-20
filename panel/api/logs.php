<?php
/** api/logs — GET only. ?source=<id>&lines=<n> => text; otherwise => sources. */
require APP_ROOT . '/lib/mod_logs.php';

if (isset($_GET['source'])) {
    json_out(['ok' => true, 'text' => log_read((string) $_GET['source'], (int) ($_GET['lines'] ?? 200))]);
}

json_out(['ok' => true, 'sources' => log_sources()]);
