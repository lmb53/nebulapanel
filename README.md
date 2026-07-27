# Nebula Panel — PHP Web Control Panel

A pre-production, self-hosted server control panel. The repository's application source
lives in the clearly named `panel/` directory. Installation copies those files
to a random URL prefix on first install. Without a domain, the quick installer
uses the server IP over HTTP; set `LOCAL_ONLY=1` to require an SSH port-forward.

> The random directory name is not a security boundary. Domainless HTTP mode is
> intended only for initial setup; add `DOMAIN` for HTTPS, restrict `ADMIN_IP`,
> or set `LOCAL_ONLY=1`. Do not host untrusted applications until the documented
> VM integration tests have passed for your OS and service combination.

## Quick install

On a fresh **Ubuntu 22.04 or 24.04** box, one command installs and configures
everything (Nginx + PHP-FPM, the panel, sudoers rules, the privileged helper,
firewall). The first run creates a random directory at
`/var/www/html/<random-prefix>/`; later runs reuse that active installation and
retain its private runtime state:

```bash
curl -fsSL https://raw.githubusercontent.com/lmb53/nebulapanel/main/install.sh | sudo env SOURCE=remote bash
```

With a panel domain and an administrator IP allowlist:

```bash
curl -fsSL https://raw.githubusercontent.com/lmb53/nebulapanel/main/install.sh | sudo env SOURCE=remote DOMAIN=panel.example.com ADMIN_IP=203.0.113.7 bash
```

The downloaded bootstrap resolves `REPO_REF` through GitHub and installs the
archive for that exact commit. For a reproducible or audited deployment, pin
both URLs to the same reviewed commit:

```bash
REVIEWED_COMMIT=<40-character-commit-sha>
curl -fsSL "https://raw.githubusercontent.com/lmb53/nebulapanel/${REVIEWED_COMMIT}/install.sh" |
  sudo env SOURCE=remote REPO_REF="${REVIEWED_COMMIT}" bash
```

Alternatively, install from a checked-out copy:

```bash
git clone https://github.com/lmb53/nebulapanel.git
cd nebulapanel
git checkout <reviewed-tag-or-commit>
sudo DOMAIN=panel.example.com ADMIN_IP=203.0.113.7 ./install.sh
```

The installer prints the URL, filesystem path, and a single-use one-hour
bootstrap token. Use that token to create the administrator account and run the
provisioning wizard. An unset `PANEL_PREFIX` reuses an existing active install and generates a
random one only on the first run. Set `PANEL_PREFIX=random` to deliberately rotate
the URL (runtime state is migrated), or use a fixed name. Options (env vars):
`PANEL_PREFIX`, `WEBROOT`, `ADMIN_IP`, `DOMAIN`, `LOCAL_ONLY`, `FM_ROOT`, `REPO`, `REPO_REF`
(see [install.sh](install.sh)).

> The `main` one-liner is intentionally convenient and follows the current
> branch. Pin a reviewed commit when reproducibility or supply-chain assurance
> matters. Remote refs are resolved before the immutable archive is fetched.

## What works

