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
  disabled or deleted. Passkeys verify origin, site, presence, signature and a
  forward-moving use counter.
- Off-site backups are encrypted before upload; the storage provider sees the
  ciphertext plus each archive's name and plaintext manifest (domain, database
  names, sizes, checksum), never its contents.

## Known residual risks

The helper remains a large privileged shell program and needs VM-based negative
tests and eventual replacement by a smaller daemon using peer credentials and
argument-vector execution throughout. Docker actions are inherently
root-equivalent. Off-site backups use AES-256-CBC without a separate MAC;
integrity rests on the SHA-256 in the archive manifest (checked after
download) and gzip's own checks, and the manifest itself is stored unencrypted
next to the archive. A server compromise exposes the off-site credentials, so
use a dedicated bucket key and provider-side versioning. Mailboxes are not part
of any backup. Self-update is locked, validated and snapshot-first with an
automatic rollback, but is not an atomic release-directory switch.
