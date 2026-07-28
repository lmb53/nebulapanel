<?php
/**
 * Docker Compose module — Dockge-style stack management plus a small app store
 * of ready-made compose templates.
 *
 * Each stack is a directory under data/stacks/<name>/ holding a
 * docker-compose.yml owned by the dedicated panel account. Privileged work is
 * delegated to validated helper actions. The compose project name is always
 * pinned to the stack name with `-p`.
 */

require_once APP_ROOT . '/lib/mod_docker.php';

/**
 * Resolve the compose command line this server can actually run.
 *
 * Compose v2 ships as a CLI plugin (`docker compose`) which Ubuntu's `docker.io`
 * package does NOT pull in, so a perfectly working Docker install can still have
 * no compose. Fall back to the standalone `docker-compose` binary when it is
 * present. Returns null when neither is usable.
 */
function compose_bin(): ?string
{
    static $bin = false;
    if ($bin !== false) { return $bin; }
    $bin = null;
    if (dk_available()) {
        [$code] = helper_cmd('compose-version plugin', 20);
        if ($code === 0) {
            $bin = 'plugin';
        } elseif (has_cmd('docker-compose')) {
            [$code2] = helper_cmd('compose-version standalone', 20);
            if ($code2 === 0) { $bin = 'standalone'; }
        }
    }
    return $bin;
}

/** True when a compose implementation is available. */
function compose_available(): bool
{
    return compose_bin() !== null;
}

/**
 * Why compose is unavailable, and whether the panel can fix it — surfaced in the
 * UI instead of the old bare "Docker Compose is not available on this server."
 */
function compose_availability(): array
{
    if (compose_available()) {
        return ['available' => true, 'installable' => false, 'reason' => '', 'bin' => compose_bin()];
    }
    if (!dk_available()) {
        return [
            'available'   => false,
            'installable' => false,
            'reason'      => 'Docker is not installed on this server. Install Docker from Install Apps first, then install Compose here.',
            'bin'         => null,
        ];
    }
    // Docker is there — distinguish "plugin missing" from "sudo/daemon problem",
    // because only the first one is fixable by installing a package.
    [$code, $out] = helper_cmd('docker-query version', 20);
    if ($code !== 0) {
        $err = trim($out);
        if (stripos($err, 'a password is required') !== false || stripos($err, 'may not run sudo') !== false) {
            $reason = 'The validating helper cannot run Docker. Re-run install.sh on the server.';
        } elseif (stripos($err, 'daemon') !== false || stripos($err, 'connect') !== false) {
            $reason = 'The Docker daemon is not running. Start the docker service from Services, then reload this page.';
        } else {
            $reason = 'Docker is installed but not responding: ' . ($err ?: 'unknown error');
        }
        return ['available' => false, 'installable' => false, 'reason' => $reason, 'bin' => null];
    }
    return [
        'available'   => false,
        'installable' => true,
        'reason'      => 'Docker is running, but the Compose plugin is not installed — Ubuntu\'s docker.io package ships without it. '
                       . 'Install it now to deploy stacks and App Store apps.',
        'bin'         => null,
    ];
}

/**
 * Install the Docker Compose plugin (apt) via the privileged helper, which knows
 * the different package names across Debian/Ubuntu releases.
 */
function compose_install(?callable $onOutput = null): array
{
    if (compose_available()) {
        return ['ok' => true, 'output' => 'Docker Compose is already available.'];
    }
    if (!dk_available()) {
        return ['ok' => false, 'error' => 'Install Docker first (Install Apps → Docker).'];
    }
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed. Re-run install.sh.'];
    }
    [$code, $out] = $onOutput
        ? helper_cmd_stream('compose-install', $onOutput, 900)
        : helper_cmd('compose-install', 900);
    audit('compose.install-plugin', 'exit ' . $code);
    if ($code !== 0) {
        $err = trim($out);
        if (stripos($err, 'unknown command') !== false) {
            $err = 'The privileged helper on this server is out of date. Update the panel (Panel Updates) or re-run install.sh, then try again.';
        }
        return ['ok' => false, 'error' => $err ?: 'Could not install Docker Compose.'];
    }
    return ['ok' => true, 'output' => $out];
}

/** Root directory holding every stack folder. */
function compose_root(): string
{
    $dir = DATA_DIR . '/stacks';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    return $dir;
}

/** Stack names are lower-case, filesystem- and compose-project-safe. */
function compose_name_ok(string $name): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $name);
}

function compose_stack_dir(string $name): string
{
    return compose_root() . '/' . $name;
}

