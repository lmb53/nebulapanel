<?php
/**
 * Nebula Panel — front controller.
 * Routes via ?r=<route>:
 *   - public:   setup, login, logout
 *   - api:      api/<name>  -> api/<name>.php  (self-contained, emits JSON)
 *   - pages:    <route>     -> views/<route>.php (wrapped in the shell)
 * Assets are served statically by the web server.
 */

require __DIR__ . '/lib/bootstrap.php';

$route = $_GET['r'] ?? 'dashboard';
$method = $_SERVER['REQUEST_METHOD'];

// --------------------------------------------------------------------------
// Public routes (no auth): setup + login + logout
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
// JSON API routes: api/<name> -> api/<name>.php
// --------------------------------------------------------------------------
if (strpos($route, 'api/') === 0) {
    $name = substr($route, 4);
    $file = APP_ROOT . '/api/' . $name . '.php';
    if (preg_match('/^[a-z0-9_-]+$/', $name) && is_file($file)) {
        require $file;
        return;
    }
    json_out(['ok' => false, 'error' => 'Unknown endpoint'], 404);
}

// --------------------------------------------------------------------------
// Special: streaming file download (not a rendered page).
// --------------------------------------------------------------------------
if ($route === 'file-download') {
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
    return;
}

if ($route === 'backup-download') {
    require APP_ROOT . '/lib/mod_backups.php';
    $abs = backup_resolve($_GET['file'] ?? '');
    if ($abs === null || !is_file($abs)) {
        http_response_code(404);
        exit('Not found');
    }
    audit('backup.download', basename($abs));
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($abs) . '"');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    return;
}

// --------------------------------------------------------------------------
// HTML page routes: views load their own data.
// --------------------------------------------------------------------------
if (is_page_route($route)) {
    render($route, [], true);
    return;
}

http_response_code(404);
render('dashboard', [], true);
