<?php
/**
 * Regressions for edge cases found in the robustness audit: symlink-safe file
 * operations, editor encoding safety, stale-index guards, notification
 * identity, login throttling and input validation. Run: php tests/robustness.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/nebula-robust-' . bin2hex(random_bytes(6));
$fm = $tmp . '/fm';
mkdir($fm, 0700, true);
define('APP_ROOT', $root . '/panel');
define('DATA_DIR', $tmp . '/data');
mkdir(DATA_DIR, 0700, true);
$config = require APP_ROOT . '/config.php';
$config['fm_root'] = $fm;
$config['login_max_attempts'] = 2;
$config['login_window'] = 600;
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/sys.php';
require APP_ROOT . '/lib/files.php';
require APP_ROOT . '/lib/mod_cron.php';
require APP_ROOT . '/lib/mod_notifications.php';
require APP_ROOT . '/lib/mod_firewall.php';
require APP_ROOT . '/lib/mod_dns.php';
require APP_ROOT . '/lib/mod_ssl.php';
require APP_ROOT . '/lib/mod_db.php';
require APP_ROOT . '/lib/mod_fail2ban.php';

register_shutdown_function(static function () use ($tmp): void {
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') { $remove($path . '/' . $entry); }
        }
        @rmdir($path);
    };
    $remove($tmp);
});

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok' : 'not ok') . " - $message\n";
    if (!$ok) { $failures++; }
};

// --- File Manager: entries vs. symlink targets -------------------------------
mkdir($fm . '/keep', 0700);
file_put_contents($fm . '/keep/precious.txt', 'do not lose me');
symlink($fm . '/keep', $fm . '/link-to-keep');
symlink('/etc', $fm . '/link-outside');

$check(fm_resolve_entry('') === null && fm_resolve_entry('/') === null && fm_resolve_entry('.') === null,
    'the File Manager root can never be addressed as a deletable entry');
$check(fm_resolve_entry('link-to-keep') === realpath($fm) . '/link-to-keep',
    'a symlink resolves to the link itself, not its target');
$check(fm_resolve_entry('link-outside') === realpath($fm) . '/link-outside',
    'a link pointing outside the root can still be removed as an entry');
$check(fm_resolve_entry('keep/..') === null && fm_resolve_entry('../etc') === null,
    'dot-dot entries and parent escapes are rejected');
$check(fm_resolve_entry('link-outside/passwd') === null,
    'a path through an escaping symlink is rejected');

$renamed = fm_rename('link-to-keep', 'renamed-link');
$check(!empty($renamed['ok']) && is_link($fm . '/renamed-link') && is_dir($fm . '/keep')
    && file_get_contents($fm . '/keep/precious.txt') === 'do not lose me',
    'renaming a symlink renames the link and leaves its target untouched');
$check(empty(fm_rename('', 'escaped')['ok']) && is_dir($fm),
    'the File Manager root cannot be renamed out of place');
mkdir($fm . '/dest', 0700);
$moved = fm_op('renamed-link', 'dest', 'move');
$check(!empty($moved['ok']) && is_link($fm . '/dest/renamed-link') && is_dir($fm . '/keep'),
    'moving a symlink moves the link, not the directory it points to');
$check(empty(fm_op('dest', 'dest', 'move')['ok']), 'a folder cannot be moved into itself');

// A panel-private directory inside the File Manager root (FM_ROOT set to a
// parent of the installation): neither it nor any parent may be acted on.
mkdir($fm . '/hosting/panel-install', 0700, true);
$config['fm_denied_paths'] = [$fm . '/hosting/panel-install'];
$check(fm_resolve_entry('hosting/panel-install') === null && fm_resolve_entry('hosting') === null,
    'a panel-private folder and its parents cannot be deleted, moved or renamed');
$check(empty(fm_compress(['hosting'], '', 'leak.tar.gz')['ok']),
    'a parent of the panel installation cannot be archived into a web-served folder');
$check(fm_resolve('hosting') !== null, 'parents of the panel installation can still be browsed');
$check(strpos($helperSource = (string) file_get_contents(APP_ROOT . '/bin/nebula-helper'), 'die "path contains the panel installation"') !== false,
    'the helper refuses to act on parents of the panel installation');
$config['fm_denied_paths'] = [];

// --- File Manager: names, uploads, listings ----------------------------------
$check(fm_valid_name('report (1).pdf') && fm_valid_name('a+b,c@d=e~f.txt'),
    'common download names with parentheses and punctuation are accepted');
$check(!fm_valid_name('a/b') && !fm_valid_name('..') && !fm_valid_name(' lead') && !fm_valid_name('x;id') && !fm_valid_name('$(id)'),
    'separators, traversal, padding and shell metacharacters are rejected');
$check(fm_executable_upload_name('.user.ini') && fm_executable_upload_name('.HTACCESS')
    && fm_executable_upload_name('shell.phtml') && fm_executable_upload_name('x.php7') && !fm_executable_upload_name('notes.txt'),
    'uploads that configure or execute PHP are recognised');

$helperSource = (string) file_get_contents(APP_ROOT . '/bin/nebula-helper');
$check(strpos($helperSource, "local re='^[A-Za-z0-9._ ()+,@=~-]{1,255}$'") !== false,
    'the helper accepts exactly the File Manager name charset');
$check(preg_match('/file-delete\)\s*\n\s*TARGET="\$\(fm_confined_entry /', $helperSource) === 1
    && strpos($helperSource, 'chown -hR root:root "$FILE_TRASH/$TRASH_NAME"') !== false,
    'the helper trashes a symlink itself, never its target');

mkdir($fm . '/big', 0700);
for ($i = 0; $i < 30; $i++) { touch(sprintf('%s/big/f%02d', $fm, $i)); }
mkdir($fm . '/big/zdir', 0700);
$listing = fm_list($fm . '/big', 10);
$check($listing['truncated'] === true && $listing['total'] === 31
    && count($listing['dirs']) + count($listing['files']) === 10 && ($listing['dirs'][0]['name'] ?? '') === 'zdir',
    'huge folders are truncated after the limit, folders first');
$full = fm_list($fm . '/big');
$check($full['truncated'] === false && count($full['files']) === 30, 'normal folders are listed completely');

// --- Editor encoding safety --------------------------------------------------
file_put_contents($fm . '/latin1.conf', "caf\xE9 = 1\n");
file_put_contents($fm . '/utf8.conf', "café = 1\n");
file_put_contents($fm . '/binary.bin', "a\0b");
$check(fm_edit_block_reason($fm . '/latin1.conf') !== null,
    'a non-UTF-8 file is refused by the inline editor instead of being blanked');
$check(fm_edit_block_reason($fm . '/utf8.conf') === null, 'UTF-8 text files remain editable');
$check(fm_edit_block_reason($fm . '/binary.bin') !== null, 'binary files are refused by the inline editor');
$check(e("caf\xE9") !== '' && str_starts_with(e("caf\xE9"), 'caf'),
    'escaping invalid UTF-8 substitutes bytes instead of erasing the string');

// --- Bounded reads -------------------------------------------------------------
$log = $tmp . '/tail.log';
$fh = fopen($log, 'wb');
for ($i = 1; $i <= 50000; $i++) { fwrite($fh, "line $i\n"); }
fclose($fh);
$check(file_tail($log, 3) === "line 49998\nline 49999\nline 50000", 'file_tail returns the last lines of a large file');
$check(file_tail($tmp . '/missing.log', 5) === '', 'file_tail tolerates a missing file');
$check(ini_size_bytes('64M') === 67108864 && ini_size_bytes('1G') === 1073741824 && ini_size_bytes('') === 0,
    'php.ini size values are parsed');

// --- Login throttling ----------------------------------------------------------
$retries = [];
foreach (['alice', 'bob', 'carol', 'dave', 'erin', 'frank', 'grace', 'heidi', 'ivan'] as $name) {
    $retries[] = reserve_login_attempt(null, $name);
}
$check($retries[7] === 0 && $retries[8] > 0,
    'one address cycling through usernames hits the per-address throttle');
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
reserve_login_attempt(null, 'office-admin');
record_login_attempt(true, null, 'office-admin');
$data = json_decode((string) file_get_contents(login_attempts_file()), true);
$check(!isset($data[login_ip_key('203.0.113.10')]), 'successful logins do not consume the per-address budget');

// --- Panel users ---------------------------------------------------------------
$_SESSION = [];
save_panel_users([['id' => 1, 'username' => 'solo-admin', 'hash' => panel_password_hash('unused robust password'), 'role' => 'admin', 'enabled' => true]]);
$demote = panel_user_update(1, 'auditor', true);
$check(empty($demote['ok']) && panel_users()[0]['role'] === 'admin',
    'a session-less caller (API token) cannot demote the last administrator');
$check(empty(panel_user_update(1, 'admin', true, 'solo-admin-is-my-password')['ok']),
    'password resets reject passwords containing the username');

// --- Notifications ----------------------------------------------------------------
$a = ['level' => 'warning', 'title' => 'Disk space running low', 'detail' => '81.2% used on /', 'route' => 'files'];
$b = ['detail' => '81.9% used on /'] + $a;
$c = ['level' => 'critical', 'title' => 'Disk space critically low'] + $a;
$check(notification_id($a) === notification_id($b), 'a notification keeps its identity while its live reading changes');
$check(notification_id($a) !== notification_id($c), 'an escalated condition gets a new notification identity');
$_SESSION = ['uid' => 1];
for ($i = 0; $i < NOTIFICATION_STATE_LIMIT + 50; $i++) { notifications_mark_read(substr(hash('sha256', (string) $i), 0, 16)); }
$check(count(notifications_state()['read']) === NOTIFICATION_STATE_LIMIT, 'remembered notification state is bounded');

// --- Cron -----------------------------------------------------------------------
$check(cron_validate('0 2 * * *', 'echo ok') === null && cron_validate('@daily', 'echo ok') === null
    && cron_validate('*/5 1-5 * jan mon-fri', 'echo ok') === null, 'valid cron schedules are accepted');