function compose_file(string $name): string
{
    return compose_stack_dir($name) . '/docker-compose.yml';
}

/** Map of compose project name => status string, from `docker compose ls`. */
function compose_project_states(): array
{
    $states = [];
    $bin = compose_bin();
    if ($bin === null) { return $states; }
    [$code, $out] = helper_cmd('compose-list ' . escapeshellarg($bin), 30);
    if ($code !== 0) { return $states; }
    $out = trim($out);
    if ($out === '') { return $states; }
    // v2 emits either a JSON array or newline-delimited objects depending on version.
    $rows = [];
    $decoded = json_decode($out, true);
    if (is_array($decoded) && array_is_list($decoded)) {
        $rows = $decoded;
    } else {
        foreach (preg_split('/\r?\n/', $out) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $j = json_decode($line, true);
            if (is_array($j)) { $rows[] = $j; }
        }
    }
    foreach ($rows as $row) {
        $name = (string) ($row['Name'] ?? '');
        if ($name !== '') { $states[$name] = (string) ($row['Status'] ?? ''); }
    }
    return $states;
}

/** List every stack folder on disk, enriched with its live compose status. */
function compose_list(): array
{
    $states = compose_project_states();
    $stacks = [];
    foreach (glob(compose_root() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        if (!compose_name_ok($name)) { continue; }
        $file = compose_file($name);
        $status = $states[$name] ?? '';
        $running = $status !== '' && stripos($status, 'exited') === false && stripos($status, 'created') === false;
        $stacks[] = [
            'name'    => $name,
            'status'  => $status,
            'running' => $running,
            'exists'  => is_file($file),
            'updated' => is_file($file) ? filemtime($file) : null,
            // Surfaced so the UI can say where a stack is actually reachable
            // instead of implying the host port is open to the world.
            'ports'   => compose_stack_ports($name),
            'proxies' => compose_stack_proxies($name),
        ];
    }
    usort($stacks, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $stacks;
}

/** Read a stack's compose file. */
function compose_read(string $name): array
{
    if (!compose_name_ok($name)) { return ['ok' => false, 'error' => 'Invalid stack name.']; }
    $file = compose_file($name);
    if (!is_file($file)) { return ['ok' => false, 'error' => 'Stack not found.']; }
    return ['ok' => true, 'name' => $name, 'content' => (string) file_get_contents($file)];
}

/** Create or overwrite a stack's compose file. */
function compose_save(string $name, string $content, bool $create = false): array
{
    if (!compose_name_ok($name)) {
        return ['ok' => false, 'error' => 'Stack name must be lower-case letters, numbers, dashes or underscores.'];
    }
    if (strlen($content) > 256 * 1024) {
        return ['ok' => false, 'error' => 'Compose file is too large.'];
    }
    if (stripos($content, 'services:') === false) {
        return ['ok' => false, 'error' => 'Compose file must define a top-level "services:" block.'];
    }
    $dangerous = [
        '/^\s*privileged\s*:\s*true\s*$/mi' => 'privileged containers',
        '/^\s*(network_mode|pid|ipc|uts|userns_mode)\s*:\s*host\s*$/mi' => 'host namespace sharing',
        '#/var/run/docker\.sock#i' => 'Docker socket mounts',
        '/^\s*devices\s*:/mi' => 'host devices',
        '/^\s*cap_add\s*:/mi' => 'added Linux capabilities',
        '/^\s*-\s*["\']?(?:\/|\.{1,2}\/|~\/)[^:\r\n]*:/mi' => 'host bind mounts',
        '/^\s*type\s*:\s*bind\s*$/mi' => 'host bind mounts',
        '/^\s*(build|env_file|extends|include|configs|secrets|volumes_from|driver_opts|cgroup[^:]*)\s*:/mi' => 'host file, build, or daemon integration',
        '/^\s*published\s*:/mi' => 'long-form published ports',
        '/^\s*sysctls\s*:/mi' => 'kernel parameter changes',
        '/\$\{/' => 'environment interpolation',
    ];
    foreach ($dangerous as $pattern => $label) {
        if (preg_match($pattern, $content)) {
            return ['ok' => false, 'error' => 'Compose policy blocks ' . $label . '.'];
        }
    }
    if (preg_match_all('/^\s*image\s*:\s*["\']?([^"\'\s#]+)["\']?\s*$/mi', $content, $images)) {
        foreach ($images[1] as $image) {
            if (!dk_pinned_image_ok((string) $image)) {
                return ['ok' => false, 'error' => 'Every Compose image must use an explicit non-latest tag or digest.'];
            }
        }
    }
    if (preg_match_all('/^\s*-\s*["\']?([^"\'\s]+):([0-9]+)(?:\/(?:tcp|udp))?["\']?\s*$/mi', $content, $ports, PREG_SET_ORDER)) {
        foreach ($ports as $port) {
            $host = $port[1];
            $number = (int) $port[2];
            if ($number < 1 || $number > 65535
                || !preg_match('/^(127\.0\.0\.1|\[::1\]):[0-9]+$/', $host)) {
                return ['ok' => false, 'error' => 'Published ports must use a valid 1-65535 port and bind to loopback.'];
            }
        }
    }
    $dir = compose_stack_dir($name);
    $file = compose_file($name);
    if ($create && is_file($file)) {
        return ['ok' => false, 'error' => 'A stack with that name already exists.'];
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return ['ok' => false, 'error' => 'Could not create the stack directory.'];
    }
    // Normalise line endings so YAML parsers on the docker side stay happy.
    $content = str_replace("\r\n", "\n", $content);
    // When Compose is installed, use its real parser before replacing the
    // active file. Static policy checks above remain mandatory either way.
    $bin = compose_bin();
    if ($bin !== null) {
        $validationFile = $dir . '/.validate-' . bin2hex(random_bytes(6)) . '.yml';
        if (file_put_contents($validationFile, $content, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Could not stage the Compose file for validation.'];
        }
        chmod($validationFile, 0600);
        [$validationCode, $validationOut] = helper_cmd(
            'compose-run ' . escapeshellarg($bin) . ' validate ' . escapeshellarg($name) . ' ' . escapeshellarg($validationFile),
            60
        );
        unlink($validationFile);
        if ($validationCode !== 0) {
            return ['ok' => false, 'error' => 'Compose validation failed: ' . redact_secrets(trim($validationOut))];
        }
    }
    if (@file_put_contents($file, $content) === false) {
        return ['ok' => false, 'error' => 'Could not write the compose file.'];
    }
    @chmod($file, 0600);
    audit('compose.save', $name);
    return ['ok' => true, 'name' => $name];
}

/**
 * Run a compose lifecycle command for a stack, streaming output when a callback
 * is supplied. $action ∈ up|down|stop|start|restart|pull|destroy.
 */
function compose_action(string $name, string $action, ?callable $onOutput = null): array
{
    if (!compose_name_ok($name)) { return ['ok' => false, 'error' => 'Invalid stack name.']; }
    $file = compose_file($name);
    if (!is_file($file)) { return ['ok' => false, 'error' => 'Stack not found.']; }

    $verbs = [
        'up'      => 'up -d --remove-orphans',
        'down'    => 'down',
        'stop'    => 'stop',
        'start'   => 'start',
        'restart' => 'restart',
        'pull'    => 'pull',
        'destroy' => 'down -v --remove-orphans',
    ];
    if (!isset($verbs[$action])) { return ['ok' => false, 'error' => 'Invalid compose action.']; }

    $bin = compose_bin();
    if ($bin === null) { return ['ok' => false, 'error' => compose_availability()['reason']]; }
    $base = 'compose-run ' . escapeshellarg($bin) . ' ' . escapeshellarg($action)
        . ' ' . escapeshellarg($name) . ' ' . escapeshellarg($file);
    $timeout = in_array($action, ['up', 'pull', 'restart'], true) ? 600 : 180;
    if ($onOutput) {
        [$code, $out] = helper_cmd_stream($base, $onOutput, $timeout);
    } else {
        [$code, $out] = helper_cmd($base, $timeout);
    }
    audit('compose.' . $action, $name . ' (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    return ['ok' => true, 'output' => $out];
}

/** Bring a stack down (optionally with volumes) and delete its folder. */
function compose_remove(string $name, bool $volumes = false, ?callable $onOutput = null): array
{
    if (!compose_name_ok($name)) { return ['ok' => false, 'error' => 'Invalid stack name.']; }
    $res = compose_action($name, $volumes ? 'destroy' : 'down', $onOutput);
    if (empty($res['ok'])) {
        return $res;
    }
    foreach (compose_stack_proxies($name) as $proxy) {
        compose_proxy_remove((string) $proxy['domain']);
    }
    $dir = compose_stack_dir($name);
    if (is_dir($dir) && strpos(realpath($dir) ?: '', realpath(compose_root()) ?: '#') === 0) {
        compose_rmdir($dir);
    }
    audit('compose.remove', $name);
    return ['ok' => true, 'output' => $res['output'] ?? ''];
}

/** Recursively remove a stack directory (compose files only, never volumes). */
function compose_rmdir(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) { compose_rmdir($path); }
        else { @unlink($path); }
    }
    @rmdir($dir);
}

/** Tail a stack's aggregated container logs. */
function compose_logs(string $name, int $lines = 200): array
{
    if (!compose_name_ok($name)) { return ['ok' => false, 'error' => 'Invalid stack name.']; }
    $file = compose_file($name);
    if (!is_file($file)) { return ['ok' => false, 'error' => 'Stack not found.']; }
    $lines = max(1, min(2000, $lines));
    $bin = compose_bin();
    if ($bin === null) { return ['ok' => false, 'error' => compose_availability()['reason']]; }
    $cmd = 'compose-run ' . escapeshellarg($bin) . ' logs ' . escapeshellarg($name)
        . ' ' . escapeshellarg($file) . ' ' . $lines;
    [$code, $out] = helper_cmd($cmd, 60);
    if ($code !== 0) { return ['ok' => false, 'error' => sudo_error($out, $code)]; }
    return ['ok' => true, 'logs' => $out];
}

// ---------------------------------------------------------------------------
// Reverse proxies
//
// App Store containers publish their ports on 127.0.0.1 only, so reaching one
// at http://<server-ip>:<port> times out no matter what the firewall says —
// nothing is listening on the public interface. Rather than opening the port,
// front the stack with an Nginx vhost on a real hostname, which also makes the
// existing SSL flow (Websites → SSL) apply to it.
// ---------------------------------------------------------------------------

/** Host ports a stack publishes on loopback, parsed from its compose file. */
function compose_stack_ports(string $name): array
{
    if (!compose_name_ok($name)) { return []; }
    $file = compose_file($name);
    if (!is_file($file)) { return []; }
    $content = (string) file_get_contents($file);
    $ports = [];
    if (preg_match_all('/^\s*-\s*["\']?(?:127\.0\.0\.1|\[::1\]):([0-9]+):[0-9]+(?:\/(?:tcp|udp))?["\']?\s*$/mi', $content, $m)) {
        foreach ($m[1] as $port) {
            $port = (int) $port;
            if ($port >= 1 && $port <= 65535 && !in_array($port, $ports, true)) { $ports[] = $port; }
        }
    }
    sort($ports);
    return $ports;
}

function compose_proxy_file(): string
{
    return DATA_DIR . '/proxies.json';
}

function compose_settings_file(): string
{
    return DATA_DIR . '/compose-settings.json';
}

/**
 * Base domain new stacks are published under automatically. Empty disables
 * auto-publishing, in which case stacks stay loopback-only until a hostname is
 * added by hand.
 */
function compose_proxy_domain(): string
{
    $raw = json_decode((string) @file_get_contents(compose_settings_file()), true);
    $domain = is_array($raw) ? (string) ($raw['proxy_domain'] ?? '') : '';
    return domain_name_ok($domain) ? $domain : '';
}

function compose_set_proxy_domain(string $domain): array
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    if ($domain !== '' && !domain_name_ok($domain)) {
        return ['ok' => false, 'error' => 'Enter a valid base domain such as apps.example.com, or clear the field to disable auto-publishing.'];
    }
    if (!write_json_file(compose_settings_file(), ['proxy_domain' => $domain], 0600)) {
        return ['ok' => false, 'error' => 'Could not save the compose settings.'];
    }
    audit('compose.proxy-domain', $domain !== '' ? $domain : '(disabled)');
    return ['ok' => true, 'proxy_domain' => $domain];
}

/**
 * Ports worth putting an HTTP reverse proxy in front of.
 *
 * An Nginx vhost only speaks HTTP, so auto-publishing every published port
 * would put a broken web front end on things like Gitea's SSH port or a
 * database. Manual publishing still allows any port — that is the operator's
 * call — but automatic publishing sticks to ports that plausibly serve HTTP.
 */
function compose_http_ports(string $stack): array
{
    $nonHttp = [22, 2222, 25, 53, 110, 143, 465, 587, 993, 995,
                1433, 3306, 5432, 5433, 6379, 9000, 11211, 27017];
    return array_values(array_filter(
        compose_stack_ports($stack),
        static fn(int $port): bool => !in_array($port, $nonHttp, true)
    ));
}

/**
 * Hostname a stack's port is auto-published under. The first (lowest HTTP) port
 * owns the bare <stack>.<base> name; any additional port is suffixed so a
 * multi-port stack cannot collide with itself. Underscores are legal in stack
 * names but not in hostnames.
 */
function compose_proxy_hostname(string $stack, int $port, bool $primary): string
{
    $label = str_replace('_', '-', $stack);
    if (!$primary) { $label .= '-' . $port; }
    return $label . '.' . compose_proxy_domain();
}

/** Proxies for this stack whose target port the compose file no longer publishes. */
function compose_stale_proxies(string $stack): array
{
    $ports = compose_stack_ports($stack);
    return array_values(array_filter(
        compose_stack_proxies($stack),
        static fn(array $proxy): bool => !in_array((int) $proxy['port'], $ports, true)
    ));
}

/**
 * Publish every port a stack exposes, and drop proxies whose port it no longer
 * exposes. Never fails the caller: a stack that deployed fine must not be
 * reported as broken because DNS or Nginx was not ready for the proxy.
 */
function compose_autoproxy(string $stack): array
{
    $result = ['created' => [], 'removed' => [], 'errors' => []];
    if (!compose_name_ok($stack)) { return $result; }

    // Drop proxies pointing at ports the stack no longer publishes, whether or
    // not auto-publishing is still enabled — those vhosts are dead either way.
    foreach (compose_stale_proxies($stack) as $stale) {
        $removed = compose_proxy_remove((string) $stale['domain']);
        if (!empty($removed['ok'])) { $result['removed'][] = $stale['domain']; }
        else { $result['errors'][] = $stale['domain'] . ': ' . (string) ($removed['error'] ?? 'could not remove'); }
    }

    $httpPorts = compose_http_ports($stack);
    if (compose_proxy_domain() === '' || !$httpPorts) { return $result; }
    $claimed = [];
    foreach (compose_stack_proxies($stack) as $existing) {
        $claimed[(int) $existing['port']] = true;
    }
    foreach ($httpPorts as $index => $port) {
        if (isset($claimed[$port])) { continue; }
        $host = compose_proxy_hostname($stack, $port, $index === 0);
        $created = compose_proxy_create($host, $stack, $port);
        if (!empty($created['ok'])) { $result['created'][] = $host; }
        else { $result['errors'][] = $host . ': ' . (string) ($created['error'] ?? 'failed'); }
    }
    return $result;
}

/** domain => ['stack' => name, 'port' => int, 'created' => ISO8601] */
function compose_proxies(): array
{
    $raw = json_decode((string) @file_get_contents(compose_proxy_file()), true);
    return is_array($raw) ? $raw : [];
}

/** Proxies belonging to one stack, as a flat list. */
function compose_stack_proxies(string $name): array
{
    $rows = [];
    foreach (compose_proxies() as $domain => $meta) {
        if ((string) ($meta['stack'] ?? '') === $name) {
            $rows[] = ['domain' => $domain, 'port' => (int) ($meta['port'] ?? 0)];
        }
    }
    return $rows;
}

/** Point a hostname at one of a stack's loopback ports. */
function compose_proxy_create(string $domain, string $stack, int $port): array
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    if (!domain_name_ok($domain)) {
        return ['ok' => false, 'error' => 'Enter a valid hostname such as app.example.com.'];
    }
    if (!compose_name_ok($stack) || !is_file(compose_file($stack))) {
        return ['ok' => false, 'error' => 'Stack not found.'];
    }
    $published = compose_stack_ports($stack);
    if (!$published) {
        return ['ok' => false, 'error' => 'This stack does not publish a host port, so there is nothing to proxy to.'];
    }
    if (!in_array($port, $published, true)) {
        return ['ok' => false, 'error' => 'Port ' . $port . ' is not published by this stack.'];
    }
    $proxies = compose_proxies();
    if (isset($proxies[$domain]) && (string) ($proxies[$domain]['stack'] ?? '') !== $stack) {
        return ['ok' => false, 'error' => 'That hostname already proxies to another stack.'];
    }
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed. Re-run install.sh.'];
    }
    [$code, $out] = helper_cmd('proxy-create ' . escapeshellarg($domain) . ' ' . $port, 60);
    if ($code !== 0) {
        $err = trim($out);
        if (stripos($err, 'unknown command') !== false) {
            $err = 'The privileged helper on this server is out of date. Update the panel (Panel Updates) '
                 . 'or re-run install.sh, then try again.';
        }
        return ['ok' => false, 'error' => $err ?: 'Could not create the reverse proxy.'];
    }
    $proxies[$domain] = ['stack' => $stack, 'port' => $port, 'created' => date('c')];
    write_json_file(compose_proxy_file(), $proxies, 0600);
    audit('compose.proxy-create', $domain . ' -> ' . $stack . ':' . $port);
    return ['ok' => true, 'domain' => $domain, 'port' => $port];
}

