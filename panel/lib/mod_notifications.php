<?php
/** Operational notifications derived from the panel's live health checks. */

function notifications_state_file(): string
{
    return DATA_DIR . '/notifications_read.json';
}

function notifications_state(): array
{
    $state = @json_decode((string) @file_get_contents(notifications_state_file()), true);
    return is_array($state) ? [
        'read' => array_values(array_unique((array) ($state['read'] ?? $state['ids'] ?? []))),
        'dismissed' => array_values(array_unique((array) ($state['dismissed'] ?? []))),
    ] : ['read' => [], 'dismissed' => []];
}

function notifications_save_state(array $state): bool
{
    return write_json_file(notifications_state_file(), [
        'updated_at' => time(),
        'read' => array_values(array_unique((array) ($state['read'] ?? []))),
        'dismissed' => array_values(array_unique((array) ($state['dismissed'] ?? []))),
    ]);
}

function notifications_items(): array
{
    require_once APP_ROOT . '/lib/mod_health.php';
    $health = health_summary();
    $state = notifications_state();
    $checked = (int) ($health['checked_at'] ?? time());
    $items = [];
    foreach ((array) ($health['items'] ?? []) as $item) {
        $item['id'] = substr(hash('sha256', ($item['title'] ?? '') . '|' . ($item['detail'] ?? '') . '|' . ($item['route'] ?? '')), 0, 16);
        if (in_array($item['id'], $state['dismissed'], true)) { continue; }
        $item['created_at'] = $checked;
        $item['read'] = in_array($item['id'], $state['read'], true);
        $items[] = $item;
    }
    return $items;
}

function notifications_mark_all_read(): bool
{
    $ids = array_column(notifications_items(), 'id');
    $state = notifications_state();
    $state['read'] = array_values(array_unique(array_merge($state['read'], $ids)));
    $ok = notifications_save_state($state);
    if ($ok) { audit('notifications.read_all'); }
    return $ok;
}

function notifications_mark_read(string $id): bool
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) { return false; }
    $state = notifications_state();
    $state['read'][] = $id;
    $ok = notifications_save_state($state);
    if ($ok) { audit('notifications.read', $id); }
    return $ok;
}

function notifications_delete(string $id): bool
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) { return false; }
    $state = notifications_state();
    $state['dismissed'][] = $id;
    $ok = notifications_save_state($state);
    if ($ok) { audit('notifications.delete', $id); }
    return $ok;
}
