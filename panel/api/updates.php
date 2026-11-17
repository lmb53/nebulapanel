<?php
/** api/updates — GET list; POST {action:refresh|upgrade|full-upgrade|autoremove|install|preview|auto-status|auto-set}. */
require APP_ROOT . '/lib/mod_updates.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    // Starts the NDJSON progress stream when ?stream=1 (or in a background job).
    $emit = stream_output_callback();
    if ($action === 'refresh') {
        $res = upd_refresh($emit);
    } elseif (in_array($action, ['upgrade', 'full-upgrade', 'autoremove'], true)) {
        $res = upd_upgrade($emit, $action);
    } elseif ($action === 'preview') {
        $res = upd_full_upgrade_preview();
    } elseif ($action === 'auto-status') {
        $res = upd_auto_status();
    } elseif ($action === 'auto-set') {
        $res = upd_auto_set(!empty($body['enabled']), !empty($body['reboot']) ? (string) ($body['reboot_time'] ?? '') : '', $emit);
    } elseif ($action === 'install') {
        $res = upd_install_package((string) ($body['package'] ?? ''), $emit);
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    action_out($res);
}

json_out(['ok' => true, 'count' => upd_count(), 'packages' => upd_list()]);
