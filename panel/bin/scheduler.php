<?php
/**
 * Nebula Panel background scheduler (CLI only).
 *
 * Installed as nebula-panel-scheduler.timer; runs every five minutes as the
 * panel user. Usage: php bin/scheduler.php [--quiet]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('NEBULA_SCHEDULER_ALLOW_ROOT') !== '1') {
    fwrite(STDERR, "Run the scheduler as the panel user (for example: sudo -u nebula-panel php bin/scheduler.php).\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', getenv('NEBULA_DATA_DIR') ?: APP_ROOT . '/data');

$config = require APP_ROOT . '/config.php';
$overrides = @json_decode((string) @file_get_contents(DATA_DIR . '/settings.json'), true);
if (is_array($overrides)) {
    $config = array_merge($config, $overrides);
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', DATA_DIR . '/php-errors.log');
@set_time_limit(0);

if (!is_dir(DATA_DIR)) {
    fwrite(STDERR, "Panel data directory is missing.\n");
    exit(1);
}
if (is_file(DATA_DIR . '/maintenance')) {
    exit(0);
}

// The audit trail attributes scheduled work to a recognisable actor.
$_SESSION = ['username' => 'system:scheduler', 'role' => 'admin'];
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? 'local';

require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/sys.php';
require APP_ROOT . '/lib/modules.php';
require APP_ROOT . '/lib/mod_scheduler.php';

$lock = @fopen(DATA_DIR . '/scheduler.lock', 'c');
if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
    // A previous run (a long backup) is still going; let it finish.
    exit(0);
}

$report = scheduler_run();
if (!in_array('--quiet', $argv, true)) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
$failed = array_filter($report, static fn($job) => empty($job['ok']));
exit($failed ? 1 : 0);
