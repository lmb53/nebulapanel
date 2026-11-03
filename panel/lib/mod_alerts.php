<?php
/**
 * Alerts — tell someone when the server needs attention while nobody is
 * looking at the panel. The scheduler evaluates the dashboard's health checks
 * every five minutes and notifies on change: a new problem, an escalation
 * (warning → critical), a daily reminder while it persists, and recovery.
 *
 * Channels: email through the local MTA (sendmail), and webhooks for Slack,
 * Discord, ntfy or any endpoint that accepts JSON.
 */

const ALERT_MAX_RECIPIENTS = 5;
const ALERT_MAX_WEBHOOKS = 5;

/** Alert categories shown in Settings, mapped from health-check keys. */
function alert_categories(): array
{
    return [
        'resources' => ['label' => 'Disk, memory and load', 'keys' => ['disk', 'memory', 'load'], 'default' => true],
        'services' => ['label' => 'Failed services', 'keys' => ['services'], 'default' => true],
        'backups' => ['label' => 'Backups old or failing', 'keys' => ['backups', 'backup_failed'], 'default' => true],
        'ssl' => ['label' => 'SSL certificates expiring', 'keys' => ['ssl'], 'default' => true],
        'panel' => ['label' => 'Panel problems (helper, scheduler, storage)', 'keys' => ['helper', 'scheduler', 'data_dir'], 'default' => true],
        'updates' => ['label' => 'Updates and reboot required', 'keys' => ['updates', 'reboot'], 'default' => false],
    ];
}

function alert_webhook_types(): array
{
    return ['slack' => 'Slack', 'discord' => 'Discord', 'ntfy' => 'ntfy', 'generic' => 'Generic JSON'];
}

function alerts_file(): string { return DATA_DIR . '/alerts.json'; }
function alerts_state_file(): string { return DATA_DIR . '/alerts-state.json'; }

function alerts_config(): array
{
    $saved = @json_decode((string) @file_get_contents(alerts_file()), true);
    $saved = is_array($saved) ? $saved : [];
    $events = [];
    foreach (alert_categories() as $key => $category) {
        $events[$key] = (bool) ($saved['events'][$key] ?? $category['default']);
    }
    return [
        'enabled' => (bool) ($saved['enabled'] ?? false),
        'emails' => array_values(array_filter((array) ($saved['emails'] ?? []), 'is_string')),
        'webhooks' => array_values(array_filter((array) ($saved['webhooks'] ?? []), 'is_array')),
        'events' => $events,
        'notify_resolved' => (bool) ($saved['notify_resolved'] ?? true),
        'remind_hours' => max(0, min(168, (int) ($saved['remind_hours'] ?? 24))),
    ];
}

function alerts_state(): array
{
    $state = @json_decode((string) @file_get_contents(alerts_state_file()), true);
    return is_array($state) ? $state + ['active' => [], 'deliveries' => []] : ['active' => [], 'deliveries' => []];
}

