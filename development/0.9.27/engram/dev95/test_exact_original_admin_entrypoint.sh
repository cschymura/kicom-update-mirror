#!/usr/bin/env bash
# DEV95: nonproduction exact-parent native admin entrypoint compatibility.
# Requires the ORIGINAL 0.9.37 archive locally. Never touches live webspace.
set -euo pipefail
archive="$1"
here="$(cd -- "$(dirname -- "$0")" && pwd)"
expected="0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7"
actual="$(sha256sum "$archive" | cut -d' ' -f1)"
[[ "$actual" == "$expected" ]] || { echo "FAIL original-parent SHA"; exit 1; }
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
unzip -p "$archive" admin.php > "$work/admin.php"
patch --fuzz=0 -d "$work" -p1 < "$here/NATIVE-original-admin-active-db-passkey-upgrade.patch"
patch --fuzz=0 -d "$work" -p1 < "$here/NATIVE-original-admin-menu-link.patch"
php -l "$work/admin.php"
[[ "$(grep -c 'engram_active_upgrade' "$work/admin.php")" -ge 3 ]]
[[ "$(grep -o 'admin.php?engram_active_upgrade=1' "$work/admin.php" | wc -l)" == 1 ]]
[[ "$(grep -o 'admin.php?engram_activate=1' "$work/admin.php" | wc -l)" -ge 1 ]]
[[ "$(grep -c 'KiComEngramOAuthAuthorizeHttp::handle(' "$work/admin.php")" == 1 ]]
[[ "$(grep -c "function csrf()" "$work/admin.php")" == 1 ]]
echo "PASS exact 0.9.37 SHA, zero-fuzz operator upgrade route and menu, original read and OAuth entrypoints, PHP syntax"
