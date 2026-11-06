#!/usr/bin/env bash
# Root-free contract checks for privileged-helper parsing and validation.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
bash -n "$ROOT/install.sh"
bash -n "$ROOT/panel/bin/nebula-helper"
"$ROOT/panel/bin/nebula-helper" helper-self-test

# New privileged actions must reject malformed arguments before touching
# anything (these run unprivileged, so they can only exercise validation).
expect_reject() {
  local why="$1"; shift
  if "$ROOT/panel/bin/nebula-helper" "$@" >/dev/null 2>&1; then
    echo "helper accepted: $why" >&2
    exit 1
  fi
}
expect_reject "bad site id for backup" site-backup not-a-site x-20260101-000000.tar.gz
expect_reject "system database in backup" site-backup 0123456789abcdef0123456789abcdef x-20260101-000000.tar.gz mysql
expect_reject "injected database name" site-backup 0123456789abcdef0123456789abcdef x-20260101-000000.tar.gz 'db;rm'
expect_reject "bad restore mode" site-restore 0123456789abcdef0123456789abcdef x-20260101-000000.tar.gz maybe
expect_reject "empty restore" site-restore 0123456789abcdef0123456789abcdef x-20260101-000000.tar.gz no
expect_reject "extract outside root" file-extract /etc/passwd /tmp out
expect_reject "unknown power action" system-power halt
expect_reject "unknown power option" system-power reboot now
# The detached package worker is only reachable from systemd-run, never sudo.
expect_reject "package worker without systemd-run" __package-worker /run/nebula-panel-ops/op.AAAAAAAA 1 pma-install /tmp/x
echo "helper argument validation contracts passed"
