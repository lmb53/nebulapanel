# Threat model

## Protected assets

Root access, panel state and sessions, site source, customer databases and mail,
TLS/DKIM keys, deploy credentials, backups, and audit records.

## Trust boundaries

Internet clients are untrusted. Hosted PHP and bundled third-party webapps are
untrusted relative to the panel. The panel application is trusted to request
administrator-authorized operations, while the root helper independently
validates action shape, resource IDs, path containment, and filesystem identity.
Docker control and administrator panel access are root-equivalent.

## Principal controls

- HTTPS is mandatory away from loopback; setup also needs a short-lived,
  single-use console token.
- Panel, bundled webapps, and sites have distinct Unix/FPM identities.
  Bundled webapps are served on their own origin (port 2096), never the
  panel's, and are rebuilt from their packages after every apt run.
- Hosted PHP is assumed able to escape its PHP sandbox and create arbitrary
  symlinks. Nginx refuses cross-owner symlinks; root never creates, chowns,
  chmods or ACL-walks a path inside a site tree (it refuses symlinks or acts
  as the site user); site PHP gets a private temp folder and no `putenv`.
- Website users cannot reach loopback-only App Store containers or the Apache
  backend (owner-matched firewall chain).
- Site paths are server-allocated from random immutable IDs. Root-owned state
  binds each public root to its device and inode. Site deletion archives rather
  than recursively deleting a caller-provided path.
- `www-data` and site/webapp accounts have no sudo rule. The panel account can
  invoke only the root-owned helper.
- Lower roles are read-only pending complete resource ownership ACLs.
- Logs use an exact source allowlist, byte/time caps, redaction, and auditing.
- Git rejects URL userinfo and runs public HTTPS deployments as the site owner.
- Mail authentication requires a real hostname certificate and TLS.
- Website cron jobs run as the site user; SFTP accounts are chrooted,
  `internal-sftp` only, with no forwarding.
- Secrets at rest (TOTP seeds, off-site storage keys and passphrase, SMTP
  password) are sealed with a key in `/etc/nebula-panel`, outside `data/`, so
  a copy of the data folder does not carry them.
- API tokens are owned: never above the owner's role, void when the owner is
  disabled or deleted, revoked when the owner's credentials change, and
  creatable only from a browser session that re-checked its password in the
  last 15 minutes (as are panel-user changes). Passkeys verify origin, site, presence, signature and a
  forward-moving use counter.
- Off-site backups are encrypted and then authenticated (HMAC-SHA256 over the
  ciphertext and the manifest) before upload; the storage provider sees the
  ciphertext plus each archive's name and plaintext manifest (domain, database
  names, sizes, checksum), never its contents, and cannot alter either
  undetected.
- Self-updates are fetched and checked by the root helper from a root-owned
  repository setting; the panel only names the commit. Trusted signing keys in
  `/etc/nebula-panel/update-signers` make signed commits mandatory.
- Push-to-deploy deliveries are authenticated by an HMAC signature or a
  secret header, not a secret in the URL.

## Known residual risks

The helper remains a large privileged shell program and needs VM-based negative
tests and eventual replacement by a smaller daemon using peer credentials and
argument-vector execution throughout. Docker actions are inherently
root-equivalent, and so is the panel's own service account in practice
(administrator features act through it). Off-site copies uploaded before
signing existed carry no MAC and restore with a warning; if this server's
local record is lost, a stripped signature cannot be told from such a legacy
copy. A server compromise exposes the off-site credentials, so use a dedicated
bucket key and provider-side versioning. Updates follow the configured branch
unless signing keys are configured or a commit is pinned. Any process running
as `www-data` (only Nginx on a standard install) can talk FastCGI to the panel
pool. The panel's CSP still allows inline styles (not scripts). Mailboxes are not part
of any backup. Self-update is locked, validated and snapshot-first with an
automatic rollback, but is not an atomic release-directory switch.
