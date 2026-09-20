#!/usr/bin/env bash
set -euo pipefail
# Fixed-list, non-installable review package: never collect live/private files.
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
OUT="$ROOT/Engram-Hostprobe-REVIEW-DEV.zip"
if [ "$#" -gt 0 ]; then OUT="$1"; fi
case "$OUT" in /*.zip) ;; *) echo "Output must be absolute .zip path" >&2; exit 2;; esac
if [ -e "$OUT" ]; then
  echo "Refuse to overwrite existing review package" >&2
  exit 2
fi
TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT
D="$TMP/Engram-Hostprobe-REVIEW-DEV"
mkdir -p "$D/source" "$D/tests" "$D/workstation" "$D/baseline-r3"
cp -- development/0.9.27/engram/HOST-PROBE-UND-LOKALES-BACKUP-REVIEW.md "$D/README-REVIEW.md"
for file in KiComEngramStore.php KiComEngramIngestionGate.php KiComEngramAuthenticatedBridge.php KiComEngramDevMemoryAdapter.php KiComEngramPrivatePathProbe.php KiComEngramDevPathHandler.php KiComEngramDevCandidatePatcher.php KiComEngramSyntheticTransfer.php; do
  cp -- "development/0.9.27/engram/$file" "$D/source/$file"
done
for file in test-engram-authenticated-bridge.php test-engram-dev-memory-adapter.php test-private-path-probe.php test-engram-dev-route.php test-engram-endpoint.php test-engram-offline-transfer.php fixture-engram-trusted-config.php; do
  cp -- "development/0.9.27/engram/$file" "$D/tests/$file"
done
cp -- development/0.9.27/engram/engram_local_offline.py "$D/workstation/engram_local_offline.py"
for file in DevSession.php DevRouter.php DevHttpAdapter.php DevEndpoint.php; do
  cp -- "source/0.9.26-r3/modules/dev/$file" "$D/baseline-r3/$file"
done
(cd "$D" && find . -type f ! -name CHECKSUMS.sha256 -print0 \
    | LC_ALL=C sort -z | xargs -0 sha256sum > CHECKSUMS.sha256)
(cd "$TMP" && zip -X -q -r "$OUT" Engram-Hostprobe-REVIEW-DEV)
test -s "$OUT"
unzip -Z1 "$OUT" | grep -q 'README-REVIEW.md'
if unzip -Z1 "$OUT" | grep -E -i '(^|/)(\.env|.*\.key|.*\.sqlite|.*\.db|.*\.pem|.*\.p12|.*\.pfx|.*\.bak|.*\.zip)$'; then
    echo "REVIEW archive unexpectedly contains private-artifact extension" >&2
    exit 3
fi
echo "ENGRAM_REVIEW_PACKAGE_SHA256=$(sha256sum "$OUT" | cut -d' ' -f1)"
echo "ENGRAM_REVIEW_PACKAGE_CODE_ONLY=1"
echo "ENGRAM_REVIEW_PACKAGE_INSTALLABLE=0"