/** Remove a panel-managed proxy vhost. */
function compose_proxy_remove(string $domain): array
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    if (!domain_name_ok($domain)) {
        return ['ok' => false, 'error' => 'Invalid hostname.'];
    }
    $proxies = compose_proxies();
    if (!isset($proxies[$domain])) {
        return ['ok' => false, 'error' => 'No panel-managed proxy for that hostname.'];
    }
    if (!helper_available()) {
        return ['ok' => false, 'error' => 'Privileged helper not installed. Re-run install.sh.'];
    }
    [$code, $out] = helper_cmd('proxy-remove ' . escapeshellarg($domain), 60);
    if ($code !== 0) {
        return ['ok' => false, 'error' => trim($out) ?: 'Could not remove the reverse proxy.'];
    }
    unset($proxies[$domain]);
    write_json_file(compose_proxy_file(), $proxies, 0600);
    audit('compose.proxy-remove', $domain);
    return ['ok' => true];
}

/** Create a stack from an app-store template and return its name. */
function compose_install_template(string $key, string $name): array
{
    $catalog = compose_catalog();
    if (!isset($catalog[$key])) { return ['ok' => false, 'error' => 'Unknown app.']; }
    if ($name === '') { $name = $key; }
    if (!compose_name_ok($name)) {
        return ['ok' => false, 'error' => 'Stack name must be lower-case letters, numbers, dashes or underscores.'];
    }
    if (is_file(compose_file($name))) {
        return ['ok' => false, 'error' => 'A stack named "' . $name . '" already exists.'];
    }
    $generatedSecrets = [];
    $content = preg_replace_callback('/change-me(?:-root|-please)?/', static function (array $match) use (&$generatedSecrets): string {
        // Repeated placeholders intentionally share a value (for example the
        // application's DB password and the database service's copy).
        return $generatedSecrets[$match[0]] ??= bin2hex(random_bytes(24));
    }, (string) $catalog[$key]['compose']);
    $content = preg_replace('/^\s*container_name\s*:.*\R?/mi', '', (string) $content) ?? (string) $content;
    $content = preg_replace_callback(
        '/(["\'])([0-9]{2,5}:[0-9]{2,5}(?:\/(?:tcp|udp))?)\1/',
        static fn(array $match): string => $match[1] . '127.0.0.1:' . $match[2] . $match[1],
        (string) $content
    ) ?? (string) $content;
    $save = compose_save($name, $content, true);
    if (empty($save['ok'])) { return $save; }
    audit('compose.install', $key . ' as ' . $name);
    return ['ok' => true, 'name' => $name];
}

