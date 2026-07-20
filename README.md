# Nebula Panel — PHP MVP

A working, self-hosted server control panel served from an **obscured URL prefix**
(the secret directory name), e.g. `http://YOUR_IP/2v9xzq4k2/`.

> The random directory name is *obscurity*, not real security. Always pair it with
> HTTPS, a strong admin password, and ideally IP allow-listing. See "Hardening".

## What works

| Feature | Status |
|---|---|
| First-run admin setup (bcrypt) + login/logout | ✅ |
| Session auth, idle timeout, CSRF on all writes, audit log | ✅ |
| **Dashboard** — live CPU / memory / disk / load (3s poll) + chart | ✅ |
| **Monitoring** — live charts + top processes (`ps`) | ✅ |
| **Services** — start / stop / restart via `systemctl` | ✅ sudo |
| **Install Apps** — install apache2/redis/mariadb/fail2ban + extra PHP versions | ✅ sudo/helper |
| **Updates** — list upgradable + `apt-get update`/`upgrade` | ✅ sudo |
| **Users** — system accounts from `/etc/passwd` (read-only) | ✅ |
| **Cron** — full CRUD on the web user's crontab | ✅ |
| **Firewall** — UFW status, enable/disable, add/delete rules | ✅ sudo |
| **Logs** — journalctl per-unit + `/var/log` file tails | ✅ |
| **Websites** — create/delete Nginx vhosts, docroot, PHP ver, Let's Encrypt | ✅ helper |
| **Databases** — MariaDB/MySQL: DBs + users CRUD (`sudo mysql`) | ✅ sudo |
| **phpMyAdmin** — one-click install + launch (auto blowfish config) | ✅ helper |
| **Docker** — containers (start/stop/restart/rm) + images | ✅ sudo |
| **File Manager** — browse / edit / upload / mkdir / rename / chmod / copy / move / delete | ✅ |
| **Backups** — create / list / download / delete `.tar.gz` | ✅ |
| **Terminal** — audited non-interactive command runner | ✅ |
| **System Info** — OS, kernel, CPU, RAM, disk, network | ✅ |
| **Panel Updates** — self-update from GitHub (check + apply) | ✅ |
| **Settings** — panel name, timeout, change password, audit log | ✅ |

Rows marked **sudo** require the passwordless sudoers rules the installer sets up
(see below). The panel is modular: each feature is `lib/mod_<x>.php` +
`views/<x>.php` (+ `api/<x>.php`), registered in `lib/modules.php`.

Metrics read Linux `/proc` and use `systemctl`, so **run this on the Linux VPS**.
On macOS/Windows the pages load but most metrics show `n/a`.

## Requirements

- Linux VPS (Ubuntu/Debian assumed)
- PHP 8.0+ with FPM, and `proc_open` enabled (default)
- Nginx or Apache

## Install

```bash
# 1. Copy the secret directory into your web root.
sudo cp -r 2v9xzq4k2 /var/www/html/

# 2. Make data/ writable by the web user (setup + audit log live here).
sudo chown -R www-data:www-data /var/www/html/2v9xzq4k2/data
sudo chmod 700 /var/www/html/2v9xzq4k2/data

# 3. Choose which directory the File Manager may browse (default /var/www).
#    Either edit config.php ('fm_root') or set an env var in your FPM pool.
```

### Nginx

```nginx
# Inside your server { } block. No rewrites needed — routing is query-param based.
location /2v9xzq4k2/ {
    index index.php;
    try_files $uri $uri/ /2v9xzq4k2/index.php$is_args$args;
}
location ~ ^/2v9xzq4k2/(data|lib|views)/ { deny all; return 404; }
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```

### Apache

`mod_php` or PHP-FPM + the bundled `.htaccess` files already deny `data/`, `lib/`,
`views/`. Ensure `AllowOverride All` for the directory.

## Privileges (sudoers)

Several modules drive tools that need root. **The installer writes these rules
for you** to `/etc/sudoers.d/nebula-panel` (only for binaries that exist):

```
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start *, /usr/bin/systemctl stop *, /usr/bin/systemctl restart *
www-data ALL=(root) NOPASSWD: /usr/sbin/ufw *
www-data ALL=(root) NOPASSWD: /usr/bin/docker *
www-data ALL=(root) NOPASSWD: /usr/bin/mysql *
www-data ALL=(root) NOPASSWD: /usr/bin/journalctl *
www-data ALL=(root) NOPASSWD: /usr/bin/tar *
www-data ALL=(root) NOPASSWD:SETENV: /usr/bin/apt-get *
```

