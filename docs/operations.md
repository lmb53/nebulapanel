# Operations baseline

## Supported test matrix

Target Ubuntu 22.04 and 24.04 on x86-64 with Nginx and the distribution PHP-FPM.
Treat other distributions, architectures, reverse proxies, and service versions
as unsupported until validated in disposable infrastructure.

## Local recovery

Run `sudo nebula-recovery reset-admin` from the server console to rotate the
administrator password, turn off their two-factor sign-in, and revoke
sessions and that account's API tokens (plus any token without an owner).
Before first setup only,
`sudo nebula-recovery issue-bootstrap` issues a new one-hour token. Neither
workflow requires making setup publicly reachable.

File-manager deletion moves entries to root-owned
`/var/lib/nebula-panel/file-trash`; only a server administrator can inspect or
restore them. Deleting, renaming or moving a symlink acts on the link itself,
never on the file or folder it points to. Website deletion first takes a final
backup (files and linked databases); if that fails nothing is deleted. The site
tree then moves to `/srv/nebula/trash`, listed under Websites → Archived, where
it can be purged.

## Limits and housekeeping

- File Manager uploads are sent in 8 MB chunks and can be resumed, so a file
  may be up to 1 GB (`max_upload_bytes`) while each request stays under the
  installer's 64 MB Nginx `client_max_body_size` and PHP-FPM
  `upload_max_filesize`/`post_max_size`. Re-run the installer on installations
  created before those settings existed, or chunks fail with HTTP 413.
  Database imports use the same chunked upload.
- The inline editor opens UTF-8 text files up to 500 KB. Files in another
  encoding are offered for download instead, because a browser round-trip
  would rewrite them.
- Folders with more than 2,000 entries list the first 2,000 (folders first).
- `data/audit.log` rotates to `data/audit.log.1` at 20 MB; every event is also
  sent to syslog (`LOG_AUTHPRIV`) for long-term retention.
- Enabling UFW from the panel first allows the SSH port(s) from
  `sshd_config` and the port the panel is being used on, so switching the
  firewall on cannot lock the operator out.

Set `PUBLIC_IP` when running the installer if mail/DNS guidance should publish
an address. Nebula deliberately does not infer a public address from
`hostname -I`.

## Ports

- Panel: 443 when `DOMAIN` is set; loopback port 80 only otherwise.
- Hosted sites: 80/443.
- Authoritative DNS: 53 TCP/UDP when selected.
- Mail: 25, 587 (STARTTLS required for authentication), 465 (implicit TLS),
  993, and 995. Plaintext 110/143 are not opened.
- SFTP for websites uses the server's SSH port (22 unless `sshd_config` says
  otherwise).
- Bundled web apps (phpMyAdmin, Roundcube webmail): 2096, opened when one is
  installed and closed when the last is removed. They are served on this
  separate origin, never on the panel's host and port.
- Compose application ports must bind to loopback. Pi-hole is omitted because it
  conflicts with the panel-managed BIND service. Website users (group
  `nebula-sites`) are refused the loopback ports of App Store containers and
  the Apache backend by the `NEBULA-LOOPBACK` firewall chain, which the
  scheduler re-applies every run; MariaDB, Redis and mail stay reachable.

### Reaching a Compose app

App Store stacks publish on `127.0.0.1` only, so `http://<server-ip>:<port>`
never responds — nothing listens on the public interface, and opening the
firewall does not change that. Reach them through a proxy vhost instead.

**Automatic.** Set a base domain in Docker → Stacks → *Auto-publish under*
(for example `apps.example.com`) and point a wildcard `*.apps.example.com`
at this server. Every stack deployed afterwards is published on deploy:

- The stack's lowest HTTP port gets `<stack>.<base>`; further HTTP ports get
  `<stack>-<port>.<base>`. Underscores in a stack name become dashes.
- Known non-HTTP ports (SSH, SMTP, MySQL, PostgreSQL, Redis, Mongo …) are
  skipped — an HTTP vhost in front of them would not work. Gitea therefore
  publishes 3000 and leaves 2222 alone.
- Proxies are reconciled on every deploy: a port removed from the compose
  file takes its vhost with it, and removing the stack removes all of them.
- A proxy that cannot be created never fails the deploy; the reason is
  printed in the install output.

**Manual.** Leave the base domain empty (or publish an extra name) with
Docker → Stacks → **Publish on a hostname**, choosing any container port.
Manual publishing is not restricted to HTTP ports.

Either way the vhost includes the websocket upgrade map that Uptime Kuma,
code-server and n8n need. Add HTTPS from Websites → SSL for the hostname.

## Websites: schedules, SFTP and copies

- **Scheduled tasks** on a website's page run from that site's own crontab, as
  the site's Unix user (`nebula-s-<id>`), never as root. Entries are validated
  in the panel and again by the helper.
