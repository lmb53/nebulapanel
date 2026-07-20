<?php
/** POST api/file-upload (multipart) {dir, file} — upload a file into FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

$dir = (string) ($_POST['dir'] ?? '');
$res = fm_upload($dir, $_FILES['file'] ?? []);
json_out($res, $res['ok'] ? 200 : 400);
