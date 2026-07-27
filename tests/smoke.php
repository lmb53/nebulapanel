<?php
/** Lightweight cross-platform checks. Run: php tests/smoke.php */

$appRoot = dirname(__DIR__) . '/panel';
$testDir = sys_get_temp_dir() . '/nebula-smoke-' . bin2hex(random_bytes(6));
$fmTestDir = sys_get_temp_dir() . '/nebula-fm-smoke-' . bin2hex(random_bytes(6));
if (!mkdir($testDir, 0700, true)) {
    fwrite(STDERR, "Could not create test directory\n");
    exit(1);
}

define('APP_ROOT', $appRoot);
define('DATA_DIR', $testDir);
$config = require APP_ROOT . '/config.php';
$config['login_max_attempts'] = 3;
$config['login_window'] = 60;
$config['fm_root'] = $fmTestDir;
$_SERVER['REMOTE_ADDR'] = '198.51.100.250';

require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/sys.php';
require APP_ROOT . '/lib/mod_updates.php';
require APP_ROOT . '/lib/mod_cron.php';
require APP_ROOT . '/lib/mod_settings.php';
require APP_ROOT . '/lib/modules.php';
require APP_ROOT . '/lib/files.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { $failures[] = $message; }
};

$jsonPath = $testDir . '/atomic.json';
$check(write_json_file($jsonPath, ['value' => 1]), 'initial JSON write failed');
$check(write_json_file($jsonPath, ['value' => 2]), 'replacement JSON write failed');
$json = json_decode((string) file_get_contents($jsonPath), true);
$check(($json['value'] ?? null) === 2, 'replacement JSON content was not preserved');

$attempts = [reserve_login_attempt(), reserve_login_attempt(), reserve_login_attempt(), reserve_login_attempt()];
$check($attempts[0] === 0 && $attempts[1] === 0 && $attempts[2] === 0, 'valid login attempts were blocked');
$check($attempts[3] > 0, 'login throttle did not activate');
record_login_attempt(true);
$check(login_retry_after() === 0, 'successful login did not clear the throttle');

