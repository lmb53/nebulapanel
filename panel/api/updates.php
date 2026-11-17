<?php
/** api/updates — GET list; POST {action:refresh|upgrade|full-upgrade|autoremove|install|preview|auto-status|auto-set}. */
require APP_ROOT . '/lib/mod_updates.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    $streaming = ($_GET['stream'] ?? '') === '1';
    $emit = null;
    if ($streaming) {
        stream_json_start();
        stream_json_event(['type' => 'start']);
        $emit = static function (string $text, string $channel): void {
            stream_json_event(['type' => 'output', 'channel' => $channel, 'text' => $text]);
        };
    }
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
    if ($streaming) {
        stream_json_event(['type' => 'result', 'result' => $res]);
        exit;
    }
    json_out($res, $res['ok'] ? 200 : 400);
}

json_out(['ok' => true, 'count' => upd_count(), 'packages' => upd_list()]);
