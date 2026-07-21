<?php
require APP_ROOT . '/lib/mod_notifications.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'mark-all-read') {
        $ok = notifications_mark_all_read();
    } elseif ($action === 'mark-read') {
        $ok = notifications_mark_read((string) ($body['id'] ?? ''));
    } elseif ($action === 'delete') {
        $ok = notifications_delete((string) ($body['id'] ?? ''));
    } else {
        json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
    json_out(['ok' => $ok], $ok ? 200 : 500);
}
$items = notifications_items();
json_out(['ok' => true, 'unread' => count(array_filter($items, fn($item) => empty($item['read']))), 'items' => $items]);