- **SFTP** turns on an SFTP-only account for the site user: `internal-sftp`,
  chrooted to the site folder (`/srv/nebula/sites/<id>`), starting in
  `/public`, no shell or forwarding. OpenSSH requires a root-owned chroot, so
  the site folder is owned by `root:<site user>` (0750) while SFTP is on and
  handed back when it is turned off. The rules live in
  `/etc/ssh/sshd_config.d/nebula-sftp.conf` (`Match Group nebula-sftp`).
  Allow the SSH port in the firewall for remote clients.
- **Clone** copies a site to a new domain: same PHP version and limits, the
  files (read as the source site's user and written as the new site's, within
  one filesystem; symlinks stay links, setuid bits stripped), and optionally
  copies of its linked databases (`<name>_copy`, linked to the new site). The
  copy's own configuration (for example `wp-config.php`) still names the
  original databases until it is edited.
- **Deploy on push** webhooks use a URL without a secret plus a shared secret
  the Git host proves with each delivery: an HMAC-SHA256 signature of the body
  (GitHub, Gitea, Forgejo, Gogs, Bitbucket) or GitLab's secret-token header.
  Webhooks created by older releases (secret in the URL) keep working until
  they are replaced from the website's Git panel.

## Website isolation

Every website is its own Unix user and PHP-FPM pool. In addition:

- Nginx serves a symlink only when the link and its target have the same owner
  (`disable_symlinks if_not_owner`), so one site cannot publish another site's
  files, or system files, through a link.
- PHP runs with `open_basedir` limited to the site folder, a private temp
  folder (`<site>/tmp`, also `sys_temp_dir`), and `putenv`, `pcntl_exec` and
  the process functions disabled (`putenv` plus `mail()` would otherwise let a
  site load native code and leave the sandbox).
- Vhost files are readable by root only and pool files by root and the panel,
  so sites cannot read each other's IDs or paths.
- Root never creates, changes ownership of, or ACL-walks a path inside a site
  folder: those steps run as the site's own user, or refuse symlinks.

`sudo nebula-helper security-migrate` applies this baseline to sites created
by older releases (idempotent; rolled back if Nginx or PHP-FPM rejects it).
`install.sh` runs it, and the scheduler runs it once after each new helper
arrives through a self-update. A hosted application that writes to `/tmp`
directly, rather than to `sys_get_temp_dir()`, must be pointed at its site's
`tmp` folder.

## Bundled web apps

phpMyAdmin and Roundcube are copies of their Debian/Ubuntu packages placed
next to the panel, with root-owned code (only their temp folders belong to
`nebula-webapps`). An apt hook (`/etc/apt/apt.conf.d/80nebula-webapps`)
rebuilds a copy from its package after every package run, including unattended
upgrades, keeping its configuration; `sudo nebula-helper webapps-refresh
--force` does it by hand. They are served by their own Nginx server
(`/etc/nginx/sites-available/nebula-webapps`) on port 2096, so script running
in either can never read panel pages or act with the panel session. An
`ADMIN_IP` allowlist on the panel also guards phpMyAdmin. Opening phpMyAdmin
from the panel hands it short-lived, per-database credentials through a
single-use ticket (`/var/lib/nebula-pma-tickets`) that its sign-on script
consumes.

## Data

Panel state lives in the installed panel's `data/` directory and is private to
`nebula-panel`. Sites live below `/srv/nebula/sites`; archived deletion targets
live below `/srv/nebula/trash`; root-owned resource state lives below
`/etc/nebula-panel`. Mail state spans `/etc/nebula-mail` and
`/var/mail/nebula`. Certificates are managed under `/etc/letsencrypt`.

## Upgrade and rollback

Pin a reviewed release commit. The panel resolves the commit; the root helper
then fetches exactly that commit itself, from the repository named in the
root-owned `/etc/nebula-panel/update-repo` (written by `install.sh`), so
nothing the panel stages or configures decides what root installs. It rejects
unsafe archive layouts/types, lints every PHP file and the new helper, takes a
required snapshot, and serializes updates with a host lock. It retains five
pre-update snapshots in root-only `/var/lib/nebula-panel-snapshots`, enters
maintenance mode during the switch, runs a post-copy health check, and
automatically restores the previous code on failure.

To require signed releases, list the trusted SSH signing keys in
`/etc/nebula-panel/update-signers` (Git's allowed-signers format, e.g.
`release@example.com ssh-ed25519 AAAA…`). The helper then refuses any commit
whose signature (`git verify-commit`) does not match one of them. If automatic rollback
itself fails, stop Nginx/PHP-FPM, restore the latest snapshot as root, re-run
`install.sh` for installer-owned configuration, validate Nginx and all FPM
pools, then restore service.

## Backup and recovery

Backups archive each website's `public/` directory plus a consistent
`mysqldump --single-transaction` of every database linked to it, with a
SHA-256 manifest, under the panel's private `data/backups/`. The schedule
(nightly or weekly) and keep-the-last-N retention run from
`nebula-panel-scheduler.timer`; manual and pre-restore archives are never
pruned. Restores verify the checksum, take a safety copy first, extract as the
site's Unix user through a staging directory, and drop and re-import each
selected database.

Local archives live on the same disk as the sites, so on their own they protect
against bad updates and deleted content, not against losing the server.

