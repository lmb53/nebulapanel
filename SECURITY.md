# Security policy

Nebula Panel is pre-production software. Only the current main branch is
supported for security fixes. Do not expose it to the Internet or host untrusted
applications until you have validated the install in a disposable VM matching
your production OS.

Report vulnerabilities privately through GitHub Security Advisories for
`lmb53/nebulapanel`. Do not open a public issue containing exploit details,
credentials, private logs, or customer data. Include the affected commit,
configuration, reproduction steps, and impact. Rotate any credential included
in a report.

The random panel URL is not authentication. The intended boundaries are:

- Nginx routes the panel to the `nebula-panel` FPM pool.
- Third-party bundled webapps use `nebula-webapps`.
- Every hosted site has its own Unix account, FPM pool, socket, and immutable
  root below `/srv/nebula/sites/<id>/public`.
- Only `nebula-panel` may invoke the root-owned validating helper.
- Docker and panel administrator access are root-equivalent.
- Non-administrator panel roles are read-only until resource ACL coverage is
  complete.

If compromise is suspected, isolate the host, preserve logs, revoke panel/API
sessions, rotate service and deploy credentials, and restore onto a clean host
from a verified backup. Do not trust an in-place cleanup after root compromise.
