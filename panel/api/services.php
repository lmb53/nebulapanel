<?php
/** api/services — GET status list; POST {name, action} to control. */
global $config;
require APP_ROOT . '/lib/mod_apps.php';

// Controllable set = configured services + any installed manageable unit.
$allowed = array_values(array_unique(array_merge($config['services'], manageable_units())));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('services.control');
    $body = read_json_body();
    $res = service_action(
        (string) ($body['name'] ?? ''),
        (string) ($body['action'] ?? ''),
        $allowed
    );
    json_out($res, $res['ok'] ? 200 : 400);
}

json_out(['ok' => true, 'services' => services_overview($allowed)]);
