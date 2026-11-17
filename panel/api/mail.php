<?php
/**
 * api/mail — GET status/config; POST actions for the mail stack, mailboxes,
 * aliases, DKIM/DNS, statistics and Roundcube webmail. Streamed installs use
 * ?stream=1.
 */
require APP_ROOT . '/lib/mod_mail.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_check();
    require_capability('mail.manage');
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

    switch ($action) {
        case 'setup':
            $res = mail_setup(
                (string) ($body['hostname'] ?? ''),
                (string) ($body['cert_email'] ?? ''),
                $emit
            );
            break;
        case 'diag':
            $res = mail_diag();
            break;
        case 'reapply':
            $res = mail_apply();
            break;
        case 'stats':
            $res = mail_stats((int) ($body['days'] ?? 30));
            break;
        case 'roundcube-install':
            $res = mail_webmail_install('roundcube', $emit);
            break;
        case 'roundcube-remove':
        case 'webmail-remove':
            $res = mail_webmail_remove();
            break;
        case 'domain-add':
            $res = mail_domain_add((string) ($body['domain'] ?? ''));
            break;
        case 'domain-delete':
            $res = mail_domain_delete((string) ($body['domain'] ?? ''));
            break;
        case 'account-add':
            $res = mail_account_add((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
            break;
        case 'account-passwd':
            $res = mail_account_passwd((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
            break;
        case 'account-delete':
            $res = mail_account_delete((string) ($body['email'] ?? ''));
            break;
        case 'account-quota':
            $res = mail_account_quota((string) ($body['email'] ?? ''), (int) ($body['quota_mb'] ?? 0));
            break;
        case 'account-autoreply':
            $res = mail_account_autoreply((string) ($body['email'] ?? ''), !empty($body['enabled']), (string) ($body['subject'] ?? ''),
                (string) ($body['body'] ?? ''), (int) ($body['days'] ?? 1), (string) ($body['until'] ?? ''));
            break;
        case 'queue':
            $res = mail_queue();
            break;
        case 'queue-flush':
        case 'queue-delete':
        case 'queue-delete-deferred':
            $res = mail_queue_action(substr($action, 6), (string) ($body['id'] ?? ''));
            break;
        case 'sieve-install':
            $res = mail_sieve_install($emit);
            break;
        case 'alias-add':
            $res = mail_alias_add((string) ($body['from'] ?? ''), (string) ($body['to'] ?? ''));
            break;
        case 'alias-delete':
            $res = mail_alias_delete((string) ($body['from'] ?? ''), (string) ($body['to'] ?? ''));
            break;
        case 'dns-publish':
            $res = mail_dns_publish((string) ($body['domain'] ?? ''));
            break;
        default:
            $res = ['ok' => false, 'error' => 'Unknown action.'];
    }

    if ($streaming) {
        stream_json_event(['type' => 'result', 'result' => $res]);
        exit;
    }
    json_out($res, !empty($res['ok']) ? 200 : 400);
}

// GET — status and full configuration for the page.
$state = mail_state();
json_out([
    'ok'        => true,
    'status'    => mail_status(),
    'domains'   => $state['domains'],
    'accounts'  => array_map(fn($a) => ['email' => $a['email'] ?? '', 'created' => $a['created'] ?? '', 'quota_mb' => (int) ($a['quota_mb'] ?? 0),
        'autoreply' => array_intersect_key((array) ($a['autoreply'] ?? []), array_flip(['enabled', 'subject', 'body', 'days', 'until']))], $state['accounts']),
    'sieve'     => mail_sieve_available(),
    'aliases'   => array_map(fn($a) => ['from' => $a['from'] ?? '', 'to' => $a['to'] ?? ''], $state['aliases']),
    'webmail'   => [
        'installed' => mail_webmail_installed(),
        'kind'      => mail_webmail()['kind'] ?? null,
        'label'     => mail_webmail_label(),
        'url'       => mail_webmail()['url'] ?? null,
    ],
    // Legacy shape kept for any older client code.
    'roundcube' => [
        'installed' => mail_roundcube_installed(),
        'url'       => mail_roundcube()['url'] ?? null,
    ],
]);
