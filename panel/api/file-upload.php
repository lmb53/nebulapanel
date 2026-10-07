<?php
/** POST api/file-upload (multipart) {dir, file} — upload a file into FM_ROOT. */
require APP_ROOT . '/lib/files.php';
require_post();
csrf_check();

// A body over post_max_size reaches PHP with $_FILES and $_POST emptied, which
// would otherwise be reported as "No file uploaded."
$postMax = ini_size_bytes((string) ini_get('post_max_size'));
if ($postMax > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMax) {
    json_out(['ok' => false, 'error' => 'The upload exceeds PHP post_max_size (' . ini_get('post_max_size')
        . '). Re-run install.sh to apply the panel\'s upload limits.'], 413);
}

$dir = (string) ($_POST['dir'] ?? '');
$overwrite = filter_var($_POST['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);
$res = fm_upload($dir, $_FILES['file'] ?? [], $overwrite);
json_out($res, $res['ok'] ? 200 : (!empty($res['conflict']) ? 409 : 400));
