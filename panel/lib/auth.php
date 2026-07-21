<?php
/**
 * Authentication: first-run setup, login, logout, guards.
 * The admin account is stored (hashed) in data/admin.json.
 */

function admin_file(): string
{
    return DATA_DIR . '/admin.json';
}

function panel_users_file(): string
{
    return DATA_DIR . '/panel-users.json';
}

/** Roles intentionally describe access to panel features, not Linux accounts. */
function panel_roles(): array
{
    return [
        'admin' => ['label' => 'Administrator', 'description' => 'Full panel access, including users, updates and settings.'],
        'operator' => ['label' => 'Operator', 'description' => 'Runs scoped hosting operations without root-equivalent package, Docker, terminal, or panel administration access.'],
        'developer' => ['label' => 'Developer', 'description' => 'Manages assigned hosting resources without server-level or panel administration access.'],
        'auditor' => ['label' => 'Auditor', 'description' => 'Read-only access to dashboards, services, logs and diagnostics.'],
    ];
}

function panel_users(): array
{
    $users = @json_decode((string) @file_get_contents(panel_users_file()), true);
    if (is_array($users) && isset($users['users']) && is_array($users['users'])) {
        return array_map('panel_user_normalize', $users['users']);
    }
    $admin = @json_decode((string) @file_get_contents(admin_file()), true);
    if (!is_array($admin) || empty($admin['username']) || empty($admin['hash'])) { return []; }
    return [panel_user_normalize([
        'id' => 1, 'username' => (string) $admin['username'], 'hash' => (string) $admin['hash'],
        'role' => 'admin', 'enabled' => true, 'created' => (string) ($admin['created'] ?? date('c')),
    ])];
}

function panel_user_normalize(array $user): array
{
    $user['session_version'] = max(1, (int) ($user['session_version'] ?? 1));
    $user['enabled'] = !isset($user['enabled']) || (bool) $user['enabled'];
    $user['role'] = isset(panel_roles()[(string) ($user['role'] ?? '')]) ? (string) $user['role'] : 'auditor';
    return $user;
}

function with_panel_users_lock(callable $callback)
{
    $handle = @fopen(DATA_DIR . '/panel-users.lock', 'c');
    if ($handle === false || !@flock($handle, LOCK_EX)) {
        if (is_resource($handle)) { fclose($handle); }
        return ['ok' => false, 'error' => 'Could not lock the panel user store.'];
    }
    try { return $callback(); }
    finally { @flock($handle, LOCK_UN); fclose($handle); }
}

function save_panel_users(array $users): bool
{
    return write_json_file(panel_users_file(), ['version' => 1, 'users' => array_values($users)]);
}

function panel_user_public(array $user): array
{
    return [
        'id' => (int) ($user['id'] ?? 0), 'username' => (string) ($user['username'] ?? ''),
        'role' => (string) ($user['role'] ?? 'auditor'), 'enabled' => !isset($user['enabled']) || (bool) $user['enabled'],
        'created' => (string) ($user['created'] ?? ''), 'last_login' => (string) ($user['last_login'] ?? ''),
    ];
}

function panel_user_create(string $username, string $password, string $role): array
{
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) return ['ok' => false, 'error' => 'Username must be 3-64 letters, numbers, dots, dashes, or underscores.'];
    if (strlen($password) < 12 || strlen($password) > 1024) return ['ok' => false, 'error' => 'Password must be between 12 and 1024 characters.'];
    if (!isset(panel_roles()[$role])) return ['ok' => false, 'error' => 'Invalid role.'];
    return with_panel_users_lock(function() use($username,$password,$role){
        $users=panel_users();foreach($users as $user)if(strcasecmp((string)($user['username']??''),$username)===0)return ['ok'=>false,'error'=>'That username already exists.'];
        $ids=array_map(fn($user)=>(int)($user['id']??0),$users);$users[]=['id'=>$ids?max($ids)+1:1,'username'=>$username,'hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$role,'enabled'=>true,'created'=>date('c'),'session_version'=>1];
        if(!save_panel_users($users))return ['ok'=>false,'error'=>'Could not save the panel user.'];audit('panel_user.create',$username.' ('.$role.')');return ['ok'=>true];
    });
}

