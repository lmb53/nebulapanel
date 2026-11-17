<?php
/** api/git — GET checkout status for a site; POST connect|pull|disconnect. */
require APP_ROOT . '/lib/mod_git.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('websites.manage');
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    $domain = (string) ($body['domain'] ?? '');
    // Starts the NDJSON progress stream when ?stream=1 (or in a background job).
    $emit = stream_output_callback();

    switch ($action) {
        case 'connect':
            $res = git_connect($domain, (string) ($body['url'] ?? ''), (string) ($body['branch'] ?? 'main'), $emit);
            break;
        case 'pull':
            $res = git_pull($domain, $emit);
            break;
        case 'deploy-key':
            $res = git_deploy_key($domain);
            break;
        case 'hook-rotate':
            $res = git_hook_rotate($domain);
            break;
        case 'hook-disable':
            $res = git_hook_disable($domain);
            break;
        case 'disconnect':
            $res = git_disconnect($domain, (bool) ($body['remove'] ?? false));
            break;
        default:
            $res = ['ok' => false, 'error' => 'Unknown action.'];
    }

    action_out($res);
}

require_capability('websites.manage');
json_out(git_status((string) ($_GET['domain'] ?? '')));
