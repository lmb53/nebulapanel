<?php
/**
 * api/jobs — background jobs.
 *   GET                     → {jobs: [...]} recent jobs you may see
 *   GET  id=<id>&offset=<n> → {job, events, offset} (events since byte offset)
 *   POST {action: start, endpoint, body} → {job}
 */
require_once APP_ROOT . '/lib/mod_jobs.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $request = read_json_body(JOB_MAX_BODY + 4096);
    if ((string) ($request['action'] ?? '') !== 'start') {
        json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
    $res = job_start((string) ($request['endpoint'] ?? ''), is_array($request['body'] ?? null) ? $request['body'] : []);
    json_out($res, !empty($res['ok']) ? 200 : (!empty($res['forbidden']) ? 403 : 400));
}

$id = (string) ($_GET['id'] ?? '');
if ($id === '') {
    json_out(['ok' => true, 'jobs' => jobs_list()]);
}
$job = job_load($id);
if ($job === null || !job_visible($job)) {
    json_out(['ok' => false, 'error' => 'Job not found.'], 404);
}
$job = job_reap($job);
$events = job_events($id, (int) ($_GET['offset'] ?? 0));
json_out(['ok' => true, 'job' => job_public($job)] + $events);