**Server configuration backups** (Backups → *Back up server config*, or with
every scheduled run) archive `/etc/nginx`, `/etc/letsencrypt`,
`/etc/nebula-panel`, `/etc/nebula-mail` (including DKIM keys), Postfix,
Dovecot, BIND, PHP, MySQL client/server config, `sshd_config.d`, cron,
UFW and the panel's `data/` folder (without backups, caches and staging).
They contain private keys: they are panel-only (0600) and should only leave the
server encrypted. They are never restored automatically. To restore on a
rebuilt host: run `install.sh`, stop Nginx, PHP-FPM, Postfix and Dovecot,
extract the paths you need with `tar -xzf system-….tar.gz -C / <path>` as
root, then validate (`nginx -t`, `postfix check`, `doveconf -n`) and start the
services again. Mailboxes (`/var/mail/nebula`) are not included; copy them
separately.

**Off-site copies** (Backups → Off-site copies) upload archives to
S3-compatible storage (AWS S3, Backblaze B2, Wasabi, Cloudflare R2, MinIO). Each
archive is encrypted on the server first with standard OpenSSL and a
passphrase the panel generates, then signed: the ciphertext and its manifest
each carry an HMAC-SHA256 under a key derived from the passphrase, so a copy
or manifest altered in the bucket is refused before anything is decrypted (shown once, and again only after re-entering
your password). Keep the passphrase off the server: without it the copies
cannot be read. Every scheduled run uploads its new archives and keeps the
newest N automatic copies per website (manual uploads are kept). A failed upload
raises a critical alert. Use a bucket and access key dedicated to this server,
ideally with object versioning or a lifecycle rule, so a compromised server
cannot erase its history. To recover without the panel:

```
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in FILE.tar.gz.enc -out FILE.tar.gz
```

From a working panel, *Browse off-site copies* → *Download to server*
verifies the signatures, decrypts and checks the SHA-256 recorded in the
archive's manifest, after which it restores like any local archive. Copies
uploaded before signing existed still restore, with a warning; one this server
uploaded signed is refused if its signature has been stripped.

Test clean-host restores regularly and record recovery time and data loss.

## Updates

Updates → System packages lists apt upgrades. *Upgrade all* runs `apt-get
upgrade` (never adds or removes packages); *Full upgrade* (`full-upgrade`)
first previews the packages it would add or remove; *Remove unused packages*
runs `autoremove`. Locally changed configuration files are always kept
(`--force-confdef --force-confold`), so an upgrade never stops at a prompt.

*Automatic security updates* manages Debian/Ubuntu `unattended-upgrades`
(installed on first use) through `/etc/apt/apt.conf.d/20auto-upgrades` and
`52nebula-unattended`, optionally rebooting at a set time when an update
requires it.

## Scheduler and alerts

`nebula-panel-scheduler.timer` runs `bin/scheduler.php` every five minutes as
`nebula-panel`: it records resource history (`data/metrics-history.json`, 8
days), runs due scheduled backups, and evaluates alerts. Its last report is in
`data/scheduler.json`; delivery results of the last 20 alerts are in
`data/alerts-state.json`. Run it by hand with
`sudo -u nebula-panel php <panel-dir>/bin/scheduler.php`. The dashboard flags
the scheduler as stopped when it has not run for 20 minutes.

Health checks cover every writable filesystem (space and inodes, read-only
mounts excluded), memory, swap, load, failed services, backups (including
failed off-site uploads), certificates and the panel itself. A new problem
must persist for *Wait before alerting* minutes (default 10) before anyone is
notified, so short spikes do not page; reminders and recovery notices follow.
Alert email goes through the local mail system, or through an SMTP relay set
under Settings → Alerts (STARTTLS or TLS with a verified certificate; the
password is stored sealed with the panel key and is only sent over TLS).

## Sign-in and API access

Two-factor sign-in uses an authenticator app (TOTP) with one-time recovery
codes. Passkeys (Touch ID, Windows Hello, phones, security keys) can then be
added from My account as a faster second factor; they need the panel opened by
its host name over HTTPS (WebAuthn does not work on a bare IP address).
Turning two-factor sign-in off, or an administrator's reset, removes the
passkeys too.

API tokens belong to the person who creates them. A token never acts with more
access than its owner's current role, and stops working when the owner is
disabled or deleted. A password change (your own or by an administrator), an
administrator's two-factor reset and `nebula-recovery reset-admin` revoke the
account's tokens. Everyone can manage their own tokens from My account;
administrators see and revoke all of them under API Tokens.

Creating tokens and creating, changing or removing panel users need a sign-in
(or a password re-check) from the last 15 minutes; the browser asks for the
password, and the two-factor code when the account uses one. Tokens cannot
create tokens or manage users.

## Capacity and hardening

Monitor disk/inodes, FPM saturation/slow logs, mail queues, certificate expiry,
backup age, and service readiness. Keep OPcache enabled, size each pool from
measured memory, and use `pm.max_children` as a hard cap. Preserve emergency SSH
access before firewall changes. Apply security updates through a maintenance
window and validate service state afterward.