mkdir($config['fm_root'], 0700, true);
mkdir($config['fm_root'] . '/site', 0700);
file_put_contents($config['fm_root'] . '/site/index.txt', 'ok');
$check(fm_resolve('site/index.txt') === realpath($config['fm_root'] . '/site/index.txt'), 'valid file-manager path was rejected');
$check(fm_resolve('../outside') === null, 'file-manager traversal was accepted');
$check(is_page_route('dashboard') && !is_page_route('../config'), 'page route whitelist failed');
$modules = nebula_modules();
$check(is_page_route('domains') && is_page_route('dns') && is_page_route('sshkeys') && is_page_route('notifications'), 'mockup-backed module routes are missing');
$check(is_page_route('api') && isset($modules['api']), 'authenticated API-token management page is not routed');
$check(($modules['files'][2] ?? '') === 'Hosting', 'File Manager is not in Hosting');
$check(($modules['backups'][2] ?? '') === 'Tools', 'Backups is not in Tools');
$check(!isset($modules['monitoring']) && !is_page_route('monitoring'), 'removed Monitoring page is still routed');
$check(role_route_allowed('users', 'admin') && !role_route_allowed('users', 'auditor') && role_route_allowed('logs', 'auditor'), 'RBAC route policy failed');
$check(role_can('services.control', 'operator') && !role_can('terminal.execute', 'operator') && !role_can('packages.manage', 'developer'), 'RBAC capabilities are too broad or incomplete');
$check(!role_route_allowed('terminal', 'operator') && !role_route_allowed('docker', 'developer') && !role_route_allowed('files', 'developer'), 'sensitive route policy failed');
$check(isset(panel_roles()['operator']) && isset(panel_roles()['developer']), 'panel roles are missing');
$bootstrapToken = bin2hex(random_bytes(32));
write_json_file(bootstrap_file(), ['hash' => hash('sha256', $bootstrapToken), 'expires_at' => time() + 300]);
$adminCreated = create_admin('smoke-admin', 'correct horse battery staple', $bootstrapToken);
$check(!empty($adminCreated['ok']) && count(panel_users()) === 1, 'initial panel administrator migration failed');
$userCreated = panel_user_create('smoke-operator', 'another correct horse battery staple', 'operator');
$createdUsers = panel_users();
$operatorId = (int) ($createdUsers[1]['id'] ?? 0);
$check(!empty($userCreated['ok']) && count($createdUsers) === 2 && $operatorId > 0, 'panel user creation failed');
$_SESSION['uid'] = 1; $_SESSION['username'] = 'smoke-admin'; $_SESSION['role'] = 'admin';
$check(!empty(panel_user_update($operatorId, 'developer', true)['ok']), 'panel role update failed');
$check(!empty(panel_user_delete($operatorId)['ok']) && count(panel_users()) === 1, 'panel user deletion failed');
$oldVersion = (int) (panel_users()[0]['session_version'] ?? 0);
$passwordChange = change_admin_password('correct horse battery staple', 'replacement correct horse battery staple');
$changedAdmin = panel_users()[0] ?? [];
$check(!empty($passwordChange['ok']) && password_verify('replacement correct horse battery staple', (string) ($changedAdmin['hash'] ?? '')), 'panel-account password change failed');
$check(!password_verify('correct horse battery staple', (string) ($changedAdmin['hash'] ?? '')) && (int) ($changedAdmin['session_version'] ?? 0) === $oldVersion + 1, 'password change did not revoke older sessions');
$check(is_file(APP_ROOT . '/api/provision.php'), 'provisioning API endpoint is missing');
$check(is_file(APP_ROOT . '/api/ssl.php'), 'SSL API endpoint is missing');
$check(is_file(APP_ROOT . '/api/php.php'), 'PHP API endpoint is missing');
$check(is_file(APP_ROOT . '/api/file-state.php') && is_file(APP_ROOT . '/api/file-compress.php'), 'extended File Manager endpoints are missing');
$check(!is_file(APP_ROOT . '/api/file-owner.php'), 'arbitrary File Manager ownership changes are still exposed');
$check(is_file(APP_ROOT . '/api/file-tree.php') && is_file(APP_ROOT . '/api/dns.php') && is_file(APP_ROOT . '/api/users.php'), 'tree, DNS, or panel-user API endpoint is missing');
$sessionApiCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/session-api.php');
[$sessionApiCode, $sessionApiOutput, $sessionApiError] = run_cmd($sessionApiCommand, 30);
$sessionApiResult = json_decode($sessionApiOutput, true);
$check(
    $sessionApiCode === 0
        && is_array($sessionApiResult)
        && !empty($sessionApiResult['ok'])
        && array_key_exists('mem', $sessionApiResult)
        && array_key_exists('disk', $sessionApiResult),
    'normal browser-session API routing failed (code=' . $sessionApiCode
        . ', output=' . json_encode($sessionApiOutput)
        . ', error=' . json_encode($sessionApiError) . ')'
);

// Email module: registration, RBAC and the mail helpers.
$check(is_page_route('mail') && ($modules['mail'][2] ?? '') === 'Hosting', 'Email module is not registered under Hosting');
$check(is_file(APP_ROOT . '/api/mail.php') && is_file(APP_ROOT . '/lib/mod_mail.php') && is_file(APP_ROOT . '/views/mail.php'), 'Email module files are missing');
$check(!role_can('mail.manage', 'operator') && !role_route_allowed('mail', 'operator') && !role_route_allowed('mail', 'auditor') && !role_route_allowed('mail', 'developer'), 'Email RBAC policy failed');
require APP_ROOT . '/lib/mod_mail.php';
$check(mail_valid_email('user@example.com') && !mail_valid_email('nope') && !mail_valid_email('user@localhost'), 'mail address validation failed');
$mailHash = mail_hash_password('a decent mailbox password');
$check(strpos($mailHash, '{SHA512-CRYPT}$6$') === 0 && crypt('a decent mailbox password', substr($mailHash, 14)) === substr($mailHash, 14), 'mail password hashing failed');
require APP_ROOT . '/lib/mod_dns.php';
$longTxt = 'v=DKIM1; k=rsa; p=' . str_repeat('A', 400);
$quoted = dns_txt_quote($longTxt);
$check(substr_count($quoted, '"') === 4 && dns_txt_quote('short') === '"short"', 'long TXT records are not split into 255-byte chunks');
$check(human_bytes(1048576) === '1 MB', 'byte formatting failed');
$check(fm_link_path($config['fm_root'] . '/site') === 'site', 'absolute file-manager link conversion failed');
$pinResult = fm_toggle_pin('site');
$check(!empty($pinResult['ok']) && count(fm_state_entries('pinned')) === 1, 'folder pinning failed');
fm_record_recent('site/index.txt');
$check(count(fm_state_entries('recent')) === 1, 'recent-file tracking failed');
$_SESSION['uid'] = 2;
$check(fm_state_entries('pinned') === [] && fm_state_entries('recent') === [], 'File Manager state leaked between panel users');
$_SESSION['uid'] = 1;
$originalHash = hash_file('sha256', $config['fm_root'] . '/site/index.txt');
file_put_contents($config['fm_root'] . '/site/index.txt', 'changed elsewhere');
$conflict = fm_save('site/index.txt', 'editor draft', $originalHash);
$check(!empty($conflict['conflict']) && file_get_contents($config['fm_root'] . '/site/index.txt') === 'changed elsewhere', 'stale editor save overwrote a changed file');
$currentHash = hash_file('sha256', $config['fm_root'] . '/site/index.txt');
$saved = fm_save('site/index.txt', 'saved safely', $currentHash);
$check(!empty($saved['ok']) && file_get_contents($config['fm_root'] . '/site/index.txt') === 'saved safely', 'conflict-aware file save failed');
mkdir($config['fm_root'] . '/site/private', 0700);
$config['fm_denied_paths'] = [$config['fm_root'] . '/site/private'];
$check(fm_resolve('site/private') === null && count(fm_list($config['fm_root'] . '/site')['dirs']) === 0, 'denied File Manager path was visible');