| Feature | Status |
|---|---|
| Token-gated first-run setup (Argon2id where available) + login/logout | ✅ |
| Session idle/absolute expiry, rotation, CSRF, account/IP throttling, structured audit | ✅ |
| **Dashboard** — live charts, load/CPU, services, top processes and actionable health alerts | ✅ |
| **Services** — tabbed per-instance manager, virtual hosts, logs, start / stop / restart + boot state | ✅ sudo |
| **Install Apps** — install apache2/redis/mariadb/fail2ban/ModSecurity + extra PHP versions with live output, each shown with its official brand logo | ✅ sudo/helper |
| **Updates** — list upgradable, update one/all, with persistent streaming apt output | ✅ sudo |
| **Users & RBAC** — panel accounts with administrator/operator/developer/auditor roles, plus system-account inventory | ✅ |
| **SSH Keys** — list/add/revoke authorized keys for interactive users | ✅ helper |
| **Cron** — administrator-only CRUD on the panel account's crontab | ✅ |
| **Security** — UFW status, enable/disable, add/delete rules, plus a **Fail2Ban** tab showing every jail's counters, the live banned-IP list with one-click unban, manual ban, and recent ban activity, and a **ModSecurity** tab (OWASP Core Rule Set WAF for nginx: blocking / detection-only / off, with nginx-validated switching and recent findings) | ✅ sudo/helper |
| **Logs** — journalctl per-unit + `/var/log` file tails | ✅ |
| **Websites** — immutable IDs, confined roots, per-site Unix/FPM identity, usage, TLS, and credential-free HTTPS Git deploy; deletion archives to root-owned trash | ✅ helper |
| **Domains + DNS** — authoritative BIND zones and record CRUD for panel-managed domains | ✅ helper |
| **SSL** — list / issue / renew / delete certbot certificates + validated custom PEM upload | ✅ helper |
| **PHP** — per-version ini settings (memory_limit, upload size…) + modules | ✅ helper |
| **Databases** — website-owned MariaDB/MySQL DB/user CRUD, metadata and per-database quick access | ✅ sudo |
| **phpMyAdmin** — one-click install + password-free, short-lived signed per-database signon | ✅ helper |
| **Email** — one-click Postfix + Dovecot + OpenDKIM mail server, virtual mailboxes & aliases, per-domain **DKIM** keys, copy-ready **MX / SPF / DKIM / DMARC** records (auto-publish to panel DNS zones), a **statistics dashboard** (sent/received/bounced/rejected, per-day chart, top senders & recipients, mailbox storage) and one-click **Roundcube** webmail | ✅ helper |
| **Docker** — create/control containers with their published ports, view container logs, pull/remove/prune images, manage volumes and networks, **Compose stacks (editable docker-compose.yml, deploy/pull/restart/logs, one-click Compose install when the CLI plugin is missing)** and a one-click **App Store** of popular self-hosted apps with official brand logos | ✅ sudo |
| **File Manager** — expandable tree previews, browse/pinned/recent, archives, popup multi-tab editor, ownership, permissions and drag-drop upload | ✅ helper |
| **Diagnostics** — environment + per-privilege sudo checks with fix hints | ✅ |
| **Backups** — create / verify / list / download / delete `.tar.gz` | ✅ |
| **Terminal** — audited non-interactive command runner | ✅ |
| **System Info** — OS, kernel, CPU, RAM, disk, network | ✅ |
| **Panel Updates** — self-update from GitHub (check + apply) | ✅ |
| **Settings** — panel name, timeout, change password, audit log | ✅ |
| **Notifications** — live operational inbox, top-bar dropdown, mark-read and delete state | ✅ |
| **Bearer API** — named, role-bound, scoped, expiring, optionally IP-bound tokens under `api/v1` | ✅ |

Rows marked **sudo** are routed through the root-owned validating helper; no
tool receives a direct wildcard sudo grant. The panel is modular: each feature is `lib/mod_<x>.php` +
`views/<x>.php` (+ `api/<x>.php`), registered in `lib/modules.php`.

Metrics read Linux `/proc` and use `systemctl`, so **run this on the Linux VPS**.
On macOS/Windows the pages load but most metrics show `n/a`.

The initial versioned API contract is in [`docs/openapi.yaml`](docs/openapi.yaml).

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
git fetch --tags
git checkout <reviewed-tag-or-commit>
sudo PANEL_PREFIX=5cc813be4cbdf4b3c35be176 ./install.sh
```

Use the prefix from your current `/var/www/html/<prefix>/` path. Omitting it
intentionally creates a separate random installation.

Re-running also repairs the panel's Nginx block as the explicit
`default_server`. This matters after adding a hosted website: requests made by
bare server IP continue to reach the panel instead of whichever domain vhost
Nginx loaded first.

## Install

Use `install.sh`; a manual copy misses the dedicated panel/webapp/site FPM
pools, root-owned site registry, broker policy, bootstrap token, and Nginx
confinement. Review the script and pin the source commit before running it.

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
    fastcgi_pass unix:/run/php/nebula-panel.sock;
}
```

### Apache

`mod_php` or PHP-FPM + the bundled `.htaccess` files deny direct access to
`api/`, `data/`, `bin/`, `lib/`, `views/`, and `config.php`. Ensure
`AllowOverride All` for the directory.

## Privileges (sudoers)

Several modules drive tools that need root. The installer writes one rule to
`/etc/sudoers.d/nebula-panel`:

```
nebula-panel ALL=(root) NOPASSWD: /usr/local/bin/nebula-helper *
```

The root-owned helper accepts fixed actions, validates resource IDs and
arguments again at the privilege boundary, and confines site paths with
root-owned filesystem identity state. Docker remains root-equivalent, so
Docker, package, database, site, file, cron, firewall, terminal, and related
operations are administrator-only.

