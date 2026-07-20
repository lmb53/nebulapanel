<?php
/** api/services — GET status list; POST {name, action} to control. */
global $config;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $body = read_json_body();
    $res = service_action(
        (string) ($body['name'] ?? ''),
        (string) ($body['action'] ?? ''),
        $config['services']
    );
    json_out($res, $res['ok'] ? 200 : 400);
}

json_out(['ok' => true, 'services' => services_overview($config['services'])]);
