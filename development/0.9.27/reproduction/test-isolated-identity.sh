#!/usr/bin/env bash
set -euo pipefail
# Isolated GitHub Linux LAB. This does not launch a KiCom daughter, change
# production credentials, allocate an external host, or grant deployment.
# Run AFTER test-os-boundary.sh; the mother remains untouched throughout.
root="/tmp/kicom-membrane-os-$GITHUB_RUN_ID"
test -d "$root/interior" && test -f "$root/policy/identity"
before="$(sha256sum "$root/interior/state" "$root/policy/identity" "$root/recovery/anchor")"
sudo useradd --system --no-create-home --shell /usr/sbin/nologin kicom_daughter_ci
sudo install -d -m 0700 -o kicom_daughter_ci -g kicom_daughter_ci "$root/daughter"
# Each identity is generated while running AS its respective separate UID;
# private keys are only written inside independently protected directories.
sudo -u kicom_inner_ci php -r '
  $kp=sodium_crypto_sign_keypair();
  $private=sodium_crypto_sign_secretkey($kp);
  $file=$argv[1];
  if(file_put_contents($file,$private,LOCK_EX)!==strlen($private))exit(2);
  chmod($file,0400);
  echo base64_encode(sodium_crypto_sign_publickey($kp)),"\n";
' "$root/interior/mother-private.key" > "$root/mother-public.key"
sudo -u kicom_daughter_ci php -r '
  $kp=sodium_crypto_sign_keypair();
  $private=sodium_crypto_sign_secretkey($kp);
  $file=$argv[1];
  if(file_put_contents($file,$private,LOCK_EX)!==strlen($private))exit(2);
  chmod($file,0400);
  echo base64_encode(sodium_crypto_sign_publickey($kp)),"\n";
' "$root/daughter/daughter-private.key" > "$root/daughter-public.key"
sudo chmod 0444 "$root/mother-public.key" "$root/daughter-public.key"
if cmp -s "$root/mother-public.key" "$root/daughter-public.key"; then
  echo "FAIL daughter reused mother's signing identity" >&2; exit 1
fi
echo 'PASS mother and daughter possess distinct Ed25519 public identities'
if sudo -u kicom_inner_ci cat "$root/daughter/daughter-private.key" >/dev/null 2>&1; then
  echo 'FAIL mother read daughter private identity' >&2; exit 1
fi
echo 'PASS mother cannot read daughter private identity'
if sudo -u kicom_daughter_ci cat "$root/interior/mother-private.key" >/dev/null 2>&1; then
  echo 'FAIL daughter read mother private identity' >&2; exit 1
fi
echo 'PASS daughter cannot read mother private identity'
if sudo -u kicom_daughter_ci sh -c 'printf takeover > "$1"' sh "$root/policy/identity" 2>/dev/null; then
  echo 'FAIL daughter modified independent membrane policy' >&2; exit 1
fi
echo 'PASS daughter cannot change independent membrane policy'
if sudo -u kicom_daughter_ci sh -c 'printf takeover > "$1"' sh "$root/recovery/anchor" 2>/dev/null; then
  echo 'FAIL daughter modified recovery trust root' >&2; exit 1
fi
echo 'PASS daughter cannot change independent recovery root'
if sudo -u kicom_daughter_ci sh -c 'printf takeover > "$1"' sh "$root/interior/state" 2>/dev/null; then
  echo 'FAIL daughter modified mother persistent state' >&2; exit 1
fi
echo 'PASS daughter cannot alter mother's persistent state'
after="$(sha256sum "$root/interior/state" "$root/policy/identity" "$root/recovery/anchor")"
test "$before" = "$after"
echo 'PASS identity genesis left protected mother state, policy and recovery bytes unchanged'
test "$(sudo stat -c '%U:%a' "$root/daughter/daughter-private.key")" = "kicom_daughter_ci:400"
echo 'PASS daughter private key is owned by daughter UID with read-only owner permissions'
# No copy, fork, independent recovery runtime, network binding or deployment
# was performed. The public IDs are test artifacts, not signed birth grants.
echo 'KICOM_REPRODUCTION_ISOLATION_TESTS_PASSED=8'
