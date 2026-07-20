<?php
/**
 * Nebula Panel — front controller.
 * Everything routes through here via ?r=<route>. Assets are served statically.
 */

require __DIR__ . '/lib/bootstrap.php';

$route = $_GET['r'] ?? 'dashboard';
$method = $_SERVER['REQUEST_METHOD'];

// --------------------------------------------------------------------------
// Public routes (no auth): setup + login
// --------------------------------------------------------------------------
switch ($route) {
    case 'setup':
        if (is_setup_complete()) {
            redirect('login');
        }
        $error = null;
        if ($method === 'POST') {
            csrf_check();
            $res = create_admin($_POST['username'] ?? '', $_POST['password'] ?? '');
            if ($res['ok']) {
                attempt_login($_POST['username'], $_POST['password']);
                redirect('dashboard');
            }
            $error = $res['error'];
        }
        render('setup', ['error' => $error], false);
        return;

    case 'login':
        if (!is_setup_complete()) {
            redirect('setup');
        }
        if (is_logged_in()) {
            redirect('dashboard');
        }
        $error = null;
        if ($method === 'POST') {
            csrf_check();
            if (attempt_login($_POST['username'] ?? '', $_POST['password'] ?? '')) {
                redirect('dashboard');
            }
            audit('login', 'failed for ' . ($_POST['username'] ?? '?'));
            $error = 'Invalid username or password.';
        }
        render('login', ['error' => $error], false);
        return;

    case 'logout':
        logout_user();
        redirect('login');
        return;
}

// --------------------------------------------------------------------------
// Everything below requires authentication.
// --------------------------------------------------------------------------
require_auth();

// --------------------------------------------------------------------------
// JSON API routes
// --------------------------------------------------------------------------
if (strpos($route, 'api/') === 0) {
    handle_api(substr($route, 4), $method);
    return;
}

// --------------------------------------------------------------------------
// HTML page routes
// --------------------------------------------------------------------------
switch ($route) {
    case 'dashboard':
        render('dashboard', ['facts' => system_facts()]);
        break;

    case 'services':
        render('services', ['services' => services_overview($config['services'])]);
        break;

    case 'files':
        require APP_ROOT . '/lib/files.php';
        $rel = $_GET['path'] ?? '';
        $abs = fm_resolve($rel);
        if ($abs === null || !is_dir($abs)) {
            // Fall back to root; show a note if root itself is missing.
            $abs = fm_resolve('');
        }
        render('files', [
            'root_ok'     => fm_root() !== '',
            'cur'         => $abs,
            'rel'         => $abs ? fm_rel($abs) : '',
            'listing'     => $abs ? fm_list($abs) : ['dirs' => [], 'files' => []],
            'breadcrumbs' => fm_breadcrumbs($abs ? fm_rel($abs) : ''),
        ]);
        break;

    case 'file-view':
        require APP_ROOT . '/lib/files.php';
        $abs = fm_resolve($_GET['path'] ?? '');
        if ($abs === null || !is_file($abs)) {
            http_response_code(404);
            render('dashboard', ['facts' => system_facts()]);
            break;
        }
        render('file-view', [
            'abs'     => $abs,
            'rel'     => fm_rel($abs),
            'is_text' => fm_is_text($abs),
            'content' => fm_is_text($abs) ? file_get_contents($abs) : null,
            'size'    => filesize($abs),
        ]);
        break;

    case 'file-download':
        require APP_ROOT . '/lib/files.php';
        $abs = fm_resolve($_GET['path'] ?? '');
        if ($abs === null || !is_file($abs) || !is_readable($abs)) {
            http_response_code(404);
            exit('Not found');
        }
        audit('file.download', fm_rel($abs));
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($abs) . '"');
        header('Content-Length: ' . filesize($abs));
        readfile($abs);
        exit;

    case 'sysinfo':
        render('sysinfo', [
            'facts' => system_facts(),
            'net'   => net_interfaces(),
            'mem'   => mem_info(),
            'disk'  => disk_info('/'),
        ]);
        break;

    default:
        http_response_code(404);
        render('dashboard', ['facts' => system_facts()]);
}

// ==========================================================================
// API dispatcher
// ==========================================================================
function handle_api(string $endpoint, string $method): void
{
    global $config;

    switch ($endpoint) {
        case 'metrics':
            $mem = mem_info();
            $disk = disk_info('/');
            json_out([
                'ok'   => true,
                'ts'   => time(),
                'cpu'  => cpu_usage(),
                'load' => load_avg(),
                'mem'  => $mem ? [
                    'total' => $mem['total'],
                    'used'  => $mem['used'],
                    'pct'   => round($mem['used'] / max(1, $mem['total']) * 100, 1),
                ] : null,
                'disk' => $disk ? [
                    'total' => $disk['total'],
                    'used'  => $disk['used'],
                    'pct'   => round($disk['used'] / max(1, $disk['total']) * 100, 1),
                ] : null,
                'uptime' => format_uptime(uptime_seconds()),
            ]);
            break;

        case 'services':
            if ($method === 'POST') {
                csrf_check();
                $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
                $res = service_action(
                    (string) ($body['name'] ?? ''),
                    (string) ($body['action'] ?? ''),
                    $config['services']
                );
                json_out($res, $res['ok'] ? 200 : 400);
            }
            json_out(['ok' => true, 'services' => services_overview($config['services'])]);
            break;

        case 'file-delete':
            require APP_ROOT . '/lib/files.php';
            if ($method !== 'POST') {
                json_out(['ok' => false, 'error' => 'POST required'], 405);
            }
            csrf_check();
            $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $abs = fm_resolve((string) ($body['path'] ?? ''), false);
            if ($abs === null || !file_exists($abs)) {
                json_out(['ok' => false, 'error' => 'Path not found or not allowed.'], 400);
            }
            $ok = is_dir($abs) ? @rmdir($abs) : @unlink($abs);
            audit('file.delete', fm_rel($abs) . ($ok ? '' : ' FAILED'));
            json_out($ok
                ? ['ok' => true]
                : ['ok' => false, 'error' => 'Delete failed (permissions, or directory not empty).'],
                $ok ? 200 : 400);
            break;

        default:
            json_out(['ok' => false, 'error' => 'Unknown endpoint'], 404);
    }
}
