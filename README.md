# Nebula Panel — PHP Web Control Panel

A working, self-hosted server control panel. The repository's application source
lives in the clearly named `panel/` directory. Installation copies those files
to a **random public URL prefix** on first install, e.g. `http://YOUR_IP/a1b2c3d4e5f6/`.

> The random directory name is *obscurity*, not real security. Always pair it with
> HTTPS, a strong admin password, and ideally IP allow-listing. See "Hardening".

## Quick install

On a fresh **Ubuntu 22.04 or 24.04** box, one command installs and configures
everything (Nginx + PHP-FPM, the panel, sudoers rules, the privileged helper,
firewall). The first run creates a random directory at
`/var/www/html/<random-prefix>/`; later runs reuse that active installation and
retain its private runtime state:

```bash
curl -fsSL https://raw.githubusercontent.com/lmb53/nebulapanel/main/install.sh | sudo bash
```

Hardened variant — random URL prefix, locked to your IP, with HTTPS:

```bash
curl -fsSL https://raw.githubusercontent.com/lmb53/nebulapanel/main/install.sh \
  | sudo PANEL_PREFIX=random ADMIN_IP=203.0.113.7 DOMAIN=panel.example.com bash
```

The installer prints both the random public URL and its filesystem path when it
finishes — open the URL to create the admin account and run the provisioning
wizard. An unset `PANEL_PREFIX` reuses an existing active install and generates a
random one only on the first run. Set `PANEL_PREFIX=random` to deliberately rotate
the URL (runtime state is migrated), or use a fixed name. Options (env vars):
`PANEL_PREFIX`, `WEBROOT`, `ADMIN_IP`, `DOMAIN`, `FM_ROOT`, `REPO`, `REPO_REF`
(see [install.sh](install.sh)).

> ⚠️ `curl … | sudo bash` runs remote code as root. It's your repo, but pin
> `REPO_REF` to a tag/commit for reproducible, reviewed installs.

## What works

| Feature | Status |
|---|---|
| First-run admin setup (bcrypt) + login/logout | ✅ |
| Session auth, idle timeout, POST-only logout, CSRF, login throttling, audit log | ✅ |
| **Dashboard** — live charts, load/CPU, services, top processes and actionable health alerts | ✅ |
| **Services** — tabbed per-instance manager, virtual hosts, logs, start / stop / restart + boot state | ✅ sudo |
| **Install Apps** — install apache2/redis/mariadb/fail2ban + extra PHP versions with live output | ✅ sudo/helper |
| **Updates** — list upgradable, update one/all, with persistent streaming apt output | ✅ sudo |
| **Users & RBAC** — panel accounts with administrator/operator/developer/auditor roles, plus system-account inventory | ✅ |
| **SSH Keys** — list/add/revoke authorized keys for interactive users | ✅ helper |
| **Cron** — full CRUD on the web user's crontab | ✅ |
| **Firewall** — UFW status, enable/disable, add/delete rules | ✅ sudo |
| **Logs** — journalctl per-unit + `/var/log` file tails | ✅ |
| **Websites** — create/delete Nginx vhosts, PHP version, service health, docroot disk/file usage, Let's Encrypt, **Git deploy (connect a repo & pull into the docroot)** | ✅ helper |
| **Domains + DNS** — authoritative BIND zones and record CRUD for panel-managed domains | ✅ helper |
| **SSL** — list / issue / renew / delete certbot certificates + validated custom PEM upload | ✅ helper |
| **PHP** — per-version ini settings (memory_limit, upload size…) + modules | ✅ helper |
| **Databases** — website-owned MariaDB/MySQL DB/user CRUD, metadata and per-database quick access | ✅ sudo |
| **phpMyAdmin** — one-click install + password-free, short-lived signed per-database signon | ✅ helper |
| **Email** — one-click Postfix + Dovecot + OpenDKIM mail server, virtual mailboxes & aliases, per-domain **DKIM** keys, copy-ready **MX / SPF / DKIM / DMARC** records (auto-publish to panel DNS zones), and one-click **Roundcube** webmail | ✅ helper |
| **Docker** — create/control containers, view container logs, pull/remove/prune images, manage volumes and networks, **Compose stacks (editable docker-compose.yml, deploy/pull/restart/logs)** and a one-click **App Store** of popular self-hosted apps | ✅ sudo |
| **File Manager** — expandable tree previews, browse/pinned/recent, archives, popup multi-tab editor, ownership, permissions and drag-drop upload | ✅ helper |
| **Diagnostics** — environment + per-privilege sudo checks with fix hints | ✅ |
| **Backups** — create / verify / list / download / delete `.tar.gz` | ✅ |
| **Terminal** — audited non-interactive command runner | ✅ |
| **System Info** — OS, kernel, CPU, RAM, disk, network | ✅ |
| **Panel Updates** — self-update from GitHub (check + apply) | ✅ |
| **Settings** — panel name, timeout, change password, audit log | ✅ |
| **Notifications** — live operational inbox, top-bar dropdown, mark-read and delete state | ✅ |

