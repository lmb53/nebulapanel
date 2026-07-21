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
    $streaming = ($_GET['stream'] ?? '') === '1';
    $emit = null;
    if ($streaming) {
        stream_json_start();
        stream_json_event(['type' => 'start']);
        $emit = static function (string $text, string $channel): void {
            stream_json_event(['type' => 'output', 'channel' => $channel, 'text' => $text]);
        };
    }

    switch ($action) {
        case 'connect':
            $res = git_connect($domain, (string) ($body['url'] ?? ''), (string) ($body['branch'] ?? 'main'), $emit);
            break;
        case 'pull':
            $res = git_pull($domain, $emit);
            break;
        case 'disconnect':
            $res = git_disconnect($domain, (bool) ($body['remove'] ?? false));
            break;
        default:
            $res = ['ok' => false, 'error' => 'Unknown action.'];
    }

    if ($streaming) {
        stream_json_event(['type' => 'result', 'result' => $res]);
        exit;
    }
    json_out($res, !empty($res['ok']) ? 200 : 400);
}

require_capability('websites.manage');
json_out(git_status((string) ($_GET['domain'] ?? '')));
