#!/usr/bin/env bash
set -euo pipefail
# Isolated GitHub Linux runner demonstration only: proves actual OS file
# permission separation for TWO principals. Not a claim about PHP webhosting.
root="/tmp/kicom-membrane-os-${GITHUB_RUN_ID:-dev}"
sudo useradd --system --no-create-home --shell /usr/sbin/nologin kicom_inner_ci
sudo useradd --system --no-create-home --shell /usr/sbin/nologin kicom_membrane_ci
sudo install -d -m 0755 -o root -g root "$root"
sudo install -d -m 0750 -o kicom_membrane_ci -g kicom_membrane_ci "$root/policy"
sudo install -d -m 0700 -o root -g root "$root/recovery"
sudo install -d -m 0700 -o kicom_inner_ci -g kicom_inner_ci "$root/interior"
sudo sh -c 'printf "%s\n" "membrane-controller-policy" > "$1"' sh "$root/policy/identity"
sudo chown kicom_membrane_ci:kicom_membrane_ci "$root/policy/identity"
sudo chmod 0400 "$root/policy/identity"
sudo sh -c 'printf "%s\n" "independent-recovery-root" > "$1"' sh "$root/recovery/anchor"
sudo chmod 0400 "$root/recovery/anchor"
sudo -u kicom_inner_ci sh -c 'printf "%s" "mutable-inner-state" > "$1"' sh "$root/interior/state"
test "$(sudo -u kicom_inner_ci cat "$root/interior/state")" = "mutable-inner-state"
echo "PASS interior can mutate own state"
if sudo -u kicom_inner_ci sh -c 'printf injected > "$1"' sh "$root/policy/identity" 2>/dev/null; then
  echo "FAIL interior modified independently owned policy" >&2
  exit 1
fi
echo "PASS interior cannot modify independently owned membrane policy"
if sudo -u kicom_inner_ci cat "$root/policy/identity" >/dev/null 2>&1; then
  echo "FAIL interior read privileged policy" >&2
  exit 1
fi
echo "PASS interior cannot read protected membrane policy"
if sudo -u kicom_inner_ci sh -c 'printf injected > "$1"' sh "$root/recovery/anchor" 2>/dev/null; then
  echo "FAIL interior modified protected recovery anchor" >&2
  exit 1
fi
echo "PASS interior cannot modify independent recovery anchor"
if sudo -u kicom_inner_ci cat "$root/recovery/anchor" >/dev/null 2>&1; then
  echo "FAIL interior read protected recovery anchor" >&2
  exit 1
fi
echo "PASS interior cannot read independent recovery anchor"
sudo -u kicom_membrane_ci test -r "$root/policy/identity"
if sudo -u kicom_membrane_ci sh -c 'printf nope > "$1"' sh "$root/recovery/anchor" 2>/dev/null; then
  echo "FAIL membrane controller modified recovery root" >&2
  exit 1
fi
echo "PASS membrane controller can inspect its policy but cannot modify recovery root"
echo "MEMBRANE_OS_BOUNDARY_TESTS_PASSED=6"
