# Operations baseline

## Supported test matrix

Target Ubuntu 22.04 and 24.04 on x86-64 with Nginx and the distribution PHP-FPM.
Treat other distributions, architectures, reverse proxies, and service versions
as unsupported until validated in disposable infrastructure.

## Local recovery

Run `sudo nebula-recovery reset-admin` from the server console to rotate the
administrator password and revoke sessions. Before first setup only,
`sudo nebula-recovery issue-bootstrap` issues a new one-hour token. Neither
workflow requires making setup publicly reachable.

File-manager deletion moves entries to root-owned
`/var/lib/nebula-panel/file-trash`; only a server administrator can inspect or
restore them. Website deletion moves the full site tree to
`/srv/nebula/trash`.

Set `PUBLIC_IP` when running the installer if mail/DNS guidance should publish
an address. Nebula deliberately does not infer a public address from
`hostname -I`.

## Ports

- Panel: 443 when `DOMAIN` is set; loopback port 80 only otherwise.
- Hosted sites: 80/443.
- Authoritative DNS: 53 TCP/UDP when selected.
- Mail: 25, 587 (TLS required for authentication), 993, and 995. Plaintext
  110/143 and unconfigured 465 are not opened.
- Compose application ports must bind to loopback. Pi-hole is omitted because it
  conflicts with the panel-managed BIND service.

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

## Data

Panel state lives in the installed panel's `data/` directory and is private to
`nebula-panel`. Sites live below `/srv/nebula/sites`; archived deletion targets
live below `/srv/nebula/trash`; root-owned resource state lives below
`/etc/nebula-panel`. Mail state spans `/etc/nebula-mail` and
`/var/mail/nebula`. Certificates are managed under `/etc/letsencrypt`.

## Upgrade and rollback

Pin a reviewed release commit. The updater resolves an immutable SHA, rejects
unsafe archive layouts/types, lints staged PHP, takes a required snapshot, and
serializes updates with a host lock. It retains five pre-update snapshots,
enters maintenance mode during the switch, runs a post-copy health check, and
automatically restores the previous code on failure. If automatic rollback
itself fails, stop Nginx/PHP-FPM, restore the latest snapshot as root, re-run
`install.sh` for installer-owned configuration, validate Nginx and all FPM
pools, then restore service.

## Backup and recovery

The built-in file backup is not a disaster-recovery system. Until complete
backup orchestration ships, independently back up and encrypt panel state, site
roots, database-consistent dumps, mail/Maildir, DKIM keys, DNS zones,
certificates, and relevant `/etc` configuration to an off-host destination.
Test clean-host restores regularly and record recovery time/data-loss results.

## Capacity and hardening

Monitor disk/inodes, FPM saturation/slow logs, mail queues, certificate expiry,
backup age, and service readiness. Keep OPcache enabled, size each pool from
measured memory, and use `pm.max_children` as a hard cap. Preserve emergency SSH
access before firewall changes. Apply security updates through a maintenance
window and validate service state afterward.