/**
 * App store catalog — popular self-hosted apps as ready-to-run compose files.
 * Each template uses named volumes and a sensible published port so a stack can
 * be deployed with a single click and edited afterwards.
 */
function compose_catalog(): array
{
    $catalog = [
        'uptime-kuma' => [
            'name' => 'Uptime Kuma', 'category' => 'Monitoring', 'icon' => 'activity', 'logo' => 'logos/uptime-kuma.svg',
            'description' => 'Self-hosted uptime monitoring with status pages and alerts.',
            'port' => 3001,
            'compose' => "services:\n  uptime-kuma:\n    image: louislam/uptime-kuma:1.23.16\n    container_name: uptime-kuma\n    restart: unless-stopped\n    ports:\n      - \"3001:3001\"\n    volumes:\n      - uptime_kuma_data:/app/data\nvolumes:\n  uptime_kuma_data:\n",
        ],
        'nextcloud' => [
            'name' => 'Nextcloud', 'category' => 'Productivity', 'icon' => 'cloud', 'logo' => 'logos/nextcloud.svg',
            'description' => 'Private file sync, sharing and collaboration suite.',
            'port' => 8080,
            'compose' => "services:\n  nextcloud:\n    image: nextcloud:30.0.6-apache\n    container_name: nextcloud\n    restart: unless-stopped\n    ports:\n      - \"8080:80\"\n    environment:\n      MYSQL_HOST: db\n      MYSQL_DATABASE: nextcloud\n      MYSQL_USER: nextcloud\n      MYSQL_PASSWORD: change-me-please\n    volumes:\n      - nextcloud_data:/var/www/html\n    depends_on:\n      - db\n  db:\n    image: mariadb:11\n    container_name: nextcloud-db\n    restart: unless-stopped\n    command: --transaction-isolation=READ-COMMITTED --binlog-format=ROW\n    environment:\n      MYSQL_ROOT_PASSWORD: change-me-root\n      MYSQL_DATABASE: nextcloud\n      MYSQL_USER: nextcloud\n      MYSQL_PASSWORD: change-me-please\n    volumes:\n      - nextcloud_db:/var/lib/mysql\nvolumes:\n  nextcloud_data:\n  nextcloud_db:\n",
        ],
        'gitea' => [
            'name' => 'Gitea', 'category' => 'Development', 'icon' => 'git-branch', 'logo' => 'logos/gitea.svg',
            'description' => 'Lightweight self-hosted Git service with a web UI.',
            'port' => 3000,
            'compose' => "services:\n  gitea:\n    image: gitea/gitea:1.22.6\n    container_name: gitea\n    restart: unless-stopped\n    environment:\n      USER_UID: 1000\n      USER_GID: 1000\n    ports:\n      - \"3000:3000\"\n      - \"2222:22\"\n    volumes:\n      - gitea_data:/data\nvolumes:\n  gitea_data:\n",
        ],
        'vaultwarden' => [
            'name' => 'Vaultwarden', 'category' => 'Security', 'icon' => 'key-round', 'logo' => 'logos/vaultwarden.svg',
            'description' => 'Bitwarden-compatible password manager server.',
            'port' => 8081,
            'compose' => "services:\n  vaultwarden:\n    image: vaultwarden/server:1.32.7\n    container_name: vaultwarden\n    restart: unless-stopped\n    environment:\n      WEBSOCKET_ENABLED: \"true\"\n    ports:\n      - \"8081:80\"\n    volumes:\n      - vaultwarden_data:/data\nvolumes:\n  vaultwarden_data:\n",
        ],
        'n8n' => [
            'name' => 'n8n', 'category' => 'Automation', 'icon' => 'workflow', 'logo' => 'logos/n8n.svg',
            'description' => 'Workflow automation with a fair-code visual editor.',
            'port' => 5678,
            'compose' => "services:\n  n8n:\n    image: docker.n8n.io/n8nio/n8n:1.82.1\n    restart: unless-stopped\n    ports:\n      - \"5678:5678\"\n    environment:\n      N8N_SECURE_COOKIE: \"true\"\n    volumes:\n      - n8n_data:/home/node/.n8n\n    security_opt:\n      - no-new-privileges:true\nvolumes:\n  n8n_data:\n",
        ],
        'grafana' => [
            'name' => 'Grafana', 'category' => 'Monitoring', 'icon' => 'chart-line', 'logo' => 'logos/grafana.svg',
            'description' => 'Analytics and dashboards for metrics and logs.',
            'port' => 3002,
            'compose' => "services:\n  grafana:\n    image: grafana/grafana-oss:11.5.2\n    container_name: grafana\n    restart: unless-stopped\n    ports:\n      - \"3002:3000\"\n    volumes:\n      - grafana_data:/var/lib/grafana\nvolumes:\n  grafana_data:\n",
        ],
        'jellyfin' => [
            'name' => 'Jellyfin', 'category' => 'Media', 'icon' => 'clapperboard', 'logo' => 'logos/jellyfin.svg',
            'description' => 'Free software media system for movies, TV and music.',
            'port' => 8096,
            'compose' => "services:\n  jellyfin:\n    image: jellyfin/jellyfin:10.10.6\n    container_name: jellyfin\n    restart: unless-stopped\n    ports:\n      - \"8096:8096\"\n    volumes:\n      - jellyfin_config:/config\n      - jellyfin_cache:/cache\n      - jellyfin_media:/media\nvolumes:\n  jellyfin_config:\n  jellyfin_cache:\n  jellyfin_media:\n",
        ],
        'code-server' => [
            'name' => 'code-server', 'category' => 'Development', 'icon' => 'code', 'logo' => 'logos/code-server.svg',
            'description' => 'VS Code running in the browser, backed by this server.',
            'port' => 8443,
            'compose' => "services:\n  code-server:\n    image: codercom/code-server:4.96.4\n    container_name: code-server\n    restart: unless-stopped\n    environment:\n      PASSWORD: change-me-please\n    ports:\n      - \"8443:8080\"\n    volumes:\n      - code_server_data:/home/coder\nvolumes:\n  code_server_data:\n",
        ],
        'adminer' => [
            'name' => 'Adminer', 'category' => 'Database', 'icon' => 'database', 'logo' => 'logos/adminer.svg',
            'description' => 'Full-featured database management in a single file.',
            'port' => 8082,
            'compose' => "services:\n  adminer:\n    image: adminer:4.8.1\n    container_name: adminer\n    restart: unless-stopped\n    ports:\n      - \"8082:8080\"\n",
        ],
        'redis' => [
            'name' => 'Redis', 'category' => 'Database', 'icon' => 'database-zap', 'logo' => 'logos/redis.svg',
            'description' => 'In-memory data store for caching and queues.',
            'port' => 6379,
            'compose' => "services:\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n    command: redis-server --appendonly yes\n    volumes:\n      - redis_data:/data\n    security_opt:\n      - no-new-privileges:true\nvolumes:\n  redis_data:\n",
        ],
        'postgres' => [
            'name' => 'PostgreSQL', 'category' => 'Database', 'icon' => 'database', 'logo' => 'logos/postgres.svg',
            'description' => 'Powerful open-source relational database.',
            'port' => 5432,
            'compose' => "services:\n  postgres:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_PASSWORD: change-me-please\n      POSTGRES_DB: app\n    volumes:\n      - postgres_data:/var/lib/postgresql/data\n    security_opt:\n      - no-new-privileges:true\nvolumes:\n  postgres_data:\n",
        ],
        'wordpress' => [
            'name' => 'WordPress', 'category' => 'CMS', 'icon' => 'newspaper', 'logo' => 'logos/wordpress.svg',
            'description' => 'The world’s most popular CMS with a bundled database.',
            'port' => 8083,
            'compose' => "services:\n  wordpress:\n    image: wordpress:6.7.2-php8.3-apache\n    container_name: wordpress\n    restart: unless-stopped\n    ports:\n      - \"8083:80\"\n    environment:\n      WORDPRESS_DB_HOST: db\n      WORDPRESS_DB_USER: wordpress\n      WORDPRESS_DB_PASSWORD: change-me-please\n      WORDPRESS_DB_NAME: wordpress\n    volumes:\n      - wordpress_data:/var/www/html\n    depends_on:\n      - db\n  db:\n    image: mariadb:11\n    container_name: wordpress-db\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: change-me-root\n      MYSQL_DATABASE: wordpress\n      MYSQL_USER: wordpress\n      MYSQL_PASSWORD: change-me-please\n    volumes:\n      - wordpress_db:/var/lib/mysql\nvolumes:\n  wordpress_data:\n  wordpress_db:\n",
        ],
    ];
    // Normalize the first image line against one auditable pin map so future
    // template edits cannot silently reintroduce a floating tag.
    $pinnedImages = [
        'uptime-kuma' => 'louislam/uptime-kuma:1.23.16',
        'nextcloud' => 'nextcloud:30.0.6-apache',
        'gitea' => 'gitea/gitea:1.22.6',
        'vaultwarden' => 'vaultwarden/server:1.32.7',
        'n8n' => 'docker.n8n.io/n8nio/n8n:1.82.1',
        'grafana' => 'grafana/grafana-oss:11.5.2',
        'jellyfin' => 'jellyfin/jellyfin:10.10.6',
        'code-server' => 'codercom/code-server:4.96.4',
        'adminer' => 'adminer:4.8.1',
        'redis' => 'redis:7.4.2-alpine',
        'postgres' => 'postgres:16.6-alpine',
        'wordpress' => 'wordpress:6.7.2-php8.3-apache',
    ];
    foreach ($pinnedImages as $key => $image) {
        if (!isset($catalog[$key])) continue;
        $catalog[$key]['compose'] = preg_replace(
            '/^(\s*image\s*:\s*)\S+/m',
            '$1' . $image,
            (string) $catalog[$key]['compose'],
            1
        ) ?? (string) $catalog[$key]['compose'];
    }
    return $catalog;
}

/** Public-facing catalog (no compose body) for listing in the UI. */
function compose_catalog_list(): array
{
    $list = [];
    foreach (compose_catalog() as $key => $app) {
        $list[] = [
            'key' => $key,
            'name' => $app['name'],
            'category' => $app['category'],
            'icon' => $app['icon'],
            // Official brand mark (vendored SVG); `icon` stays as the fallback.
            'logo' => !empty($app['logo']) ? asset($app['logo']) : null,
            'description' => $app['description'],
            'port' => $app['port'] ?? null,
        ];
    }
    return $list;
}