Modules whose tool/sudo rule is missing degrade gracefully: read-only status
still shows and actions return a clear permission error in the UI.

### The privileged helper (`nebula-helper`)

Website provisioning, SSL, and phpMyAdmin install need to write nginx configs,
create docroots, and run certbot — operations that would otherwise require a
broad `tee`/`ln`/`mkdir`/`certbot` sudo grant. Instead the installer deploys a
single **root-owned** script to `/usr/local/bin/nebula-helper` with one tight
sudoers rule:

```
nebula-panel ALL=(root) NOPASSWD: /usr/local/bin/nebula-helper *
```

The helper accepts only a fixed set of validated subcommands covering site,
certificate, DNS, PHP, File Manager, phpMyAdmin, and panel-update operations and
re-validates every argument itself. It lives **outside** the web-writable tree
and is root-owned, so web identities cannot alter it. A validated self-update
does refresh the staged helper; re-run `install.sh` when installer-owned FPM,
Nginx, account, or sudoers configuration changes.

## First run

1. Visit the HTTPS URL printed by the installer, or use its loopback URL through
   an SSH port-forward.
2. Enter the single-use bootstrap token and create the admin username + password.
3. Then the **provisioning wizard** opens: pick the services to install (MariaDB,
   a PHP version, phpMyAdmin, Redis, Fail2Ban, Certbot, Docker, …). It installs
   each in order with live progress, via apt + the privileged helper. You can
   skip and add more later from **Install Apps**.
4. You're in. Credentials and RBAC state are hashed into `data/panel-users.json`.

For local account recovery, run `sudo nebula-recovery reset-admin`. It updates
the administrator with Argon2id and revokes existing sessions without exposing
setup publicly. `sudo nebula-recovery issue-bootstrap` rotates the one-hour
single-use setup token when initialization has not yet completed.

## Email

The **Email** page (Hosting section) runs a complete self-hosted mail server
with as little configuration as possible:

1. **Install & configure mail server** — one click installs and wires up
   **Postfix** (SMTP + TLS-required submission on 587), **Dovecot** (implicit
   TLS IMAP 993 / POP3S 995 with SASL), and **OpenDKIM** (signing milter). Mailboxes
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
   pre-configured against this server's local IMAP/SMTP with zero-config SQLite
   storage. Users sign in with their full email address and mailbox password.
5. **Stats** aggregates the Postfix/Dovecot logs and the maildirs on disk:
   messages sent, received, bounced, deferred and rejected, mailbox logins and
   failed logins, current queue depth, a per-day delivery chart, top senders and
   recipients, per-mailbox storage, and the most recent delivery problems with
   the SMTP reason behind each one.

The page is tabbed (Overview · Stats · Mailboxes · Aliases · DNS & DKIM ·
Webmail). The
**Reconfigure / repair** button on the Overview tab re-applies the
Postfix/Dovecot/OpenDKIM configuration — use it if mail clients or webmail can't
log in after an upgrade.

> Deliverable mail also needs correct **reverse DNS (PTR)** for the server IP and
> an unblocked outbound port 25 — both are set at your hosting provider, not in
> the panel. The mail stack runs entirely through `nebula-helper`, so re-run
> `install.sh` first if the helper is missing.

## Hardening (do this before exposing it)

- **HTTPS** — required away from loopback. Monitor certificate renewal.
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
dedicated `nebula-panel` process. Notes:
- `config.php` is intentionally **not** overwritten, so new config keys from an
  update won't appear automatically — diff it against the repo after a major
  update. Runtime prefs (panel name, timeout) live in `data/settings.json` and
  are unaffected.
- Roll back by extracting the pre-update snapshot from `data/backups/`.
- Pin `repo_ref` to a tag/commit for reproducible, reviewed updates.
- Existing installations must re-run `install.sh` once to install the hardened
  update helper and `/etc/nebula-panel/panel-root` confinement file.

## Still to build (natural next steps)

- **Websites** — staged releases, rollback, wildcard certificates, Apache mode
- **Recovery** — complete encrypted off-host restore workflow and tested drills
- **Authentication** — passkeys/TOTP, recovery codes, and step-up checks
- **Live PTY terminal** — real interactive shell (needs a WebSocket sidecar)
- **Two-factor authentication** for panel users
```