function panel_user_update(int $id, string $role, bool $enabled, string $password = ''): array
{
    if (!isset(panel_roles()[$role])) return ['ok' => false, 'error' => 'Invalid role.'];
    if ($password !== '' && (strlen($password) < 12 || strlen($password) > 1024)) return ['ok' => false, 'error' => 'Password must be between 12 and 1024 characters.'];
    return with_panel_users_lock(function() use($id,$role,$enabled,$password){
        $users=panel_users();$found=false;foreach($users as &$user){if((int)($user['id']??0)!==$id)continue;if((int)($_SESSION['uid']??0)===$id&&(!$enabled||$role!=='admin'))return ['ok'=>false,'error'=>'You cannot disable or demote your own active administrator account.'];$changed=($user['role']??'')!==$role||(bool)($user['enabled']??true)!==$enabled||$password!=='';$user['role']=$role;$user['enabled']=$enabled;if($password!=='')$user['hash']=password_hash($password,PASSWORD_DEFAULT);if($changed)$user['session_version']=max(1,(int)($user['session_version']??1))+1;$found=true;break;}unset($user);
        if(!$found)return ['ok'=>false,'error'=>'Panel user not found.'];if(!save_panel_users($users))return ['ok'=>false,'error'=>'Could not save the panel user.'];audit('panel_user.update','id '.$id.' ('.$role.', '.($enabled?'enabled':'disabled').')');return ['ok'=>true];
    });
}

function panel_user_delete(int $id): array
{
    if ((int) ($_SESSION['uid'] ?? 0) === $id) return ['ok' => false, 'error' => 'You cannot delete your own active account.'];
    return with_panel_users_lock(function() use($id){$users=panel_users();$next=array_values(array_filter($users,fn($user)=>(int)($user['id']??0)!==$id));if(count($next)===count($users))return ['ok'=>false,'error'=>'Panel user not found.'];$admins=array_filter($next,fn($user)=>($user['role']??'')==='admin'&&(!isset($user['enabled'])||$user['enabled']));if(!$admins)return ['ok'=>false,'error'=>'At least one enabled administrator is required.'];if(!save_panel_users($next))return ['ok'=>false,'error'=>'Could not save the panel users.'];audit('panel_user.delete','id '.$id);return ['ok'=>true];});
}

function current_role(): string
{
    return (string) ($_SESSION['role'] ?? 'auditor');
}

function role_can(string $capability, ?string $role = null): bool
{
    $role = $role ?? current_role();
    if ($role === 'admin') { return true; }
    $common = ['dashboard.read','services.read','logs.read','sysinfo.read','diagnostics.read','notifications.read'];
    $hosting = ['websites.manage','files.manage','domains.manage','dns.manage','ssl.manage','php.manage','databases.manage','phpmyadmin.use'];
    $caps = $role === 'operator'
        ? array_merge($common, $hosting, ['services.control','cron.manage','firewall.manage','backups.manage'])
        : ($role === 'developer' ? array_merge($common, $hosting) : $common);
    return in_array($capability, $caps, true);
}

function require_capability(string $capability): void
{
    if (role_can($capability)) { return; }
    if (function_exists('is_json_request') && is_json_request()) {
        json_out(['ok' => false, 'error' => 'Your role does not have permission for this action.'], 403);
    }
    http_response_code(403);
    exit('Forbidden');
}

function role_route_allowed(string $route, ?string $role = null): bool
{
    $role = $role ?? current_role();
    if ($role === 'admin') return true;
    $common = ['dashboard','services','logs','sysinfo','diagnostics','notifications'];
    $operator = array_merge($common, ['websites','files','file-edit','domains','dns','ssl','php','databases','phpmyadmin','cron','firewall','backups']);
    $developer = array_merge($common, ['websites','files','file-edit','domains','dns','ssl','php','databases','phpmyadmin']);
    $allowed = $role === 'operator' ? $operator : ($role === 'developer' ? $developer : $common);
    return in_array($route, $allowed, true);
}

function api_route_owner(string $name): string
{
    $map = ['metrics'=>'dashboard','health'=>'dashboard','processes'=>'dashboard','file-upload'=>'files','file-state'=>'files','file-save'=>'files','file-rename'=>'files','file-owner'=>'files','file-op'=>'files','file-mkfile'=>'files','file-mkdir'=>'files','file-chmod'=>'files','file-compress'=>'files','file-delete'=>'files','file-tree'=>'files','sites'=>'websites','git'=>'websites','compose'=>'docker','provision'=>'apps','pma'=>'phpmyadmin','selfupdate'=>'selfupdate'];
    return $map[$name] ?? $name;
}

