<?php
require APP_ROOT . '/lib/mod_notifications.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    if (($body['action'] ?? '') !== 'mark-all-read') {
        json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
    $ok = notifications_mark_all_read();
    json_out(['ok' => $ok], $ok ? 200 : 500);
}
$items = notifications_items();
json_out(['ok' => true, 'unread' => count(array_filter($items, fn($item) => empty($item['read']))), 'items' => $items]);
