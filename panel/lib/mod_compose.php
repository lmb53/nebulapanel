<?php
/**
 * Docker Compose module — Dockge-style stack management plus a small app store
 * of ready-made compose templates.
 *
 * Each stack is a directory under data/stacks/<name>/ holding a
 * docker-compose.yml the panel writes directly (the data dir is owned by the
 * web user). Privileged work is delegated to `sudo docker compose`, reusing the
 * same docker sudoers grant the rest of the Docker module relies on. The
 * compose project name is always pinned to the stack name with `-p`.
 */

require_once APP_ROOT . '/lib/mod_docker.php';

/** Compose v2 is bundled with modern Docker; treat availability as docker + the plugin. */
function compose_available(): bool
{
    if (!dk_available()) { return false; }
    static $ok = null;
    if ($ok === null) {
        [$code] = sudo_cmd('docker compose version', 20);
        $ok = $code === 0;
    }
    return $ok;
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
    [$code, $out] = sudo_cmd('docker compose ls --all --format ' . escapeshellarg('json'), 30);
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

    $base = 'docker compose -p ' . escapeshellarg($name) . ' -f ' . escapeshellarg($file) . ' ' . $verbs[$action];
    $timeout = in_array($action, ['up', 'pull', 'restart'], true) ? 600 : 180;
    if ($onOutput) {
        [$code, $out] = sudo_cmd_stream($base, $onOutput, $timeout);
    } else {
        [$code, $out] = sudo_cmd($base, $timeout);
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
    // Even if `down` fails (e.g. already gone), still remove the folder so the
    // panel stops listing an orphaned stack.
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
    $cmd = 'docker compose -p ' . escapeshellarg($name) . ' -f ' . escapeshellarg($file)
        . ' logs --no-color --tail ' . $lines;
    [$code, $out] = sudo_cmd($cmd, 60);
    if ($code !== 0) { return ['ok' => false, 'error' => sudo_error($out, $code)]; }
    return ['ok' => true, 'logs' => $out];
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
    $save = compose_save($name, $catalog[$key]['compose'], true);
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
    return [
        'portainer' => [
            'name' => 'Portainer', 'category' => 'Management', 'icon' => 'container',
            'description' => 'Web UI to manage Docker containers, images and volumes.',
            'port' => 9443,
            'compose' => "services:\n  portainer:\n    image: portainer/portainer-ce:latest\n    container_name: portainer\n    restart: unless-stopped\n    ports:\n      - \"9443:9443\"\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n      - portainer_data:/data\nvolumes:\n  portainer_data:\n",
        ],
        'uptime-kuma' => [
            'name' => 'Uptime Kuma', 'category' => 'Monitoring', 'icon' => 'activity',
            'description' => 'Self-hosted uptime monitoring with status pages and alerts.',
            'port' => 3001,
            'compose' => "services:\n  uptime-kuma:\n    image: louislam/uptime-kuma:1\n    container_name: uptime-kuma\n    restart: unless-stopped\n    ports:\n      - \"3001:3001\"\n    volumes:\n      - uptime_kuma_data:/app/data\nvolumes:\n  uptime_kuma_data:\n",
        ],
        'nextcloud' => [
            'name' => 'Nextcloud', 'category' => 'Productivity', 'icon' => 'cloud',
            'description' => 'Private file sync, sharing and collaboration suite.',
            'port' => 8080,
            'compose' => "services:\n  nextcloud:\n    image: nextcloud:apache\n    container_name: nextcloud\n    restart: unless-stopped\n    ports:\n      - \"8080:80\"\n    environment:\n      MYSQL_HOST: db\n      MYSQL_DATABASE: nextcloud\n      MYSQL_USER: nextcloud\n      MYSQL_PASSWORD: change-me-please\n    volumes:\n      - nextcloud_data:/var/www/html\n    depends_on:\n      - db\n  db:\n    image: mariadb:11\n    container_name: nextcloud-db\n    restart: unless-stopped\n    command: --transaction-isolation=READ-COMMITTED --binlog-format=ROW\n    environment:\n      MYSQL_ROOT_PASSWORD: change-me-root\n      MYSQL_DATABASE: nextcloud\n      MYSQL_USER: nextcloud\n      MYSQL_PASSWORD: change-me-please\n    volumes:\n      - nextcloud_db:/var/lib/mysql\nvolumes:\n  nextcloud_data:\n  nextcloud_db:\n",
        ],
        'gitea' => [
            'name' => 'Gitea', 'category' => 'Development', 'icon' => 'git-branch',
            'description' => 'Lightweight self-hosted Git service with a web UI.',
            'port' => 3000,
            'compose' => "services:\n  gitea:\n    image: gitea/gitea:latest\n    container_name: gitea\n    restart: unless-stopped\n    environment:\n      USER_UID: 1000\n      USER_GID: 1000\n    ports:\n      - \"3000:3000\"\n      - \"2222:22\"\n    volumes:\n      - gitea_data:/data\n      - /etc/timezone:/etc/timezone:ro\n      - /etc/localtime:/etc/localtime:ro\nvolumes:\n  gitea_data:\n",
        ],
        'vaultwarden' => [
            'name' => 'Vaultwarden', 'category' => 'Security', 'icon' => 'key-round',
            'description' => 'Bitwarden-compatible password manager server.',
            'port' => 8081,
            'compose' => "services:\n  vaultwarden:\n    image: vaultwarden/server:latest\n    container_name: vaultwarden\n    restart: unless-stopped\n    environment:\n      WEBSOCKET_ENABLED: \"true\"\n    ports:\n      - \"8081:80\"\n    volumes:\n      - vaultwarden_data:/data\nvolumes:\n  vaultwarden_data:\n",
        ],
        'n8n' => [
            'name' => 'n8n', 'category' => 'Automation', 'icon' => 'workflow',
            'description' => 'Workflow automation with a fair-code visual editor.',
            'port' => 5678,
            'compose' => "services:\n  n8n:\n    image: docker.n8n.io/n8nio/n8n:latest\n    container_name: n8n\n    restart: unless-stopped\n    ports:\n      - \"5678:5678\"\n    environment:\n      N8N_SECURE_COOKIE: \"false\"\n    volumes:\n      - n8n_data:/home/node/.n8n\nvolumes:\n  n8n_data:\n",
        ],
        'pihole' => [
            'name' => 'Pi-hole', 'category' => 'Networking', 'icon' => 'shield-ban',
            'description' => 'Network-wide DNS ad blocking with a web dashboard.',
            'port' => 8089,
            'compose' => "services:\n  pihole:\n    image: pihole/pihole:latest\n    container_name: pihole\n    restart: unless-stopped\n    ports:\n      - \"53:53/tcp\"\n      - \"53:53/udp\"\n      - \"8089:80/tcp\"\n    environment:\n      TZ: Europe/London\n      WEBPASSWORD: change-me-please\n    volumes:\n      - pihole_etc:/etc/pihole\n      - pihole_dnsmasq:/etc/dnsmasq.d\nvolumes:\n  pihole_etc:\n  pihole_dnsmasq:\n",
        ],
        'grafana' => [
            'name' => 'Grafana', 'category' => 'Monitoring', 'icon' => 'chart-line',
            'description' => 'Analytics and dashboards for metrics and logs.',
            'port' => 3002,
            'compose' => "services:\n  grafana:\n    image: grafana/grafana-oss:latest\n    container_name: grafana\n    restart: unless-stopped\n    ports:\n      - \"3002:3000\"\n    volumes:\n      - grafana_data:/var/lib/grafana\nvolumes:\n  grafana_data:\n",
        ],
        'jellyfin' => [
            'name' => 'Jellyfin', 'category' => 'Media', 'icon' => 'clapperboard',
            'description' => 'Free software media system for movies, TV and music.',
            'port' => 8096,
            'compose' => "services:\n  jellyfin:\n    image: jellyfin/jellyfin:latest\n    container_name: jellyfin\n    restart: unless-stopped\n    ports:\n      - \"8096:8096\"\n    volumes:\n      - jellyfin_config:/config\n      - jellyfin_cache:/cache\n      - jellyfin_media:/media\nvolumes:\n  jellyfin_config:\n  jellyfin_cache:\n  jellyfin_media:\n",
        ],
        'code-server' => [
            'name' => 'code-server', 'category' => 'Development', 'icon' => 'code',
            'description' => 'VS Code running in the browser, backed by this server.',
            'port' => 8443,
            'compose' => "services:\n  code-server:\n    image: codercom/code-server:latest\n    container_name: code-server\n    restart: unless-stopped\n    environment:\n      PASSWORD: change-me-please\n    ports:\n      - \"8443:8080\"\n    volumes:\n      - code_server_data:/home/coder\nvolumes:\n  code_server_data:\n",
        ],
        'adminer' => [
            'name' => 'Adminer', 'category' => 'Database', 'icon' => 'database',
            'description' => 'Full-featured database management in a single file.',
            'port' => 8082,
            'compose' => "services:\n  adminer:\n    image: adminer:latest\n    container_name: adminer\n    restart: unless-stopped\n    ports:\n      - \"8082:8080\"\n",
        ],
        'redis' => [
            'name' => 'Redis', 'category' => 'Database', 'icon' => 'database-zap',
            'description' => 'In-memory data store for caching and queues.',
            'port' => 6379,
            'compose' => "services:\n  redis:\n    image: redis:7-alpine\n    container_name: redis\n    restart: unless-stopped\n    command: redis-server --appendonly yes\n    ports:\n      - \"6379:6379\"\n    volumes:\n      - redis_data:/data\nvolumes:\n  redis_data:\n",
        ],
        'postgres' => [
            'name' => 'PostgreSQL', 'category' => 'Database', 'icon' => 'database',
            'description' => 'Powerful open-source relational database.',
            'port' => 5432,
            'compose' => "services:\n  postgres:\n    image: postgres:16-alpine\n    container_name: postgres\n    restart: unless-stopped\n    environment:\n      POSTGRES_PASSWORD: change-me-please\n      POSTGRES_DB: app\n    ports:\n      - \"5432:5432\"\n    volumes:\n      - postgres_data:/var/lib/postgresql/data\nvolumes:\n  postgres_data:\n",
        ],
        'wordpress' => [
            'name' => 'WordPress', 'category' => 'CMS', 'icon' => 'newspaper',
            'description' => 'The world’s most popular CMS with a bundled database.',
            'port' => 8083,
            'compose' => "services:\n  wordpress:\n    image: wordpress:latest\n    container_name: wordpress\n    restart: unless-stopped\n    ports:\n      - \"8083:80\"\n    environment:\n      WORDPRESS_DB_HOST: db\n      WORDPRESS_DB_USER: wordpress\n      WORDPRESS_DB_PASSWORD: change-me-please\n      WORDPRESS_DB_NAME: wordpress\n    volumes:\n      - wordpress_data:/var/www/html\n    depends_on:\n      - db\n  db:\n    image: mariadb:11\n    container_name: wordpress-db\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: change-me-root\n      MYSQL_DATABASE: wordpress\n      MYSQL_USER: wordpress\n      MYSQL_PASSWORD: change-me-please\n    volumes:\n      - wordpress_db:/var/lib/mysql\nvolumes:\n  wordpress_data:\n  wordpress_db:\n",
        ],
    ];
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
            'description' => $app['description'],
            'port' => $app['port'] ?? null,
        ];
    }
    return $list;
}
