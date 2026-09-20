#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
SRC="$ROOT/source/0.9.26-r3"
TMP="$(mktemp -d)"; trap 'kill ${PID:-0} 2>/dev/null || true; rm -rf "$TMP"' EXIT
RUNTIME="$TMP/daughter"; cp -a "$SRC" "$RUNTIME"
(cd "$SRC" && sha256sum --status -c MANIFEST.sha256)
echo '6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f  releases/0.9.26/KiCom-0.9.26-R3.zip' | (cd "$ROOT" && sha256sum -c -)
KEYDIR="$TMP/host-key"; mkdir -m 700 "$KEYDIR"
php -r '$kp=sodium_crypto_sign_keypair();file_put_contents($argv[1],base64_encode(sodium_crypto_sign_publickey($kp)));file_put_contents($argv[2],base64_encode(sodium_crypto_sign_secretkey($kp)));' "$KEYDIR/public" "$KEYDIR/secret"
chmod 600 "$KEYDIR"/*
NONCE="$(php -r 'echo bin2hex(random_bytes(32));')"
php -r 'require $argv[1];$p=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);$c=KiComDerivativeGenome::build($p,trim(file_get_contents($argv[3])),$argv[5]);$c=KiComDerivativeGenome::sign($c,trim(file_get_contents($argv[4])));if(!KiComDerivativeGenome::verify($c,trim(file_get_contents($argv[3]))))exit(9);file_put_contents($argv[6],json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$ROOT/development/0.9.27/reproduction/KiComDerivativeGenome.php" "$SRC/genome/genome.json" "$KEYDIR/public" "$KEYDIR/secret" "$NONCE" "$RUNTIME/genome/genome.json"
# Private key is not part of runtime; erase the CI-only signing material after genesis.
rm -f "$KEYDIR/secret"
PORT=$((20000 + RANDOM % 20000)); php -S "127.0.0.1:$PORT" -t "$RUNTIME" >"$TMP/server.log" 2>&1 & PID=$!
for i in {1..30}; do if curl -fsS "http://127.0.0.1:$PORT/index.php?q=PING" >"$TMP/ping"; then break; fi; sleep .1; done
grep -q '^OK ping$' "$TMP/ping"
curl -fsS "http://127.0.0.1:$PORT/index.php?q=GENOME_STATUS" >"$TMP/genome-status"
ID="$(php -r '$g=json_decode(file_get_contents($argv[1]),true);echo $g["id"];' "$RUNTIME/genome/genome.json")"
grep -Fq "FACT genome_id=\"$ID\"" "$TMP/genome-status"
grep -Fq 'FACT trusted=true' "$TMP/genome-status"; grep -Fq 'FACT healthy=true' "$TMP/genome-status"; grep -Fq 'FACT lkg_ok=true' "$TMP/genome-status"; grep -Fq 'FACT drift_count=0' "$TMP/genome-status"; grep -Fq 'FACT unknown_count=0' "$TMP/genome-status"
# Independent host verification of the exact genome that KCL just reported.
php -r 'require $argv[1];$c=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);if(!KiComDerivativeGenome::verify($c,trim(file_get_contents($argv[3]))))exit(7);if(($c["derivative_identity"]["authority_granted"]??true)!==false||($c["derivative_identity"]["deployment_permitted"]??true)!==false)exit(8);' "$ROOT/development/0.9.27/reproduction/KiComDerivativeGenome.php" "$RUNTIME/genome/genome.json" "$KEYDIR/public"
# Original source remains byte-for-byte verifier-clean after derivative runtime exercise.
(cd "$SRC" && sha256sum --status -c MANIFEST.sha256)
printf 'OK derivative-runtime: actual KCL GENOME_STATUS child=%s trusted/healthy/LKG, source R3 unchanged\n' "$ID"
