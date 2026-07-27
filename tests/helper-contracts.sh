#!/usr/bin/env bash
# Root-free contract checks for privileged-helper parsing and validation.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
bash -n "$ROOT/install.sh"
bash -n "$ROOT/panel/bin/nebula-helper"
"$ROOT/panel/bin/nebula-helper" helper-self-test