> ⚠️ **This is broad.** `tar`, `docker`, `mysql`, and `apt-get` as root are each
> effectively a path to full root. That is inherent to a control panel — the
> mitigation is *access control*, not command scoping: keep the panel behind the
> obscured prefix **+ HTTPS + an IP allow-list**, and treat panel access as root
> access. If you don't need a module, delete its `sudo_line` from `install.sh`
> (or the rule from the sudoers file) to shrink the surface.

Modules whose tool/sudo rule is missing degrade gracefully: read-only status
still shows and actions return a clear permission error in the UI.

### The privileged helper (`nebula-helper`)

Website provisioning, SSL, and phpMyAdmin install need to write nginx configs,
create docroots, and run certbot — operations that would otherwise require a
broad `tee`/`ln`/`mkdir`/`certbot` sudo grant. Instead the installer deploys a
single **root-owned** script to `/usr/local/bin/nebula-helper` with one tight
sudoers rule:

```
www-data ALL=(root) NOPASSWD: /usr/local/bin/nebula-helper *
```

The helper accepts only a fixed set of validated subcommands (`site-create`,
`site-delete`, `site-ssl`, `php-versions`, `pma-install`, `pma-remove`) and
re-validates every argument itself. It lives **outside** the web-writable tree
and is root-owned, so the web user can't alter what runs as root. Self-update
does **not** touch it — re-run `install.sh` to update the helper.

## First run

1. Visit `http://YOUR_IP/2v9xzq4k2/`
2. You'll be redirected to **Setup** — create the admin username + password.
3. You're in. Credentials are hashed into `data/admin.json`.

To reset the admin account, delete `data/admin.json` and reload.

## Hardening (do this before exposing it)

- **HTTPS** — put it behind Let's Encrypt / a reverse proxy. Sessions set the
  `Secure` cookie flag automatically over HTTPS.
- **Change the secret prefix** — rename the directory to your own random string.
- **IP allow-list** the location block to your admin IPs.
- **Rate-limit** `/2v9xzq4k2/?r=login` (nginx `limit_req` or fail2ban).
- Keep `'debug' => false` in `config.php`.

## Architecture

```
2v9xzq4k2/
  index.php         front controller — ?r=<route>: public / api/<x> / page views
  config.php        panel name, fm_root, service whitelist, timeouts
  lib/
    bootstrap.php   sessions, config (+ data/settings.json overrides), includes
    helpers.php     url()/asset()/e()/json_out()/csrf/render()/audit()/read_json_body()
    auth.php        setup, login, logout, guards
    sys.php         /proc metrics, run_cmd(), sudo_cmd(), has_cmd()
    modules.php     nav + route registry (single source of truth)
    files.php       path-safe file manager backend
    mod_*.php       one backend per feature (cron, firewall, db, docker, …)
  api/
    <x>.php         one JSON endpoint per feature (drop-in: ?r=api/<x>)
  views/
    layout.php      shell; <x>.php self-loads its data and renders
  assets/           style.css, app.js (exposes window.Nebula for view scripts)
  data/             admin.json, settings.json, audit.log, backups/ (web-denied)
```

**Adding a module** = drop `lib/mod_x.php` + `views/x.php` (+ `api/x.php`) and add
one row to `lib/modules.php`. No edits to the router or nav needed.

Routing is query-param based (`?r=...`) so it works identically under Nginx,
Apache, and `php -S` with **zero rewrite rules**. Page views self-load their
data; API endpoints are self-contained files that emit JSON via `json_out()`.

## Self-update (Panel Updates page)

The **Panel Updates** page checks the configured GitHub repo (`config.php` →
`repo` / `repo_ref`, default `lmb53/nebulapanel@main`) and can apply updates
in place:

1. Compares the deployed commit SHA (recorded in `data/version.json` at install)
   against the latest commit via the GitHub API.
2. On **Update now**: downloads the tarball, **snapshots the current install**
   to `data/backups/pre-update-<ts>.tar.gz`, then `rsync`s the new files over
   the panel — **preserving `data/` and `config.php`** so your settings survive.

Because the web user owns the panel files (the installer sets this), no sudo is
needed to self-update. Notes:
- `config.php` is intentionally **not** overwritten, so new config keys from an
  update won't appear automatically — diff it against the repo after a major
  update. Runtime prefs (panel name, timeout) live in `data/settings.json` and
  are unaffected.
- Roll back by extracting the pre-update snapshot from `data/backups/`.
- Pin `repo_ref` to a tag/commit for reproducible, reviewed updates.

## Still to build (natural next steps)

- **DNS & Email** — the remaining mockup pages
- **PHP** — install additional versions (ondrej PPA), extensions, per-site `php.ini`
- **Websites** — per-site logs viewer, clone/staging, wildcard certs, Apache mode
- **Live PTY terminal** — real interactive shell (needs a WebSocket sidecar)
- **Multi-user + roles, 2FA**
```