$chunks = '';
$streamCmd = escapeshellarg(PHP_BINARY) . ' --version';
[$streamCode, $streamOut] = run_cmd_stream($streamCmd, static function (string $chunk) use (&$chunks): void { $chunks .= $chunk; });
$check(
    $streamCode === 0 && strpos($streamOut, 'PHP') !== false && strpos($chunks, 'PHP') !== false,
    'streaming command runner failed (code=' . $streamCode . ', out=' . json_encode($streamOut) . ', chunks=' . json_encode($chunks) . ')'
);

$check(empty(upd_install_package('../bad')['ok']), 'unsafe package name was accepted');
$disabledCron = cron_parse_job_line('15 3 * * 1 /usr/bin/example', 4, false);
$check(($disabledCron['enabled'] ?? true) === false && ($disabledCron['schedule'] ?? '') === '15 3 * * 1', 'disabled cron job parsing failed');

// Fail2Ban module: files, RBAC routing and input validation.
require APP_ROOT . '/lib/mod_fail2ban.php';
$check(is_file(APP_ROOT . '/api/fail2ban.php'), 'Fail2Ban API endpoint is missing');
$check(api_route_owner('fail2ban') === 'firewall' && !role_route_allowed('firewall', 'operator') && !role_route_allowed('firewall', 'developer'), 'Fail2Ban API is not scoped to the admin-only firewall module');
$check(empty(f2b_action('unban', 'sshd', 'not-an-ip')['ok']) && empty(f2b_action('unban', 'bad jail', '198.51.100.7')['ok']), 'Fail2Ban ban/unban input validation failed');
$check(empty(f2b_action('drop', 'sshd', '198.51.100.7')['ok']), 'an unknown Fail2Ban action was accepted');

// Docker Compose: availability is reported with a reason the UI can act on.
require APP_ROOT . '/lib/mod_compose.php';
$composeAvail = compose_availability();
$check(array_key_exists('available', $composeAvail) && array_key_exists('installable', $composeAvail) && array_key_exists('reason', $composeAvail), 'compose availability does not report why Compose is missing');
$check($composeAvail['available'] || $composeAvail['reason'] !== '', 'unavailable Compose reported no reason');
$storeApp = compose_catalog_list()[0] ?? [];
$check(!empty($storeApp['logo']) && strpos($storeApp['logo'], 'logos/') !== false, 'App Store entries carry no official logo');
foreach (compose_catalog() as $storeKey => $storeEntry) {
    $check(!empty($storeEntry['logo']) && is_file(APP_ROOT . '/assets/' . $storeEntry['logo']), 'App Store logo file is missing for ' . $storeKey);
    $check(strpos((string) ($storeEntry['compose'] ?? ''), ':latest') === false, 'App Store still deploys a mutable latest image for ' . $storeKey);
    $check(strpos((string) ($storeEntry['compose'] ?? ''), '/etc/') === false, 'App Store still deploys a host bind mount for ' . $storeKey);
}

