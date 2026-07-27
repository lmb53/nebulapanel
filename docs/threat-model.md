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

## Known residual risks

The helper remains a large privileged shell program and needs VM-based negative
tests and eventual replacement by a smaller daemon using peer credentials and
argument-vector execution throughout. Docker actions are inherently
root-equivalent. Backups are not yet complete, encrypted, off-host disaster
recovery. Self-update is locked, validated, and snapshot-first but is not yet an
atomic release-directory switch with automatic health-check rollback.
