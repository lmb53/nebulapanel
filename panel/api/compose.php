<?php
/** api/compose — GET stacks + app catalog; POST compose lifecycle actions. */
require APP_ROOT . '/lib/mod_compose.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('docker.manage');
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    $name = (string) ($body['name'] ?? '');
    $streaming = ($_GET['stream'] ?? '') === '1';
    $emit = null;
    if ($streaming) {
        stream_json_start();
        stream_json_event(['type' => 'start']);
        $emit = static function (string $text, string $channel): void {
            stream_json_event(['type' => 'output', 'channel' => $channel, 'text' => $text]);
        };
    }

    // Everything except installing Compose itself needs a working Compose CLI.
    // Report *why* it is missing rather than a bare "not available".
    if ($action !== 'install-compose' && !compose_available()) {
        $res = ['ok' => false, 'error' => compose_availability()['reason']];
        if ($streaming) {
            stream_json_event(['type' => 'result', 'result' => $res]);
            exit;
        }
        json_out($res, 400);
    }

    switch ($action) {
        case 'install-compose':
            $res = compose_install($emit);
            break;
        case 'save':
            $res = compose_save($name, (string) ($body['content'] ?? ''), (bool) ($body['create'] ?? false));
            break;
        case 'read':
            $res = compose_read($name);
            break;
        case 'logs':
            $res = compose_logs($name, (int) ($body['lines'] ?? 200));
            break;
        case 'install':
            // Create a stack from an app-store template, then bring it up.
            $created = compose_install_template((string) ($body['key'] ?? ''), $name);
            if (empty($created['ok'])) { $res = $created; break; }
            $res = compose_action($created['name'], 'up', $emit);
            $res['name'] = $created['name'];
            break;
        case 'up':
        case 'down':
        case 'stop':
        case 'start':
        case 'restart':
        case 'pull':
            $res = compose_action($name, $action, $emit);
            break;
        case 'remove':
            $res = compose_remove($name, (bool) ($body['volumes'] ?? false), $emit);
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

require_capability('docker.manage');
$avail = compose_availability();
json_out([
    'ok'          => true,
    'available'   => $avail['available'],
    'installable' => $avail['installable'],
    'reason'      => $avail['reason'],
    'bin'         => $avail['bin'],
    'stacks'      => $avail['available'] ? compose_list() : [],
    'catalog'     => compose_catalog_list(),
]);