$helperSource = (string) file_get_contents(APP_ROOT . '/bin/nebula-helper');
$check(strpos($helperSource, 'server_name $DOMAIN;') !== false, 'site vhost still adds an implicit hostname');
$check(strpos($helperSource, '-d "www.$DOMAIN"') === false, 'SSL issuance still requests an implicit www hostname');
$check(strpos($helperSource, "tr -dc 'a-zA-Z0-9' </dev/urandom | head") === false, 'phpMyAdmin secret generation still has the pipefail/SIGPIPE bug');
$check(strpos($helperSource, 'FM_ROOT_FILE=/etc/nebula-panel/fm-root') !== false, 'privileged File Manager confinement is missing');
$check(strpos($helperSource, 'PANEL_ROOT_FILE=/etc/nebula-panel/panel-root') !== false && strpos($helperSource, 'panel-update)') !== false, 'confined privileged panel updater is missing');
$check(strpos($helperSource, 'install -m 0755 -o root -g root "$SOURCE/bin/nebula-helper" /usr/local/bin/nebula-helper') !== false, 'panel-update does not refresh the privileged helper from the staged release');
$check(strpos($helperSource, 'file-compress)') !== false && strpos($helperSource, 'zip -rq') !== false, 'privileged zip compression is missing');
$check(strpos($helperSource, 'systemd-run --quiet') !== false, 'PHP-FPM reload is not deferred');
$check(strpos($helperSource, 'systemctl reload "php${VER}-fpm"') === false, 'a website action can still reload its serving PHP-FPM process inline');
$check(strpos($helperSource, 'site-list)') !== false && strpos($helperSource, 'site-php)') !== false, 'website recovery or PHP reassignment helper is missing');
$check(strpos($helperSource, 'php-extension)') !== false && strpos($helperSource, 'php-ini-replace)') !== false, 'expanded PHP management helper actions are missing');
$check(strpos($helperSource, 'pma-signon)') !== false && strpos($helperSource, "SignonSession") !== false, 'phpMyAdmin signed signon support is missing');
$check(strpos($helperSource, 'roundcube-install)') !== false && strpos($helperSource, 'webmail-remove)') !== false, 'Roundcube installer or generic webmail remover is missing');
$check(strpos($helperSource, 'snappymail-install)') === false, 'SnappyMail installer is still present (Roundcube is the only supported webmail)');
$check(strpos($helperSource, 'compose-install)') !== false, 'Docker Compose installer helper action is missing');
$check(strpos($helperSource, 'docker-query)') !== false && strpos($helperSource, 'compose-run)') !== false,
    'typed Docker/Compose helper actions are missing');
$check(strpos($helperSource, 'docker(-compose|[[:space:]]+compose)?') === false,
    'the generic root Docker command broker is still enabled');
$check(strpos($helperSource, 'f2b-status)') !== false && strpos($helperSource, 'f2b-unban|f2b-ban)') !== false, 'Fail2Ban helper actions are missing');
$check(strpos($helperSource, 'mail-stats)') !== false, 'mail statistics helper action is missing');
$check(strpos($helperSource, 'read -r email hash || [[ -n "$email" ]]') !== false && strpos($helperSource, 'read -r d || [[ -n "$d" ]]') !== false, 'mail-apply drops the final line without a trailing newline (single-mailbox login bug)');
$check(function_exists('mail_webmail_install') && function_exists('mail_webmail_remove') && function_exists('mail_webmail_installed'), 'webmail functions are missing');
$snappy = mail_webmail_install('snappymail');
$check(empty($snappy['ok']) && stripos((string) ($snappy['error'] ?? ''), 'roundcube') !== false, 'SnappyMail is still installable');
// Mail statistics: syslog timestamps carry no year, so a "future" stamp is last year's.
$check(mail_log_time('Jan 02 03:04:05 host postfix/smtp[1]: x') !== null, 'syslog mail-log timestamps are not parsed');
$check(mail_log_time('not a log line') === null, 'a non-log line was parsed as a timestamp');
$check(mail_log_time(date('M d H:i:s', strtotime('+40 days')) . ' host postfix/smtp[1]: x') < time(), 'a year-less future timestamp was not rolled back a year');
$check(mail_log_reason('Jul 01 00:00:00 h postfix/smtp[1]: A1: to=<a@b>, status=bounced (host said: 550 no such user)') === 'host said: 550 no such user', 'the SMTP reason was not extracted from a status line');
$pmaSource = (string) file_get_contents(APP_ROOT . '/lib/mod_pma.php');
$check(preg_match("/session_write_close\(\);\s*session_id\(''\);\s*session_name\('NebulaPmaSignon'\)/", $pmaSource) === 1, 'phpMyAdmin signon session is not isolated from the panel session ID');
$check(strpos($pmaSource, "\$state['accounts'][\$key] = \$account") === false, 'phpMyAdmin passwords are still persisted outside short-lived launches');
$check(strpos($helperSource, 'cert-upload)') !== false && strpos($helperSource, 'openssl x509') !== false, 'custom certificate installation is missing');
$check(strpos($helperSource, 'dns-zone-put)') !== false && strpos($helperSource, 'named-checkzone') !== false, 'authoritative DNS helper support is missing');
$check(!is_page_route('file-view'), 'obsolete file viewer route is still enabled');
$installerSource = (string) file_get_contents(dirname(__DIR__) . '/install.sh');
$check(strpos($installerSource, 'Reusing active panel prefix') !== false && strpos($installerSource, 'Migrated runtime state') !== false, 'reinstall state preservation is missing');
$check(strpos($installerSource, 'bind9 bind9-utils') === false
    && strpos($installerSource, 'ufw allow 53/udp') === false
    && strpos($helperSource, 'install -y bind9 bind9-utils') !== false
    && strpos($helperSource, 'ufw allow 53/udp') !== false,
    'authoritative DNS is not installed/opened lazily on first use');