function can_access_api(string $name, string $method): bool
{
    if (!role_route_allowed(api_route_owner($name))) return false;
    if ($method !== 'GET' && current_role() === 'auditor') return false;
    return true;
}

/** Has an admin account been created yet? */
function is_setup_complete(): bool
{
    return is_file(panel_users_file()) || is_file(admin_file());
}

/** Create the admin account (first-run only). */
function create_admin(string $username, string $password): array
{
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
        return ['ok' => false, 'error' => 'Username must be 3–64 letters, numbers, dots, dashes, or underscores.'];
    }
    if (strlen($password) < 12 || strlen($password) > 1024) {
        return ['ok' => false, 'error' => 'Password must be between 12 and 1024 characters.'];
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        return ['ok' => false, 'error' => 'Could not hash the password.'];
    }
    $lock = @fopen(DATA_DIR . '/setup.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) { @fclose($lock); }
        return ['ok' => false, 'error' => 'Could not lock the setup process. Check data directory permissions.'];
    }
    if (is_setup_complete()) {
        @flock($lock, LOCK_UN);
        fclose($lock);
        return ['ok' => false, 'error' => 'The administrator account has already been created.'];
    }
    $data = [
        'username' => $username,
        'hash'     => $hash,
        'created'  => date('c'),
    ];
    $ok = write_json_file(admin_file(), $data)
        && save_panel_users([panel_user_normalize(['id'=>1,'username'=>$username,'hash'=>$hash,'role'=>'admin','enabled'=>true,'created'=>$data['created']])]);
    @flock($lock, LOCK_UN);
    fclose($lock);
    if (!$ok) {
        return ['ok' => false, 'error' => 'Could not write ' . admin_file() . ' — check permissions.'];
    }
    return ['ok' => true];
}

function login_attempts_file(): string
{
    return DATA_DIR . '/login_attempts.json';
}

/** Return seconds until another login is allowed (0 means allowed). */
function login_retry_after(?string $ip = null): int
{
    global $config;
    $ip = $ip ?? client_ip();
    $max = max(1, (int) ($config['login_max_attempts'] ?? 5));
    $window = max(60, (int) ($config['login_window'] ?? 600));
    $data = @json_decode((string) @file_get_contents(login_attempts_file()), true);
    $attempts = is_array($data) && isset($data[hash('sha256', $ip)]) && is_array($data[hash('sha256', $ip)])
        ? $data[hash('sha256', $ip)] : [];
    $cutoff = time() - $window;
    $attempts = array_values(array_filter($attempts, fn($ts) => (int) $ts > $cutoff));
    if (count($attempts) < $max) {
        return 0;
    }
    return max(1, ((int) $attempts[0] + $window) - time());
}

/** Atomically reserve one attempt, returning retry seconds when blocked. */
function reserve_login_attempt(?string $ip = null): int
{
    global $config;
    $ip = $ip ?? client_ip();
    $key = hash('sha256', $ip);
    $max = max(1, (int) ($config['login_max_attempts'] ?? 5));
    $window = max(60, (int) ($config['login_window'] ?? 600));
    $path = login_attempts_file();
    $handle = @fopen($path, 'c+');
    if ($handle === false || !@flock($handle, LOCK_EX)) {
        if (is_resource($handle)) { @fclose($handle); }
        return $window; // fail closed if the throttle store is unavailable
    }
    $data = json_decode((string) stream_get_contents($handle), true);
    $data = is_array($data) ? $data : [];
    $cutoff = time() - $window;
    foreach ($data as $k => $attempts) {
        $data[$k] = is_array($attempts)
            ? array_values(array_filter($attempts, fn($ts) => (int) $ts > $cutoff)) : [];
        if (!$data[$k]) { unset($data[$k]); }
    }
    $attempts = $data[$key] ?? [];
    $retry = count($attempts) >= $max ? max(1, ((int) $attempts[0] + $window) - time()) : 0;
    if ($retry === 0) {
        $data[$key][] = time();
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($data));
    fflush($handle);
    @flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($path, 0600);
    return $retry;
}

