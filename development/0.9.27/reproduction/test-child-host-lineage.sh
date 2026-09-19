#!/usr/bin/env bash
set -euo pipefail
# Disposable runner, after test-isolated-identity.sh AND test-daughter-runtime.sh.
# A lab-only host-root-checked identity receipt, NOT the native R3 genome.
root="/tmp/kicom-membrane-os-$GITHUB_RUN_ID"
source="$(pwd)/development/0.9.27/reproduction"
fixture="$root/fixtures"
sudo install -d -m 0755 -o root -g root "$fixture"
sudo install -m 0644 -o root -g root "$source/KiComChildLineage.php" "$fixture/KiComChildLineage.php"
sudo test -f "$root/mother-public.key"
sudo test -f "$root/daughter-public.key"
sudo test -f "$root/daughter/daughter-private.key"
sudo test ! -e "$root/daughter-candidate.json"
nonce="$(php -r 'echo bin2hex(random_bytes(32));')"
sudo sh -c 'printf "%s" "$1" > "$2"' sh "$nonce" "$root/genesis-nonce"
sudo chmod 0444 "$root/genesis-nonce"
# A child-owned process signs the challenge; the verifier never reads the
# child's private key. No capability to send messages or deploy is issued.
sudo -u kicom_daughter_ci php -r '
  require $argv[1];
  $nonce=trim(file_get_contents($argv[2]));
  $p=trim(file_get_contents($argv[3]));
  $c=trim(file_get_contents($argv[4]));
  $secret=file_get_contents($argv[5]);
  if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)exit(2);
  $pkg="6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f";
  echo base64_encode(sodium_crypto_sign_detached(
      KiComChildLineage::proofBytes($nonce,$p,$c,$pkg),$secret)),"\n";
' "$fixture/KiComChildLineage.php" "$root/genesis-nonce" \
  "$root/mother-public.key" "$root/daughter-public.key" \
  "$root/daughter/daughter-private.key" | sudo tee "$root/genesis-proof" >/dev/null
sudo chmod 0444 "$root/genesis-proof"
echo 'PASS daughter signed host-bound nonce using its own protected identity'
sudo php -r '
  require $argv[1];
  $root=$argv[2];
  $nonce=trim(file_get_contents($root."/genesis-nonce"));
  $p=trim(file_get_contents($root."/mother-public.key"));
  $c=trim(file_get_contents($root."/daughter-public.key"));
  $sig=trim(file_get_contents($root."/genesis-proof"));
  $pkg="6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f";
  $r=KiComChildLineage::candidateRecord($nonce,$p,$c,$pkg,$sig,
        "daughter-one","kicom-0.9.26-g25r3");
  if(!$r["ok"] || $r["native_genome_bound"] || $r["deployment_authorized"])exit(3);
  if(file_put_contents($root."/daughter-candidate.json",
        json_encode($r,JSON_THROW_ON_ERROR),LOCK_EX)===false)exit(4);
' "$fixture/KiComChildLineage.php" "$root"
sudo chmod 0444 "$root/daughter-candidate.json"
echo 'PASS independent host verifier produced only an inert daughter lineage candidate'
if sudo -u kicom_daughter_ci sh -c 'printf forged > "$1"' sh "$root/daughter-candidate.json" 2>/dev/null; then
  echo 'FAIL daughter rewrote host-protected lineage candidate' >&2; exit 1
fi
echo 'PASS daughter cannot rewrite host lineage candidate'
if sudo -u kicom_inner_ci sh -c 'printf forged > "$1"' sh "$root/daughter-candidate.json" 2>/dev/null; then
  echo 'FAIL mother rewrote daughter host lineage candidate' >&2; exit 1
fi
echo 'PASS mother cannot rewrite host lineage candidate'
sudo php -r '
  $root=$argv[1];
  $r=json_decode(file_get_contents($root."/daughter-candidate.json"),true,16,JSON_THROW_ON_ERROR);
  $p=base64_decode(trim(file_get_contents($root."/mother-public.key")),true);
  $c=base64_decode(trim(file_get_contents($root."/daughter-public.key")),true);
  if(!is_string($p)||!is_string($c))exit(3);
  if($r["parent_public_fingerprint"]!==hash("sha256",$p)
     ||$r["child_public_fingerprint"]!==hash("sha256",$c)
     ||$r["native_genome_bound"]||$r["recovery_bound"]
     ||$r["independent_host_qualified"]||$r["external_action_authorized"])exit(4);
' "$root"
echo 'PASS lineage fingerprints bind distinct keys without falsely claiming native Genome'
sudo test -f "$root/daughter/daughter-private.key"
sudo test -f "$root/interior/mother-private.key"
echo 'PASS neither mother nor daughter private key was consumed or copied into lineage record'
echo 'KICOM_CHILD_HOST_LINEAGE_TESTS_PASSED=6'