/** Validate and save the alert settings. */
function alerts_save(array $input): array
{
    $emails = [];
    $rawEmails = is_array($input['emails'] ?? null) ? $input['emails'] : preg_split('/[\s,;]+/', (string) ($input['emails'] ?? ''));
    foreach ($rawEmails as $email) {
        $email = trim((string) $email);
        if ($email === '') { continue; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
            return ['ok' => false, 'error' => 'Invalid email address: ' . $email];
        }
        $emails[] = $email;
    }
    $emails = array_values(array_unique($emails));
    if (count($emails) > ALERT_MAX_RECIPIENTS) { return ['ok' => false, 'error' => 'Use at most ' . ALERT_MAX_RECIPIENTS . ' email recipients.']; }
    $webhooks = [];
    foreach ((array) ($input['webhooks'] ?? []) as $hook) {
        $url = trim((string) ($hook['url'] ?? ''));
        $type = (string) ($hook['type'] ?? 'generic');
        if ($url === '') { continue; }
        if (!isset(alert_webhook_types()[$type])) { return ['ok' => false, 'error' => 'Unsupported webhook type.']; }
        if (strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'error' => 'Webhook URLs must be http:// or https:// addresses.'];
        }
        $webhooks[] = ['type' => $type, 'url' => $url];
    }
    if (count($webhooks) > ALERT_MAX_WEBHOOKS) { return ['ok' => false, 'error' => 'Use at most ' . ALERT_MAX_WEBHOOKS . ' webhooks.']; }
    $events = [];
    foreach (alert_categories() as $key => $category) { $events[$key] = (bool) ($input['events'][$key] ?? false); }
    $enabled = (bool) ($input['enabled'] ?? false);
    if ($enabled && !$emails && !$webhooks) {
        return ['ok' => false, 'error' => 'Add an email address or a webhook before turning alerts on.'];
    }
    $config = [
        'enabled' => $enabled, 'emails' => $emails, 'webhooks' => $webhooks, 'events' => $events,
        'notify_resolved' => (bool) ($input['notify_resolved'] ?? true),
        'remind_hours' => max(0, min(168, (int) ($input['remind_hours'] ?? 24))),
    ];
    if (!write_json_file(alerts_file(), $config)) { return ['ok' => false, 'error' => 'Could not save the alert settings.']; }
    audit('alerts.settings', ($enabled ? 'enabled' : 'disabled') . ', ' . count($emails) . ' email, ' . count($webhooks) . ' webhook');
    return ['ok' => true];
}

/** Category for a health-check key, or null when it never alerts. */
function alert_category_for(string $key): ?string
{
    foreach (alert_categories() as $category => $info) {
        if (in_array($key, $info['keys'], true)) { return $category; }
    }
    return null;
}

/**
 * Compare current health with what was last notified and send one batched
 * message describing every change. Returns a summary for the scheduler.
 */
function alerts_evaluate(?int $now = null, ?array $health = null): array
{
    $now = $now ?? time();
    $config = alerts_config();
    if (!$config['enabled'] || (!$config['emails'] && !$config['webhooks'])) {
        return ['ok' => true, 'sent' => 0, 'skipped' => 'alerts are off'];
    }
    if ($health === null) {
        require_once APP_ROOT . '/lib/mod_health.php';
        $health = health_summary();
    }
    $current = [];
    foreach ((array) ($health['items'] ?? []) as $item) {
        $level = (string) ($item['level'] ?? 'info');
        $key = (string) ($item['key'] ?? '');
        $category = $key !== '' ? alert_category_for($key) : null;
        if ($category === null || empty($config['events'][$category]) || !in_array($level, ['warning', 'critical'], true)) { continue; }
        // One alert per check; keep the most severe item (e.g. two SSL entries).
        $id = $key;
        if (isset($current[$id]) && $current[$id]['level'] === 'critical') { continue; }
        $current[$id] = ['level' => $level, 'title' => (string) $item['title'], 'detail' => (string) ($item['detail'] ?? '')];
    }

    $state = alerts_state();
    $active = is_array($state['active']) ? $state['active'] : [];
    $events = [];
    foreach ($current as $id => $item) {
        $previous = $active[$id] ?? null;
        $remind = $config['remind_hours'] > 0 && $previous && $now - (int) ($previous['notified_at'] ?? 0) >= $config['remind_hours'] * 3600;
        if (!$previous) {
            $events[] = ['type' => 'problem'] + $item;
        } elseif ($previous['level'] === 'warning' && $item['level'] === 'critical') {
            $events[] = ['type' => 'escalated'] + $item;
        } elseif ($remind) {
            $events[] = ['type' => 'reminder'] + $item;
        }
        $notified = (!$previous || $remind || ($previous['level'] === 'warning' && $item['level'] === 'critical'))
            ? $now : (int) ($previous['notified_at'] ?? $now);
        $active[$id] = $item + ['since' => (int) ($previous['since'] ?? $now), 'notified_at' => $notified];
    }
    foreach ($active as $id => $previous) {
        if (isset($current[$id])) { continue; }
        if ($config['notify_resolved']) {
            $events[] = ['type' => 'resolved', 'level' => 'ok', 'title' => 'Resolved: ' . ($previous['title'] ?? $id), 'detail' => ''];
        }
        unset($active[$id]);
    }
    $state['active'] = $active;
    $results = [];
    if ($events) {
        $results = alerts_send($events, $config);
        $state['deliveries'] = array_slice(array_merge([['at' => $now, 'events' => count($events), 'results' => $results]], (array) ($state['deliveries'] ?? [])), 0, 20);
    }
    write_json_file(alerts_state_file(), $state);
    $failed = array_filter($results, static fn($r) => empty($r['ok']));
    return ['ok' => !$failed, 'sent' => count($events), 'active' => count($active), 'results' => $results]
        + ($failed ? ['error' => 'Alert delivery failed: ' . implode('; ', array_map(static fn($r) => $r['channel'] . ': ' . $r['error'], $failed))] : []);
}

