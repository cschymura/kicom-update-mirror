#!/usr/bin/env python3
"""Build a complete CODE-CONTAINING KiCom release candidate with Engram inert.

Uses exact verified 0.9.26-R3 parent and trusted native update packaging.
Includes all synthetic-test-proven memory modules, browser assets and a
fail-closed first-party route. NO private configuration or user memories.
The route is intentionally NOT activated without a later approved genuine
server-bound browser identity, audited host isolation and connector bridge.
"""
from __future__ import annotations

import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile

BASE = Path("development/0.9.27/engram")
R3 = Path("source/0.9.26-r3")
SPEC = importlib.util.spec_from_file_location("kicom_host_probe", BASE / "build_staging_host_probe.py")
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Trusted R3 package builder unavailable")
host_probe = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(host_probe)

VERSION = "0.9.27"
GENOME = "kicom-0.9.27-g26-engram-integrated-disabled"
MEMORY_MODULES = [
    "KiComEngramStore.php",
    "KiComEngramIngestionGate.php",
    "KiComEngramAuthenticatedBridge.php",
    "KiComEngramDevMemoryAdapter.php",
    "KiComEngramDevSessionCredentialReader.php",
    "KiComEngramVerifiedCredentialResolver.php",
    "KiComEngramPrivateOwnerRegistry.php",
    "KiComEngramPrivateConsentLedger.php",
    "KiComEngramFirstPartyReview.php",
    "KiComEngramBrowserReviewPage.php",
    "KiComEngramWebAuthnApprovalController.php",
    "KiComEngramWebAuthnReviewHttpAdapter.php",
    "KiComEngramPrivatePathProbe.php",
    "KiComEngramDevPathHandler.php",
]
ASSET = "engram-review-client.js"
ENTRY = """<?php
declare(strict_types=1);
/* Engram private memory remains OFF in this code-containing release.
   This route cannot be enabled by query parameters, headers, cookies,
   GitHub/Slack messages or an ordinary KiCom DEV bearer token.
   The genuine first-party browser identity and private host config still
   need an explicitly reviewed production integration. */
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
http_response_code(404);
exit;
"""


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def build(dest: Path) -> dict:
    files, original = host_probe.read_verified_source()
    with tempfile.TemporaryDirectory(prefix="kicom-engram-disabled-") as tmp:
        host_probe.patch_candidate(files, Path(tmp) / "candidate")
    host_probe.patch_version(files)
    module_manifest = json.loads(files["genome/modules.json"])
    old_paths = {m["path"] for m in module_manifest["modules"]}
    if "engram.php" in files or any(name.startswith("modules/engram/") for name in files):
        raise RuntimeError("Unrecognized original Engram code in trusted R3")

    additions: dict[str, bytes] = {"engram.php": ENTRY.encode("utf-8")}
    for name in MEMORY_MODULES:
        raw = (BASE / name).read_bytes()
        if not raw.startswith(b"<?php"):
            raise RuntimeError(f"Missing PHP module header: {name}")
        target = "modules/engram/" + name
        additions[target] = raw
        if target in old_paths:
            raise RuntimeError("Unexpected original module manifest entry")
        # Keep inert and absent from global trusted runtime bootstrap.
        module_manifest["modules"].append({"path": target, "enabled": False})
    js = (BASE / ASSET).read_bytes()
    if len(js) > 15000:
        raise RuntimeError("Unexpected browser asset size")
    additions["assets/engram-review-client.js"] = js
    files.update(additions)
    files["genome/modules.json"] = (
        json.dumps(module_manifest, ensure_ascii=False, indent=2) + "\n"
    ).encode("utf-8")

    genome = json.loads(json.dumps(original))
    if genome["id"] != host_probe.BASE_GENOME_ID or genome["generation"] != 25:
        raise RuntimeError("Unexpected baseline genome")
    genome.update({
        "id": GENOME,
        "version": VERSION,
        "parent": host_probe.BASE_GENOME_ID,
        "generation": 26,
        "mutation_reason": "engram-private-memory-components-present-but-inert-pending-human-approved-host-trust",
    })
    components = genome["components"]
    by_path = {row["path"]: row for row in components}
    changed = sorted(
        rel for rel, data in files.items()
        if rel not in host_probe.load_manifest(R3)
        or data != (R3 / rel).read_bytes()
    )
    for rel in changed:
        data = files[rel]
        if rel in by_path:
            by_path[rel]["sha256"] = digest(data)
        else:
            if rel not in additions:
                raise RuntimeError("Unexpected added file")
            components.append({
                "path": rel,
                "sha256": digest(data),
                "role": "engram-disabled-public-route" if rel == "engram.php"
                else ("engram-inert-source" if rel.endswith(".php") else "engram-inert-asset"),
                "auto_heal": True,
            })
    if "lib.php" not in changed or "genome/modules.json" not in changed:
        raise RuntimeError("Version or module manifest not advanced")
    for critical in ("index.php", "api.php", "guardian.php", "recovery.php", ".htaccess"):
        if files[critical] != (R3 / critical).read_bytes():
            raise RuntimeError("Critical production source unexpectedly modified")
    if len(set(files)) != len(files):
        raise RuntimeError("Duplicate output path")
    files["genome/genome.json"] = (
        json.dumps(genome, ensure_ascii=False, indent=2) + "\n"
    ).encode("utf-8")
    record = host_probe.package(files, dest)
    record.update({
        "candidate_genome": GENOME,
        "version": VERSION,
        "changed_paths": changed + ["genome/genome.json"],
        "memory_source_modules_included": len(MEMORY_MODULES),
        "private_browser_route_enabled": False,
        "private_memory_read_write_enabled": False,
        "live_connector_included": False,
        "private_host_config_included": False,
        "production_installation_attempted": False,
        "requires_separate_runtime_trust_review": True,
    })
    return record


def main() -> int:
    if len(sys.argv) != 2:
        raise RuntimeError("Usage: build_engram_integrated_candidate.py /absolute/path/NEW.zip")
    dest = Path(sys.argv[1])
    if not dest.is_absolute() or dest.suffix != ".zip":
        raise RuntimeError("Absolute new ZIP destination required")
    print(json.dumps(build(dest), sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (RuntimeError, OSError, ValueError, subprocess.CalledProcessError) as exc:
        print("ENGRAM_INTEGRATED_CANDIDATE_BUILD_FAILED=" + type(exc).__name__, file=sys.stderr)
        raise SystemExit(1)
