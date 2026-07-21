<?php
/** POST api/file-upload (multipart) {dir, file} — upload a file into FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

$dir = (string) ($_POST['dir'] ?? '');
$overwrite = filter_var($_POST['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);
$res = fm_upload($dir, $_FILES['file'] ?? [], $overwrite);
json_out($res, $res['ok'] ? 200 : (!empty($res['conflict']) ? 409 : 400));