function record_login_attempt(bool $success, ?string $ip = null): void
{
    global $config;
    $ip = $ip ?? client_ip();
    $key = hash('sha256', $ip);
    $window = max(60, (int) ($config['login_window'] ?? 600));
    $path = login_attempts_file();
    $handle = @fopen($path, 'c+');
    if ($handle === false || !@flock($handle, LOCK_EX)) {
        if (is_resource($handle)) { @fclose($handle); }
        return;
    }
    $raw = stream_get_contents($handle);
    $data = json_decode((string) $raw, true);
    $data = is_array($data) ? $data : [];
    $cutoff = time() - $window;
    foreach ($data as $k => $attempts) {
        if (!is_array($attempts)) { unset($data[$k]); continue; }
        $data[$k] = array_values(array_filter($attempts, fn($ts) => (int) $ts > $cutoff));
        if (!$data[$k]) { unset($data[$k]); }
    }
    if ($success) {
        unset($data[$key]);
    } else {
        $data[$key][] = time();
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($data));
    fflush($handle);
    @flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($path, 0600);
}

/** Attempt a login. Returns true on success. */
function attempt_login(string $username, string $password): bool
{
    if (!is_setup_complete()) {
        return false;
    }
    $matched = null;
    foreach (panel_users() as $candidate) {
        if (hash_equals((string) ($candidate['username'] ?? ''), $username)) { $matched = $candidate; break; }
    }
    $passwordOk = is_array($matched) && (!isset($matched['enabled']) || (bool) $matched['enabled']) && !empty($matched['hash']) && password_verify($password, (string) $matched['hash']);
    if (!$passwordOk) {
        // Constant-ish delay to blunt brute force / timing.
        usleep(300000);
        return false;
    }
    // Re-read and update under the same lock so a concurrent disable, password
    // reset, role change, or login cannot be silently overwritten here.
    $loginUpdate = with_panel_users_lock(function () use ($matched, $password): array {
        $users = panel_users();
        foreach ($users as &$candidate) {
            if ((int) ($candidate['id'] ?? 0) !== (int) ($matched['id'] ?? 0)) { continue; }
            if (empty($candidate['enabled']) || empty($candidate['hash']) || !password_verify($password, (string) $candidate['hash'])) {
                unset($candidate);
                return ['ok' => false];
            }
            if (password_needs_rehash((string) $candidate['hash'], PASSWORD_DEFAULT)) {
                $candidate['hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $candidate['last_login'] = date('c');
            $fresh = $candidate;
            unset($candidate);
            if (!save_panel_users($users)) { return ['ok' => false]; }
            return ['ok' => true, 'user' => $fresh];
        }
        unset($candidate);
        return ['ok' => false];
    });
    if (empty($loginUpdate['ok'])) { return false; }
    $matched = $loginUpdate['user'];
    session_regenerate_id(true);
    unset($_SESSION['csrf']);
    $_SESSION['uid'] = (int) ($matched['id'] ?? 1);
    $_SESSION['username'] = (string) ($matched['username'] ?? $username);
    $_SESSION['role'] = (string) ($matched['role'] ?? 'admin');
    $_SESSION['session_version'] = max(1, (int) ($matched['session_version'] ?? 1));
    $_SESSION['last_seen'] = time();
    record_login_attempt(true);
    audit('login', 'success');
    return true;
}

function refresh_session_identity(): void
{
    $uid = (int) ($_SESSION['uid'] ?? 0);
    $matched = null;
    foreach (panel_users() as $user) {
        if ((int) ($user['id'] ?? 0) === $uid) { $matched = $user; break; }
    }
    $expected = max(1, (int) ($matched['session_version'] ?? 1));
    $actual = max(1, (int) ($_SESSION['session_version'] ?? 1));
    if (!$matched || empty($matched['enabled']) || $expected !== $actual) {
        logout_user();
        return;
    }
    $_SESSION['username'] = (string) $matched['username'];
    $_SESSION['role'] = (string) ($matched['role'] ?? 'auditor');
}

function logout_user(): void
{
    if (!empty($_SESSION['username'])) {
        audit('logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function current_user(): ?string
{
    return $_SESSION['username'] ?? null;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['uid']);
}

/** Guard: require an authenticated session or bounce to login/setup. */
function require_auth(): void
{
    if (!is_setup_complete()) {
        redirect('setup');
    }
    if (!is_logged_in()) {
        redirect('login');
    }
}
