#!/usr/bin/env bash
# Run every test suite; exit non-zero if any fails. Usage: bash tests/run-all.sh
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1
failed=()
run() {
  local name="$1"; shift
  if "$@" >/tmp/nebula-test-"$name".log 2>&1; then
    echo "ok   $name"
  else
    echo "FAIL $name (see /tmp/nebula-test-$name.log)"
    grep -E '^(not ok|FAIL)' /tmp/nebula-test-"$name".log | head -5 | sed 's/^/     /'
    failed+=("$name")
  fi
}
for suite in smoke security robustness session-lifecycle session-api http-flows; do
  run "$suite" php "tests/$suite.php"
done
run helper-contracts bash tests/helper-contracts.sh
run helper-sandbox bash tests/helper-sandbox.sh
run shellcheck shellcheck -S warning install.sh panel/bin/nebula-helper tests/helper-contracts.sh tests/helper-sandbox.sh tests/run-all.sh
run js-syntax bash -c 'for f in panel/assets/app.js panel/assets/passkeys.js panel/assets/vendor/lucide-subset.js tools/build-icons.js; do node --check "$f" || exit 1; done'
if (( ${#failed[@]} )); then echo "${#failed[@]} suite(s) failed: ${failed[*]}"; exit 1; fi
echo "all suites passed"