$check(strpos($installerSource, 'chown -R root:root "$DEST"') !== false && strpos($installerSource, 'chown -R "$PANEL_USER:$PANEL_USER" "$DEST/data"') !== false, 'panel code/data ownership separation is missing');
$check(strpos($installerSource, 'sudo_line tar') === false, 'broad root tar permission is still installed');
$check(strpos($installerSource, 'nebula-panel-selfsigned.crt') !== false
    && strpos($installerSource, 'listen 443 ssl default_server') !== false
    && strpos($installerSource, 'subjectAltName=IP:${SERVER_IP}') !== false,
    'domainless self-signed HTTPS provisioning is missing');
$check(strpos($installerSource, 'request_terminate_timeout = 930s') !== false
    && strpos($installerSource, 'fastcgi_read_timeout 930s;') !== false
    && strpos($installerSource, 'env[PATH] = /usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin') !== false,
    'long operations or command discovery are not configured for the dedicated panel FPM pool');
$websitesSource = (string) file_get_contents(APP_ROOT . '/views/websites.php');
$check(strpos($websitesSource, 'id="wsRoot"') === false
    && strpos($websitesSource, '<label class="field-label">Document root</label>') === false,
    'the website creation form still exposes the automatically allocated document root');
$dashboardSource = (string) file_get_contents(APP_ROOT . '/views/dashboard.php');
$check(strpos($dashboardSource, '$initialMem = mem_info();') !== false
    && strpos($dashboardSource, '$initialDisk = disk_info') !== false,
    'dashboard resource cards have no server-rendered fallback');
$indexSource = (string) file_get_contents(APP_ROOT . '/index.php');
$check(strpos($indexSource, 'catch (Throwable $error)') !== false
    && strpos($indexSource, "stream_json_event(['type' => 'result'") !== false,
    'API failures are not converted to structured JSON/stream results');
$uploadSource = (string) file_get_contents(APP_ROOT . '/views/files.php');
$check(strpos($uploadSource, 'Replace it with the uploaded file?') !== false, 'upload overwrite confirmation is missing');
$check(strpos($uploadSource, 'fm-tree-section-title">Pinned') === false && strpos($uploadSource, 'No subfolders') === false, 'File Manager tree still includes removed sidebar placeholders');
$check(strpos($uploadSource, 'fmPropsBackdrop') !== false && strpos($uploadSource, "closest('[data-fm-details]')") !== false, 'File Manager details drawer trigger or dismiss layer is missing');
$editorSource = (string) file_get_contents(APP_ROOT . '/views/file-edit.php');
$check(strpos($editorSource, 'nebula-editor-drafts-v2') !== false && strpos($editorSource, "execCommand('findNext')") !== false, 'persistent editor drafts or find navigation is missing');
$cronSource = (string) file_get_contents(APP_ROOT . '/views/cron.php');
$check(strpos($cronSource, 'data-cron-toggle') !== false && strpos($cronSource, 'data-cron-part') !== false, 'cron toggle or visual schedule controls are missing');
$layoutSource = (string) file_get_contents(APP_ROOT . '/views/layout.php');
$bootstrapSource = (string) file_get_contents(APP_ROOT . '/lib/bootstrap.php');
$check(strpos($layoutSource, "\$active === 'dashboard'") !== false && strpos($layoutSource, 'vendor/chart-4.4.9.umd.min.js') !== false, 'Chart.js is not scoped to the dashboard');
$check(strpos($layoutSource, 'cdn.jsdelivr.net') === false && strpos($layoutSource, 'fonts.googleapis.com') === false, 'layout still requires third-party browser assets');
$check(strpos($bootstrapSource, "frame-ancestors 'none'") !== false && strpos($bootstrapSource, "script-src 'self' 'nonce-") !== false, 'restrictive CSP is missing');
$check(is_file(APP_ROOT . '/assets/vendor/lucide-1.8.0.min.js') && is_file(APP_ROOT . '/assets/vendor/codemirror-5.65.16.min.js'), 'local browser dependencies are missing');
$check(strpos($layoutSource, 'theme_boot_script()') !== false
    && strpos($layoutSource, 'theme_boot_script()') < strpos($layoutSource, "asset('style.css')"),
    'the theme boot script must run before the stylesheet to avoid a dark flash');
