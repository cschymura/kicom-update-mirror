#!/usr/bin/env bash
set -euo pipefail
# Run AFTER test-os-boundary.sh on disposable GitHub Linux runner.
# This proves cross-UID synthetic communications and filesystem access denial.
# It does NOT prove a non-bypassable production egress gateway.
root="/tmp/kicom-membrane-os-$GITHUB_RUN_ID"
source="$(pwd)/development/0.9.27/membrane"
fixture="$root/fixtures"
test -d "$root/interior"
test -f "$source/fixture-isolated-transport.php"
test -f "$source/test-cross-uid-transport.php"
# The checkout may live below a private runner home; the two real test
# principals must NOT be given access to that home. Copy only inert fixtures
# into a root-owned read-only path outside both their writable domains.
sudo install -d -m 0755 -o root -g root "$fixture"
sudo install -m 0644 -o root -g root "$source/fixture-isolated-transport.php" "$fixture/fixture-isolated-transport.php"
sudo install -m 0644 -o root -g root "$source/test-cross-uid-transport.php" "$fixture/test-cross-uid-transport.php"
sudo -u kicom_inner_ci ln -s "$root/policy/identity" "$root/interior/policy-proxy"
if sudo -u kicom_inner_ci sh -c 'printf forged > "$1"' sh "$root/interior/policy-proxy" 2>/dev/null; then
  echo 'FAIL interior wrote privileged policy through symlink' >&2
  exit 1
fi
echo 'PASS interior cannot overwrite policy through its own symlink'
if sudo -u kicom_inner_ci sh -c 'chmod 0600 "$1"' sh "$root/policy/identity" 2>/dev/null; then
  echo 'FAIL interior changed privileged policy permissions' >&2
  exit 1
fi
echo 'PASS interior cannot alter privileged policy permissions'
if sudo -u kicom_inner_ci mv "$root/policy" "$root/interior/policy-stolen" 2>/dev/null; then
  echo 'FAIL interior renamed privileged policy directory' >&2
  exit 1
fi
echo 'PASS interior cannot move privileged policy directory'
if sudo -u kicom_membrane_ci cat "$root/interior/state" >/dev/null 2>&1; then
  echo 'FAIL controller accessed private mutable interior state' >&2
  exit 1
fi
echo 'PASS membrane process cannot read private mutable interior state'
receipts="$root/interior/receipts"
sudo -u kicom_inner_ci env \
  KICOM_MEMBRANE_ISOLATED_RECEIPTS="$receipts" \
  php -S 127.0.0.1:18731 -t "$fixture" > /tmp/kicom-membrane-cross-uid.log 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT
ready=0
for i in 1 2 3 4 5 6 7 8 9 10; do
  if curl --silent --fail 'http://127.0.0.1:18731/fixture-isolated-transport.php?serial=ready' >/dev/null; then
    ready=1
    break
  fi
  sleep 1
done
test "$ready" -eq 1 || { cat /tmp/kicom-membrane-cross-uid.log; exit 1; }
sudo -u kicom_inner_ci rm -f "$receipts"
sudo -u kicom_membrane_ci php "$fixture/test-cross-uid-transport.php" http://127.0.0.1:18731
test "$(sudo -u kicom_inner_ci sh -c 'wc -l < "$1"' sh "$receipts")" -eq 4 || {
  echo 'FAIL cross-UID request receipts not exactly once' >&2
  exit 1
}
echo 'PASS cross-UID synthetic receiver logged exactly one receipt per accepted request'
test "$(sudo -u kicom_membrane_ci cat "$root/policy/identity")" = "membrane-controller-policy"
test "$(sudo cat "$root/recovery/anchor")" = "independent-recovery-root"
echo 'PASS cross-UID communication left membrane policy and recovery anchor unchanged'
echo 'MEMBRANE_OS_BYPASS_TESTS_PASSED=6'
