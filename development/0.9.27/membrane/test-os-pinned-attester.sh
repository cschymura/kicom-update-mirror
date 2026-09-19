#!/usr/bin/env bash
set -euo pipefail
# Disposable Linux CI ONLY. Execute after the two-principal OS boundary test.
# A separate membrane principal owns a synthetic private attestation key.
# The interior can only read a root-pinned public key, never replace it.
# This is NOT Slack-provider validation or a KiCom production trust root.
root="/tmp/kicom-membrane-os-$GITHUB_RUN_ID"
source="$(pwd)/development/0.9.27/membrane"
fixture="$root/fixtures"
test -d "$root/policy" && test -d "$root/interior" && test -d "$fixture"
sudo install -m 0644 -o root -g root "$source/KiComMembraneStagingAttestation.php" "$fixture/KiComMembraneStagingAttestation.php"
sudo install -m 0644 -o root -g root "$source/fixture-os-attestation.php" "$fixture/fixture-os-attestation.php"

sudo php "$fixture/fixture-os-attestation.php" initialize "$root" >/dev/null
test "$(sudo stat -c %a "$root/policy/staging-private.key")" = "400"
test "$(sudo stat -c %U "$root/policy/staging-private.key")" = "kicom_membrane_ci"
test "$(sudo stat -c %U "$root/pinned-public.key")" = "root"
echo 'PASS test signer key is private to a different host principal and verifier key is pinned by root'

if sudo -u kicom_inner_ci cat "$root/policy/staging-private.key" >/dev/null 2>&1; then
  echo 'FAIL KiCom interior read independent staging private key' >&2; exit 1
fi
echo 'PASS interior cannot read the signing private key'

if sudo -u kicom_inner_ci sh -c 'printf intruder > "$1"' sh "$root/policy/staging-private.key" 2>/dev/null; then
  echo 'FAIL KiCom interior modified protected signer key' >&2; exit 1
fi
echo 'PASS interior cannot modify independent private signing key'

if sudo -u kicom_inner_ci sh -c 'printf replaced > "$1"' sh "$root/pinned-public.key" 2>/dev/null; then
  echo 'FAIL KiCom interior modified pinned public key' >&2; exit 1
fi
echo 'PASS interior cannot substitute independently pinned verification key'

if sudo -u kicom_inner_ci sh -c 'printf invented > "$1"' sh "$root/trusted-staging-context.json" 2>/dev/null; then
  echo 'FAIL KiCom interior modified independently pinned expected context' >&2; exit 1
fi
echo 'PASS interior cannot modify trusted synthetic attestation context'

sudo -u kicom_membrane_ci php "$fixture/fixture-os-attestation.php" sign "$root" | sudo tee "$root/signed.json" >/dev/null
sudo chmod 0444 "$root/signed.json"
test -s "$root/signed.json"
echo 'PASS isolated membrane principal signs synthetic metadata without exposing its private key'

valid="$(sudo -u kicom_membrane_ci php "$fixture/fixture-os-attestation.php" verify "$root" "$root/signed.json")"
test "$valid" = 'SIGNED_STAGING_CLAIM_INTEGRITY_VERIFIED'
echo 'PASS independent pinned-key verifier accepts genuine synthetic signed statement without action authority'

if sudo -u kicom_inner_ci php "$fixture/fixture-os-attestation.php" sign "$root" >/dev/null 2>&1; then
  echo 'FAIL interior invoked protected signer' >&2; exit 1
fi
echo 'PASS KiCom interior cannot use protected signer via the staging CLI'

# The interior may generate a different key pair. Its signature is still
# rejected because the verifier reads only the root-pinned verification key.
sudo -u kicom_inner_ci php -r '
  require $argv[2];
  $packet=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
  $pair=sodium_crypto_sign_keypair();
  $packet["signature"]=base64_encode(sodium_crypto_sign_detached(
    KiComMembraneStagingAttestation::signingBytes($packet["claim"]),
    sodium_crypto_sign_secretkey($pair)));
  echo json_encode($packet,JSON_THROW_ON_ERROR),"\n";
' "$root/signed.json" "$fixture/KiComMembraneStagingAttestation.php" | sudo tee "$root/forged.json" >/dev/null
sudo chmod 0444 "$root/forged.json"
if sudo -u kicom_membrane_ci php "$fixture/fixture-os-attestation.php" verify "$root" "$root/forged.json" >/dev/null 2>&1; then
  echo 'FAIL independent verifier accepted a user-supplied forged key/signature' >&2; exit 1
fi
echo 'PASS forged but self-consistent inner signature cannot replace a pinned signer'

test "$(sudo -u kicom_membrane_ci cat "$root/policy/identity")" = 'membrane-controller-policy'
test "$(sudo cat "$root/recovery/anchor")" = 'independent-recovery-root'
echo 'PASS synthetic trust-root test leaves membrane policy and recovery root unchanged'
echo 'MEMBRANE_PINNED_KEY_TESTS_PASSED=10'