foreach (['login', 'setup', 'setup-wizard'] as $standalone) {
    $check(strpos((string) file_get_contents(APP_ROOT . "/views/$standalone.php"), 'theme_boot_script()') !== false,
        "views/$standalone.php does not apply the stored theme before paint");
}

require_once APP_ROOT . '/lib/mod_docker.php';
$parsedPorts = dk_parse_ports('0.0.0.0:8080->80/tcp, :::8080->80/tcp, 9000/tcp');
$check(count($parsedPorts) === 2, 'dual-stack port mappings should collapse to one entry');
$check($parsedPorts[0]['host'] === '8080' && $parsedPorts[0]['container'] === '80/tcp' && $parsedPorts[0]['published'],
    'published container ports are not parsed as host -> container');
$check($parsedPorts[1]['host'] === '' && !$parsedPorts[1]['published'], 'exposed-only ports should not be marked published');
$check(dk_parse_ports('') === [], 'a container with no ports should parse to an empty list');

$appsSource = (string) file_get_contents(APP_ROOT . '/lib/mod_apps.php');
foreach (['apache', 'mariadb', 'certbot', 'git', 'modsecurity'] as $logo) {
    $check(is_file(APP_ROOT . "/assets/logos/$logo.svg"), "assets/logos/$logo.svg is missing");
}
$check(strpos($appsSource, "'logo' => 'logos/apache.svg'") !== false, 'the app catalog does not use official brand logos');
$check(strpos($appsSource, "'helper' => 'modsec'") !== false, 'ModSecurity is missing from the app catalog');
$helperSource = (string) file_get_contents(dirname(APP_ROOT) . '/panel/bin/nebula-helper');
foreach (['modsec-status', 'modsec-install', 'modsec-uninstall', 'modsec-mode', 'modsec-log'] as $cmd) {
    $check(strpos($helperSource, "\n  $cmd)") !== false || strpos($helperSource, "$cmd)") !== false,
        "nebula-helper is missing the $cmd command");
}
$check(is_file(APP_ROOT . '/api/modsecurity.php') && strpos((string) file_get_contents(APP_ROOT . '/lib/auth.php'), "'modsecurity'=>'firewall'") !== false,
    'the ModSecurity API is not mapped to the firewall route');

$updaterSource = (string) file_get_contents(APP_ROOT . '/lib/mod_selfupdate.php');
$check(strpos($updaterSource, "preg_match('/^[a-f0-9]{40}$/") !== false && strpos($updaterSource, 'panel-update') !== false, 'self-update is not pinned and delegated to the confined helper');
$check(strpos($updaterSource, 'git ls-remote') !== false, 'self-update does not resolve the configured Git ref directly');

@unlink($config['fm_root'] . '/site/index.txt');
@rmdir($config['fm_root'] . '/site/private');
@rmdir($config['fm_root'] . '/site');
@rmdir($config['fm_root']);
@unlink($jsonPath);
@unlink(login_attempts_file());
@unlink(admin_file());
@unlink(bootstrap_file());
@unlink(panel_users_file());
@unlink(DATA_DIR . '/panel-users.lock');
@unlink(DATA_DIR . '/setup.lock');
@unlink(fm_state_file());
@unlink(fm_state_file() . '.lock');
@unlink(DATA_DIR . '/audit.log');
@rmdir($testDir);

if ($failures) {
    foreach ($failures as $failure) { fwrite(STDERR, "FAIL: $failure\n"); }
    exit(1);
}

echo "Nebula smoke tests passed.\n";
