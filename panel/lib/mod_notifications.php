<?php
/** Operational notifications derived from the panel's live health checks. */

function notifications_state_file(): string
{
    return DATA_DIR . '/notifications_read.json';
}

function notifications_state(): array
{
    $data = @json_decode((string) @file_get_contents(notifications_state_file()), true);
    $key = (string) ((int) ($_SESSION['uid'] ?? 0));
    $state = is_array($data['users'][$key] ?? null) ? $data['users'][$key] : (is_array($data) ? $data : []);
    return [
        'read' => array_values(array_unique((array) ($state['read'] ?? $state['ids'] ?? []))),
        'dismissed' => array_values(array_unique((array) ($state['dismissed'] ?? []))),
    ];
}

function notifications_save_state(array $state): bool
{
    return !empty(notifications_update_state(static fn(array $current): array => $state)['ok']);
}

/** Mutate one user's notification state without losing concurrent updates. */
function notifications_update_state(callable $mutator): array
{
    $path=notifications_state_file();$lock=@fopen($path.'.lock','c');
    if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))fclose($lock);return ['ok'=>false];}
    $data=@json_decode((string)@file_get_contents($path),true);
    $data=is_array($data)&&isset($data['users'])?$data:['version'=>2,'users'=>[]];
    $key=(string)((int)($_SESSION['uid']??0));
    $current=is_array($data['users'][$key]??null)?$data['users'][$key]:['read'=>[],'dismissed'=>[]];
    $state=$mutator([
        'read'=>array_values(array_unique((array)($current['read']??$current['ids']??[]))),
        'dismissed'=>array_values(array_unique((array)($current['dismissed']??[]))),
    ]);
    if(!is_array($state)){@flock($lock,LOCK_UN);fclose($lock);return ['ok'=>false];}
    $data['users'][$key]=[
        'updated_at' => time(),
        'read' => array_values(array_unique((array) ($state['read'] ?? []))),
        'dismissed' => array_values(array_unique((array) ($state['dismissed'] ?? []))),
    ];
    $ok=write_json_file($path,$data);@flock($lock,LOCK_UN);fclose($lock);return ['ok'=>$ok,'state'=>$state];
}

function notifications_items(): array
{
    require_once APP_ROOT . '/lib/mod_health.php';
    $health = cache_remember('health-summary', 30, 'health_summary');
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
    $result = notifications_update_state(static function(array $state) use($ids): array {
        $state['read'] = array_values(array_unique(array_merge($state['read'], $ids)));
        return $state;
    });
    $ok = !empty($result['ok']);
    if ($ok) { audit('notifications.read_all'); }
    return $ok;
}

function notifications_mark_read(string $id): bool
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) { return false; }
    $result = notifications_update_state(static function(array $state) use($id): array {$state['read'][]=$id;return $state;});
    $ok = !empty($result['ok']);
    if ($ok) { audit('notifications.read', $id); }
    return $ok;
}

function notifications_delete(string $id): bool
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) { return false; }
    $result = notifications_update_state(static function(array $state) use($id): array {$state['dismissed'][]=$id;return $state;});
    $ok = !empty($result['ok']);
    if ($ok) { audit('notifications.delete', $id); }
    return $ok;
}
