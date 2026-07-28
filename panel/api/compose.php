<?php
/** api/compose — GET stacks + app catalog; POST compose lifecycle actions. */
require APP_ROOT . '/lib/mod_compose.php';

/**
 * Auto-publish a stack's ports and describe the outcome in the install log.
 * Proxy problems are reported but never turn a successful deploy into a
 * failure — the containers are up either way.
 */
function compose_report_autoproxy(string $stack, ?callable $emit): array
{
    $proxy = compose_autoproxy($stack);
    if (!$emit) { return $proxy; }
    foreach ($proxy['removed'] as $domain) {
        $emit("Removed stale hostname $domain\n", 'stdout');
    }
    foreach ($proxy['created'] as $domain) {
        $emit("Published on http://$domain/ (point this name's DNS at this server)\n", 'stdout');
    }
    foreach ($proxy['errors'] as $error) {
        $emit("Could not publish $error\n", 'stderr');
    }
    if (!$proxy['created'] && !$proxy['errors'] && compose_proxy_domain() === '' && compose_stack_ports($stack)) {
        $emit("Ports bind to loopback only. Set a base domain in Docker \u2192 Stacks to publish stacks automatically.\n", 'stdout');
    }
    return $proxy;
}

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
            if (!empty($res['ok'])) { $res['proxy'] = compose_report_autoproxy($created['name'], $emit); }
            break;
        case 'up':
        case 'down':
        case 'stop':
        case 'start':
        case 'restart':
        case 'pull':
            $res = compose_action($name, $action, $emit);
            // Deploying is also when a hand-edited stack's ports change, so
            // reconcile its proxies against what it now publishes.
            if ($action === 'up' && !empty($res['ok'])) { $res['proxy'] = compose_report_autoproxy($name, $emit); }
            break;
        case 'remove':
            $res = compose_remove($name, (bool) ($body['volumes'] ?? false), $emit);
            break;
        case 'proxy-create':
            $res = compose_proxy_create((string) ($body['domain'] ?? ''), $name, (int) ($body['port'] ?? 0));
            break;
        case 'proxy-remove':
            $res = compose_proxy_remove((string) ($body['domain'] ?? ''));
            break;
        case 'proxy-domain':
            $res = compose_set_proxy_domain((string) ($body['domain'] ?? ''));
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
    'proxyDomain' => compose_proxy_domain(),
    'catalog'     => compose_catalog_list(),
]);