$check(cron_validate('0 2 * * *', "echo ok\n* * * * * id") !== null, 'a command cannot inject a second crontab line');
$check(cron_validate("@daily\n", 'x') !== null && cron_validate('@sometimes', 'x') !== null && cron_validate('0 2 * *', 'x') !== null,
    'malformed schedules and unknown keywords are rejected');
$check(cron_validate('0 2 * * ;', 'x') !== null, 'schedule fields are restricted to cron syntax');
$lines = ['0 1 * * * a', '0 2 * * * b'];
$check(cron_line_error($lines, 1, '0 2 * * * b') === null && cron_line_error($lines, 1, null) === null,
    'a matching or unspecified expected crontab line is accepted');
$check(cron_line_error($lines, 1, '0 1 * * * a') !== null && cron_line_error($lines, 5, null) !== null,
    'a shifted or missing crontab line is refused');

// --- Firewall / Fail2Ban -----------------------------------------------------------
$check(fw_rule_key("22/tcp                     ALLOW IN    Anywhere") === fw_rule_key("22/tcp ALLOW IN Anywhere"),
    'firewall rules compare independently of column padding');
$_SERVER['SERVER_PORT'] = '443';
$check(in_array(443, fw_lockout_ports(), true) && fw_ssh_ports() !== [],
    'enabling UFW protects SSH and the panel port');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$check(!in_array(443, fw_lockout_ports(), true), 'an SSH-tunnelled session does not force the panel port open');
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$selfBan = f2b_action('ban', 'sshd', '198.51.100.7');
$check(empty($selfBan['ok']) && stripos((string) ($selfBan['error'] ?? ''), 'lock you out') !== false,
    'Fail2Ban refuses to ban the operator\'s own address');