/** Send a test message through every configured channel. */
function alerts_test(): array
{
    $config = alerts_config();
    if (!$config['emails'] && !$config['webhooks']) {
        return ['ok' => false, 'error' => 'Save an email address or webhook first.'];
    }
    $results = alerts_send([[
        'type' => 'test', 'level' => 'info', 'title' => 'Test alert from ' . alerts_hostname(),
        'detail' => 'Alerts from Nebula Panel reach this channel. Sent by ' . (current_user() ?? 'unknown') . '.',
    ]], $config);
    audit('alerts.test', implode(', ', array_map(static fn($r) => $r['channel'] . '=' . ($r['ok'] ? 'ok' : 'failed'), $results)));
    $failed = array_filter($results, static fn($r) => empty($r['ok']));
    return $failed
        ? ['ok' => false, 'results' => $results, 'error' => implode("\n", array_map(static fn($r) => $r['channel'] . ': ' . $r['error'], $failed))]
        : ['ok' => true, 'results' => $results];
}

function alerts_hostname(): string
{
    return (string) (gethostname() ?: 'server');
}

/** Plain-text rendering shared by email and chat channels. */
function alerts_text(array $events): array
{
    $host = alerts_hostname();
    $problems = count(array_filter($events, static fn($e) => in_array($e['type'], ['problem', 'escalated', 'reminder'], true)));
    $resolved = count(array_filter($events, static fn($e) => $e['type'] === 'resolved'));
    if (count($events) === 1) {
        $subject = $events[0]['title'];
    } elseif ($problems) {
        $subject = $problems . ' problem' . ($problems === 1 ? '' : 's') . ' need attention' . ($resolved ? ', ' . $resolved . ' resolved' : '');
    } else {
        $subject = $resolved . ' problem' . ($resolved === 1 ? '' : 's') . ' resolved';
    }
    $marks = ['critical' => '[CRITICAL]', 'warning' => '[WARNING]', 'ok' => '[RESOLVED]', 'info' => '[INFO]'];
    $prefix = ['escalated' => 'Escalated: ', 'reminder' => 'Still open: '];
    $lines = [];
    foreach ($events as $event) {
        $lines[] = ($marks[$event['level']] ?? '') . ' ' . ($prefix[$event['type']] ?? '') . $event['title']
            . ($event['detail'] !== '' ? "\n    " . $event['detail'] : '');
    }
    $worst = 'info';
    foreach ($events as $event) {
        if ($event['level'] === 'critical') { $worst = 'critical'; break; }
        if ($event['level'] === 'warning') { $worst = 'warning'; }
        if ($event['level'] === 'ok' && $worst === 'info') { $worst = 'ok'; }
    }
    return ['subject' => '[' . $host . '] ' . $subject, 'body' => implode("\n", $lines), 'level' => $worst, 'host' => $host];
}