Rows marked **sudo** require the passwordless sudoers rules the installer sets up
(see below). The panel is modular: each feature is `lib/mod_<x>.php` +
`views/<x>.php` (+ `api/<x>.php`), registered in `lib/modules.php`.

Metrics read Linux `/proc` and use `systemctl`, so **run this on the Linux VPS**.
On macOS/Windows the pages load but most metrics show `n/a`.

## Requirements

- Linux VPS (Ubuntu/Debian assumed)
- PHP 8.0+ with FPM, and `proc_open` enabled (default)
- Nginx or Apache

## Screenshot
![Dashboard UI Dark](https://i.imgur.com/Th0TvGm.png)

![Dashboard UI Light](https://i.imgur.com/bOQe2Qg.png)

## Development checks

Run the cross-platform smoke test and syntax checks before deploying:

```bash
php tests/smoke.php
find panel -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n install.sh panel/bin/nebula-helper
```

## Repairing an existing installation

Re-running the corrected installer with the same prefix repairs the helper and
sudoers rules without replacing `data/` (accounts, settings, backups, and audit
history are preserved). For example:

```bash
curl -fsSL https://raw.githubusercontent.com/lmb53/nebulapanel/main/install.sh \
  | sudo PANEL_PREFIX=5cc813be4cbdf4b3c35be176 bash
```

Use the prefix from your current `/var/www/html/<prefix>/` path. Omitting it
intentionally creates a separate random installation.

Re-running also repairs the panel's Nginx block as the explicit
`default_server`. This matters after adding a hosted website: requests made by
bare server IP continue to reach the panel instead of whichever domain vhost
Nginx loaded first.

## Install

```bash
# 1. Copy the panel source to a random directory in your web root.
PANEL_PREFIX="$(od -An -N12 -tx1 /dev/urandom | tr -d '[:space:]')"
sudo cp -r panel "/var/www/html/$PANEL_PREFIX"

# 2. Make data/ writable by the web user (setup + audit log live here).
sudo chown -R www-data:www-data "/var/www/html/$PANEL_PREFIX/data"
sudo chmod 700 "/var/www/html/$PANEL_PREFIX/data"

# 3. Choose which directory the File Manager may browse (default /var/www).
#    Either edit config.php ('fm_root') or set an env var in your FPM pool.
# 4. Set NEBULA_NS1 and NEBULA_NS2 in the FPM environment when the server's
#    hostname is not already the desired authoritative nameserver base.
```

### Nginx

```nginx
# Inside your server { } block. No rewrites needed — routing is query-param based.
location /RANDOM_PREFIX/ {
    index index.php;
    try_files $uri $uri/ /RANDOM_PREFIX/index.php$is_args$args;
}
location ~ ^/RANDOM_PREFIX/(api|data|lib|views|bin)/ { deny all; return 404; }
location = /RANDOM_PREFIX/config.php { deny all; return 404; }
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```

### Apache

`mod_php` or PHP-FPM + the bundled `.htaccess` files deny direct access to
`api/`, `data/`, `bin/`, `lib/`, `views/`, and `config.php`. Ensure
`AllowOverride All` for the directory.

## Privileges (sudoers)

Several modules drive tools that need root. **The installer writes these rules
for you** to `/etc/sudoers.d/nebula-panel` (only for binaries that exist):

```
www-data ALL=(root) NOPASSWD: /usr/sbin/ufw *
www-data ALL=(root) NOPASSWD: /usr/bin/docker *
www-data ALL=(root) NOPASSWD: /usr/bin/mysql *
www-data ALL=(root) NOPASSWD: /usr/bin/journalctl *
www-data ALL=(root) NOPASSWD:SETENV: /usr/bin/apt-get *
www-data ALL=(root) NOPASSWD: /usr/local/bin/nebula-helper *
```

> ⚠️ **This is broad.** `docker`, `mysql`, and `apt-get` as root are each
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

The helper accepts only a fixed set of validated subcommands covering site,
certificate, DNS, PHP, File Manager, phpMyAdmin, and panel-update operations and
re-validates every argument itself. It lives **outside** the web-writable tree
and is root-owned, so the web user can't alter what runs as root. Self-update
does **not** touch it — re-run `install.sh` to update the helper.

## First run

1. Visit the random URL printed by the installer.
2. You'll be redirected to **Setup** — create the admin username + password.
3. Then the **provisioning wizard** opens: pick the services to install (MariaDB,
   a PHP version, phpMyAdmin, Redis, Fail2Ban, Certbot, Docker, …). It installs
   each in order with live progress, via apt + the privileged helper. You can
   skip and add more later from **Install Apps**.
4. You're in. Credentials and RBAC state are hashed into `data/panel-users.json`.

To reset a locked-out installation, stop the web service, back up and remove both
`data/panel-users.json` and the legacy `data/admin.json`, then reload Setup. Do
not remove either file while the panel is publicly reachable.

## Email

The **Email** page (Hosting section) runs a complete self-hosted mail server
with as little configuration as possible:

1. **Install & configure mail server** — one click installs and wires up
   **Postfix** (SMTP + submission on 587), **Dovecot** (IMAP 143 / POP3 110 with
   SASL for authenticated sending), and **OpenDKIM** (signing milter). Mailboxes
   are *virtual* and file-backed — there is no SQL to configure. When UFW is
   active the standard mail ports are opened automatically.
2. **Add a mail domain**, then create **mailboxes** (full-address + password) and
   **aliases / forwarders** on it. Every change regenerates the Postfix and
   Dovecot maps atomically through the privileged helper, so the panel and the
   running server never drift. Passwords are stored only as SHA-512 crypt hashes.
3. **DKIM** keys are generated per domain automatically. The page shows the
   ready-to-paste **MX, SPF, DKIM, and DMARC** DNS records; for domains that are
   panel-managed authoritative zones you can **publish them to DNS in one click**
   (long DKIM keys are split into valid 255-byte TXT chunks). For externally
   hosted DNS, copy each record with the copy button.
4. **Roundcube webmail** installs to its own random URL with one click,
   pre-configured against this server's IMAP/SMTP (SQLite storage). Users sign in
   with their full email address and mailbox password.

> Deliverable mail also needs correct **reverse DNS (PTR)** for the server IP and
> an unblocked outbound port 25 — both are set at your hosting provider, not in
> the panel. The mail stack runs entirely through `nebula-helper`, so re-run
> `install.sh` first if the helper is missing.

## Hardening (do this before exposing it)

- **HTTPS** — put it behind Let's Encrypt / a reverse proxy. Sessions set the
  `Secure` cookie flag automatically over HTTPS.
- **Change the secret prefix** — rename the directory to your own random string.
- **IP allow-list** the location block to your admin IPs.
- **Rate-limit** `/<random-prefix>/?r=login` (nginx `limit_req` or fail2ban).
- If TLS terminates at a reverse proxy, add only that proxy's IP to
  `trusted_proxies` in `config.php`; forwarded headers are ignored otherwise.
- Keep `'debug' => false` in `config.php`.
## Architecture

```
panel/             source directory; installed under a random public name
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
  data/             panel-users.json, settings.json, audit.log, backups/ (web-denied)
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
2. On **Update now**: resolves the configured ref to an immutable commit,
   downloads that commit, validates archive paths and PHP syntax, then asks the
   root-owned helper to take a required snapshot and deploy it while preserving
   `data/` and `config.php`.

The installer keeps application code root-owned and only `data/` writable by the
web process. Notes:
- `config.php` is intentionally **not** overwritten, so new config keys from an
  update won't appear automatically — diff it against the repo after a major
  update. Runtime prefs (panel name, timeout) live in `data/settings.json` and
  are unaffected.
- Roll back by extracting the pre-update snapshot from `data/backups/`.
- Pin `repo_ref` to a tag/commit for reproducible, reviewed updates.
- Existing installations must re-run `install.sh` once to install the hardened
  update helper and `/etc/nebula-panel/panel-root` confinement file.

## Still to build (natural next steps)

- **Email hosting** — an SMTP/IMAP stack and reputation tooling remain separate
- **PHP** — install additional versions (ondrej PPA), extensions, per-site `php.ini`
- **Websites** — per-site logs viewer, clone/staging, wildcard certs, Apache mode
- **Live PTY terminal** — real interactive shell (needs a WebSocket sidecar)
- **Two-factor authentication** for panel users
```