// --- DNS / SSL / databases ------------------------------------------------------------
$check(dns_canonical_domain(' Example.COM. ') === 'example.com', 'DNS zones use one canonical key');
write_json_file(ssl_custom_file(), [
    ['name' => 'old.example.com', 'expiry' => date('Y-m-d H:i:s O', time() - 86400), 'days' => 90, 'valid' => true, 'custom' => true],
    ['name' => 'new.example.com', 'expires_at' => time() + 10 * 86400 + 60, 'days' => 999, 'valid' => true, 'custom' => true],
]);
$custom = ssl_custom_list();
$check(($custom[0]['valid'] ?? true) === false && ($custom[0]['days'] ?? -1) === 0,
    'an expired uploaded certificate is reported as expired');
$check(($custom[1]['days'] ?? 0) === 10 && ($custom[1]['valid'] ?? false) === true,
    'uploaded certificate days-left are computed live');
$check(db_password_error('') !== null && db_password_error('short') !== null && db_password_error('a sound password') === null,
    'database users require a real password');
$check(empty(db_create_bundle('appdb', 'appuser', 'localhost', '', 'example.com')['ok']),
    'a database bundle with a passwordless user is refused before anything is created');

if ($failures) {
    fwrite(STDERR, "$failures robustness regression(s) failed.\n");
    exit(1);
}
echo "Robustness regressions passed.\n";
