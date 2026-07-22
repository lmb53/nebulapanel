<?php
/**
 * api/mail — GET status/config; POST actions for the mail stack, mailboxes,
 * aliases, DKIM/DNS and Roundcube. Streamed installs use ?stream=1.
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
            $res = mail_setup($emit);
            break;
        case 'roundcube-install':
            $res = mail_roundcube_install($emit);
            break;
        case 'roundcube-remove':
            $res = mail_roundcube_remove();
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
    'accounts'  => array_map(fn($a) => ['email' => $a['email'] ?? '', 'created' => $a['created'] ?? ''], $state['accounts']),
    'aliases'   => array_map(fn($a) => ['from' => $a['from'] ?? '', 'to' => $a['to'] ?? ''], $state['aliases']),
    'roundcube' => [
        'installed' => mail_roundcube_installed(),
        'url'       => mail_roundcube()['url'] ?? null,
    ],
]);
