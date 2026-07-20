<?php
/** Operational notifications derived from the panel's live health checks. */

function notifications_read_file(): string
{
    return DATA_DIR . '/notifications_read.json';
}

function notifications_items(): array
{
    require_once APP_ROOT . '/lib/mod_health.php';
    $health = health_summary();
    $read = @json_decode((string) @file_get_contents(notifications_read_file()), true);
    $readIds = is_array($read) ? (array) ($read['ids'] ?? []) : [];
    $checked = (int) ($health['checked_at'] ?? time());
    $items = [];
    foreach ((array) ($health['items'] ?? []) as $item) {
        $item['id'] = substr(hash('sha256', ($item['title'] ?? '') . '|' . ($item['detail'] ?? '') . '|' . ($item['route'] ?? '')), 0, 16);
        $item['created_at'] = $checked;
        $item['read'] = in_array($item['id'], $readIds, true);
        $items[] = $item;
    }
    return $items;
}

function notifications_mark_all_read(): bool
{
    $ids = array_column(notifications_items(), 'id');
    $ok = write_json_file(notifications_read_file(), ['read_at' => time(), 'ids' => $ids]);
    if ($ok) { audit('notifications.read_all'); }
    return $ok;
}
