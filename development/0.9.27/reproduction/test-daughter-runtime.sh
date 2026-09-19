#!/usr/bin/env bash
set -euo pipefail
# Disposable GitHub Linux CI only. Not a production child or egress boundary.
# Execute after test-isolated-identity.sh and source manifest verification.
root="/tmp/kicom-membrane-os-$GITHUB_RUN_ID"
source="$(pwd)/source/0.9.26-r3"
code="$root/daughter-code"
daughter="$root/daughter"
sudo test -d "$daughter"
sudo test -f "$daughter/daughter-private.key"
sudo test -f "$root/interior/mother-private.key"
(cd "$source" && sha256sum --status -c MANIFEST.sha256)
sudo test ! -e "$code"
sudo install -d -m 0755 -o root -g root "$code"
sudo cp -a "$source/." "$code/"
sudo rm -rf "$code/var"
sudo chown -R root:root "$code"
sudo chmod -R u=rwX,go=rX "$code"
sudo install -d -m 0700 -o kicom_daughter_ci -g kicom_daughter_ci "$code/var"
sudo install -d -m 0700 -o kicom_daughter_ci -g kicom_daughter_ci "$code/stage"
sudo -u kicom_daughter_ci test -w "$code/var"
if sudo -u kicom_inner_ci ls "$code/var" >/dev/null 2>&1; then
  echo 'FAIL mother accessed daughter private runtime state' >&2; exit 1
fi
echo 'PASS daughter has an independent, mother-inaccessible private runtime directory'
if sudo -u kicom_daughter_ci sh -c 'printf injected >> "$1"' sh "$code/index.php" 2>/dev/null; then
  echo 'FAIL daughter modified root-owned source code' >&2; exit 1
fi
echo 'PASS daughter cannot change independently owned release code'
sudo test ! -e "$code/var/config.php"
sudo test ! -e "$code/var/db/kicom.sqlite"
sudo test ! -e "$code/var/project_memory/project_state.kcl"
echo 'PASS fresh daughter runtime contains no inherited config, DB or running project memory'

log="/tmp/kicom-daughter-$GITHUB_RUN_ID.log"
sudo -u kicom_daughter_ci php -S 127.0.0.1:18734 -t "$code" > "$log" 2>&1 &
daughter_pid=$!
trap 'kill "$daughter_pid" 2>/dev/null || true' EXIT
ready=0
for i in 1 2 3 4 5 6 7 8 9 10; do
  if curl --silent --fail --max-time 2 'http://127.0.0.1:18734/index.php?q=PING' > /tmp/kicom-daughter-ping.txt; then
    ready=1; break
  fi
  sleep 1
done
if test "$ready" -ne 1; then
  echo 'FAIL isolated daughter did not respond to local KCL ping' >&2
  sed -n '1,20p' "$log" >&2
  exit 1
fi
grep -q '^KCL/1' /tmp/kicom-daughter-ping.txt
grep -q '^OK ping' /tmp/kicom-daughter-ping.txt
echo 'PASS separately owned daughter executes original R3 KCL ping on localhost'

curl --silent --fail --max-time 3 'http://127.0.0.1:18734/index.php?q=HELLO' > /tmp/kicom-daughter-hello.txt
grep -q '^KCL/1' /tmp/kicom-daughter-hello.txt
grep -q '^OK hello' /tmp/kicom-daughter-hello.txt
echo 'PASS daughter retains baseline KCL-facing protocol locally (HTTP fixture only)'

# Read the ACTUAL native R3 genome response, not a made-up daughter genome.
# A 503 may be legitimate if the new private state lacks original production
# trust anchors; it remains a fail-closed development qualification result.
genome_report="/tmp/kicom-daughter-native-genome-$GITHUB_RUN_ID.kcl"
genome_http="$(curl --silent --max-time 5 --output "$genome_report" --write-out '%{http_code}' \
  'http://127.0.0.1:18734/index.php?q=GENOME_STATUS')"
test "$genome_http" = 200 || test "$genome_http" = 503 || {
  echo "FAIL native daughter Genome read returned HTTP $genome_http" >&2; exit 1;
}
grep -q '^KCL/1' "$genome_report"
grep -q '^FACT genome_id="kicom-0.9.26-g25r3"' "$genome_report"
echo 'PASS native daughter still reports inherited R3 release genome, NOT a unique child identity'

sudo -u kicom_daughter_ci test -d "$code/var/project_memory"
sudo -u kicom_daughter_ci test -f "$code/var/project_memory/project_state.kcl"
sudo -u kicom_daughter_ci test -r "$code/var/project_memory/project_state.kcl"
if sudo -u kicom_inner_ci cat "$code/var/project_memory/project_state.kcl" >/dev/null 2>&1; then
  echo 'FAIL mother read daughter private memory' >&2; exit 1
fi
echo 'PASS daughter initialized private release-seeded memory without mother read access'

# Release seed may contain historical parent/public template text; it is
# NOT a child-specific independently issued genome or transferred live memory.
sudo test ! -e "$code/var/config.php"
sudo test ! -e "$code/var/db/kicom.sqlite" || sudo -u kicom_daughter_ci test -r "$code/var/db/kicom.sqlite"
echo 'PASS no parent credentials copied; any initialized SQLite belongs to daughter var'

if sudo -u kicom_daughter_ci cat "$root/interior/mother-private.key" >/dev/null 2>&1; then
  echo 'FAIL daughter read mother identity after runtime initialization' >&2; exit 1
fi
if sudo -u kicom_inner_ci cat "$daughter/daughter-private.key" >/dev/null 2>&1; then
  echo 'FAIL mother read daughter identity after runtime initialization' >&2; exit 1
fi
echo 'PASS mutual key isolation survives local daughter runtime initialization'

sudo test -f "$root/interior/state"
test "$(sudo -u kicom_inner_ci cat "$root/interior/state")" = 'mutable-inner-state'
echo 'PASS mother mutable runtime state remains intact during daughter boot'
echo 'KICOM_REPRODUCTION_RUNTIME_TESTS_PASSED=9'
