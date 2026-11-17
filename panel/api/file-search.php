<?php
/** GET api/file-search&path=<folder>&q=<text> — names containing q below the folder. */
require APP_ROOT . '/lib/files.php';
require_capability('files.manage');
$res = fm_search((string) ($_GET['path'] ?? ''), (string) ($_GET['q'] ?? ''));
json_out($res, !empty($res['ok']) ? 200 : 400);