/** Deliver events to every channel; returns one result per channel. */
function alerts_send(array $events, array $config): array
{
    $text = alerts_text($events);
    $results = [];
    foreach ($config['emails'] as $email) {
        $results[] = ['channel' => 'email ' . $email] + alert_send_email($email, $text);
    }
    foreach ($config['webhooks'] as $hook) {
        $label = (alert_webhook_types()[$hook['type']] ?? 'webhook') . ' ' . (parse_url((string) $hook['url'], PHP_URL_HOST) ?: 'webhook');
        $results[] = ['channel' => $label] + alert_send_webhook($hook, $events, $text);
    }
    return $results;
}

function alert_send_email(string $to, array $text): array
{
    if (!function_exists('mail')) { return ['ok' => false, 'error' => 'PHP mail() is unavailable.']; }
    $sendmail = (string) ini_get('sendmail_path');
    $binary = $sendmail !== '' ? strtok($sendmail, ' ') : '/usr/sbin/sendmail';
    if (!is_executable((string) $binary)) {
        return ['ok' => false, 'error' => 'No local mail server (sendmail) is installed. Set up Email in the panel, or use a webhook.'];
    }
    $host = $text['host'];
    $from = 'nebula-panel@' . (preg_match('/^[A-Za-z0-9.-]+$/', $host) && str_contains($host, '.') ? $host : 'localhost');
    $headers = "From: Nebula Panel <{$from}>\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Nebula-Alert: " . $text['level'];
    $body = $text['body'] . "\n\n-- \nSent by Nebula Panel on " . $host . ' at ' . date('Y-m-d H:i T') . ".\nChange alert settings under Settings → Alerts.\n";
    $subject = '=?UTF-8?B?' . base64_encode($text['subject']) . '?=';
    return @mail($to, $subject, $body, $headers)
        ? ['ok' => true]
        : ['ok' => false, 'error' => 'The local mail server rejected the message.'];
}

function alert_send_webhook(array $hook, array $events, array $text): array
{
    $url = (string) $hook['url'];
    $emoji = ['critical' => '🔴', 'warning' => '🟠', 'ok' => '🟢', 'info' => 'ℹ️'];
    $message = ($emoji[$text['level']] ?? '') . ' *' . $text['subject'] . "*\n" . $text['body'];
    switch ($hook['type']) {
        case 'slack':
            return alert_http_post($url, json_encode(['text' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['Content-Type: application/json']);
        case 'discord':
            $content = str_replace('*', '**', $message);
            return alert_http_post($url, json_encode(['content' => mb_substr($content, 0, 1900), 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['Content-Type: application/json']);
        case 'ntfy':
            $priority = ['critical' => 'urgent', 'warning' => 'high', 'ok' => 'default', 'info' => 'default'][$text['level']] ?? 'default';
            $tags = ['critical' => 'rotating_light', 'warning' => 'warning', 'ok' => 'white_check_mark', 'info' => 'information_source'][$text['level']] ?? '';
            // Header values must be single-line ASCII; ntfy accepts RFC 2047.
            return alert_http_post($url, $text['body'], ['Content-Type: text/plain; charset=utf-8',
                'Title: =?UTF-8?B?' . base64_encode($text['subject']) . '?=', 'Priority: ' . $priority, 'Tags: ' . $tags]);
        default:
            return alert_http_post($url, json_encode([
                'source' => 'nebula-panel', 'host' => $text['host'], 'level' => $text['level'], 'subject' => $text['subject'],
                'text' => $text['body'], 'time' => date('c'), 'events' => $events,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['Content-Type: application/json']);
    }
}

/** Minimal POST used for webhooks: [ok, error]. No redirects are followed. */
function alert_http_post(string $url, string $body, array $headers, int $timeout = 10): array
{
    $headers[] = 'User-Agent: NebulaPanel-Alerts';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) { return ['ok' => false, 'error' => $error ?: 'Request failed.']; }
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
            'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $code = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $code = (int) $m[1]; }
        }
        if ($response === false && $code === 0) { return ['ok' => false, 'error' => 'Could not connect.']; }
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => 'HTTP ' . $code . ($response ? ': ' . substr(trim(strip_tags((string) $response)), 0, 160) : '')];
    }
    return ['ok' => true];
}
