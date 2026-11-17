#!/usr/bin/env bash
# Exercises the helper's embedded Python routines (confined file operations
# and the Compose policy) against hostile inputs, without root: the code is
# extracted from the shipped helper and pointed at a throwaway tree.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HELPER="$ROOT/panel/bin/nebula-helper"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

extract() { awk -v start="read -r -d '' $1 <<'PYEOF' || true" '$0 == start {on=1; next} on && $0 == "PYEOF" {exit} on {print}' "$HELPER"; }
extract FMSAFE_PY > "$WORK/fmsafe.py"
extract COMPOSE_POLICY_PY > "$WORK/policy.py"
[[ -s "$WORK/fmsafe.py" && -s "$WORK/policy.py" ]] || { echo "could not extract helper Python" >&2; exit 1; }

fails=0
ok()   { echo "ok - $1"; }
bad()  { echo "not ok - $1"; fails=$((fails + 1)); }
expect_fail() { local why="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$why"; else ok "$why"; fi; }
expect_ok()   { local why="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$why"; else bad "$why"; "$@" || true; fi; }

# --- confined file operations ----------------------------------------------
T="$WORK/fs"
mkdir -p "$T/etc" "$T/sites/site/public" "$T/panel/data/file-staging" "$T/trash" "$T/secret"
echo "secret" > "$T/secret/shadow"; chmod 600 "$T/secret/shadow"
echo "$T/sites" > "$T/etc/fm-root"; echo "$T/panel" > "$T/etc/panel-root"
export NEBULA_FM_ROOT_FILE="$T/etc/fm-root" NEBULA_PANEL_ROOT_FILE="$T/etc/panel-root" NEBULA_FILE_TRASH="$T/trash"
FS=(python3 -I "$WORK/fmsafe.py")
PUB="$T/sites/site/public"
# Site folders belong to a non-root site user (the ops refuse root-owned folders).
if [[ "$(id -u)" == 0 ]]; then chown -R 65534:65534 "$T/sites/site"; fi
ln -s "$T/secret" "$PUB/evil"
ln -s "$T/secret/shadow" "$PUB/evil-file"

expect_ok   "mkdir inside a site"                     "${FS[@]}" mkdir "$PUB/new"
expect_ok   "mkfile inside a site"                    "${FS[@]}" mkfile "$PUB/new/a.txt"
expect_fail "chmod through a symlinked folder"        "${FS[@]}" chmod "$PUB/evil/shadow" 777
expect_fail "chmod on a symlink"                      "${FS[@]}" chmod "$PUB/evil-file" 777
[[ "$(stat -c %a "$T/secret/shadow")" == 600 ]] && ok "symlink target mode unchanged" || bad "symlink target mode unchanged"
expect_fail "mkdir through a symlinked folder"        "${FS[@]}" mkdir "$PUB/evil/x"
expect_fail "install through a symlinked folder"      "${FS[@]}" install "$T/panel/data/file-staging/0123456789abcdef0123456789abcdef" "$PUB/evil/x" no
expect_fail "parent-directory component"              "${FS[@]}" chmod "$T/sites/site/../../secret/shadow" 777
expect_fail "path outside the root"                   "${FS[@]}" chmod "$T/secret/shadow" 777
expect_fail "rename across directories"               "${FS[@]}" rename "$PUB/new/a.txt" "$PUB/a.txt"
expect_fail "move a folder into itself"               "${FS[@]}" move "$PUB/new" "$PUB/new/inner"
expect_fail "staged file outside the staging folder"  "${FS[@]}" install "$T/secret/shadow" "$PUB/copy" no
expect_ok   "copy a symlink as a symlink"             "${FS[@]}" copy "$PUB/evil" "$PUB/evil-copy"
[[ -L "$PUB/evil-copy" ]] && ok "copied link stays a link" || bad "copied link stays a link"
expect_ok   "copy a folder"                           "${FS[@]}" copy "$PUB/new" "$PUB/new-copy"
[[ -f "$PUB/new-copy/a.txt" ]] && ok "folder contents copied" || bad "folder contents copied"
expect_ok   "trash a symlink"                         "${FS[@]}" trash "$PUB/evil-file"
[[ -f "$T/secret/shadow" ]] && ok "trashing a link keeps its target" || bad "trashing a link keeps its target"
echo hi > "$T/panel/data/file-staging/0123456789abcdef0123456789abcdef"
expect_ok   "install a staged upload"                 "${FS[@]}" install "$T/panel/data/file-staging/0123456789abcdef0123456789abcdef" "$PUB/up.txt" no
[[ "$(cat "$PUB/up.txt")" == hi ]] && ok "uploaded content installed" || bad "uploaded content installed"
expect_fail "the root itself cannot be trashed"       "${FS[@]}" trash "$T/sites"

# --- Compose policy ---------------------------------------------------------
P=(python3 -I "$WORK/policy.py")
compose() { printf '%s\n' "$2" > "$WORK/$1.yml"; echo "$WORK/$1.yml"; }
expect_ok   "pinned loopback stack"   "${P[@]}" "$(compose good $'services:\n  web:\n    image: nginx:1.27\n    ports: ["127.0.0.1:8080:80"]\n    volumes: [data:/data]\nvolumes:\n  data: {}')"
expect_fail "quoted privileged key"   "${P[@]}" "$(compose q1 $'services:\n  app:\n    image: "alpine:3.20"\n    "privileged": true')"
expect_fail "flow-style bind mount"   "${P[@]}" "$(compose q2 $'services: {app: {image: "alpine:3.20", volumes: ["/:/host"]}}')"
expect_fail "public port"             "${P[@]}" "$(compose q3 $'services:\n  app:\n    image: alpine:3.20\n    ports: ["0.0.0.0:80:80"]')"
expect_fail "long-syntax public port" "${P[@]}" "$(compose q4 $'services:\n  app:\n    image: alpine:3.20\n    ports: [{target: 80, published: 80}]')"
expect_fail "host network"            "${P[@]}" "$(compose q5 $'services:\n  app:\n    image: alpine:3.20\n    "network_mode": host')"
expect_fail "merge-key privileged"    "${P[@]}" "$(compose q6 $'x: &a\n  privileged: true\nservices:\n  app:\n    <<: *a\n    image: alpine:3.20')"
expect_fail "duplicate keys"          "${P[@]}" "$(compose q7 $'services:\n  app:\n    image: alpine:3.20\n    image: alpine:3.21')"
expect_fail "env_file"                "${P[@]}" "$(compose q8 $'services:\n  app:\n    image: alpine:3.20\n    env_file: /etc/shadow')"
expect_fail "latest tag"              "${P[@]}" "$(compose q9 $'services:\n  app:\n    image: alpine:latest')"
expect_fail "local driver bind"       "${P[@]}" "$(compose q10 $'services:\n  app:\n    image: alpine:3.20\n    volumes: [d:/d]\nvolumes:\n  d:\n    driver_opts: {type: none, o: bind, device: /}')"
expect_fail "interpolation"           "${P[@]}" "$(compose q11 $'services:\n  app:\n    image: alpine:3.20\n    environment: [X=${HOME}]')"
printf '%s' '{"services":{"app":{"image":"alpine:3.20","volumes":[{"type":"bind","source":"/","target":"/h"}]}}}' > "$WORK/norm.json"
expect_fail "normalized bind mount"   "${P[@]}" "$WORK/good.yml" "$WORK/norm.json"

if (( fails )); then echo "$fails helper sandbox check(s) failed" >&2; exit 1; fi
echo "helper sandbox checks passed"
