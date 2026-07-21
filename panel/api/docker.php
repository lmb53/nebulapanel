<?php
/** api/docker — GET containers+images; POST {action:container|image_remove, ...}. */
require APP_ROOT . '/lib/mod_docker.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('docker.manage');
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'container') {
        $id = (string) ($body['id'] ?? '');
        $op = (string) ($body['op'] ?? '');
        if (!in_array($op, ['start', 'stop', 'restart', 'remove'], true)) {
            $res = ['ok' => false, 'error' => 'Invalid operation.'];
        } else {
            $res = dk_container_action($id, $op);
        }
    } elseif ($action === 'create_container') {
        $res = dk_container_create($body);
    } elseif ($action === 'image_pull') {
        $res = dk_image_pull((string) ($body['image'] ?? ''));
    } elseif ($action === 'image_remove') {
        $res = dk_image_remove((string) ($body['id'] ?? ''));
    } elseif (in_array($action, ['volume_create','volume_remove','network_create','network_remove'], true)) {
        [$kind,$op] = explode('_',$action,2); $res = dk_named_resource($kind,$op === 'remove' ? 'rm' : 'create',(string)($body['name']??''));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    json_out($res, $res['ok'] ? 200 : 400);
}

json_out([
    'ok'         => true,
    'containers' => dk_containers()['containers'] ?? [],
    'images'     => dk_images()['images'] ?? [],
    'volumes'    => dk_volumes()['volumes'] ?? [],
    'networks'   => dk_networks()['networks'] ?? [],
    'version'    => dk_version(),
]);
