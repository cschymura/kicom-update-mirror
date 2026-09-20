#!/usr/bin/env python3
"""Build a complete CODE-CONTAINING KiCom release candidate with Engram inert.

Uses exact verified 0.9.26-R3 parent and trusted native update packaging.
Includes all synthetic-test-proven memory modules and browser assets but NO
first-party route (native KiCom update allowlist forbids a new root PHP entry).
NO private configuration or user memories. The feature is NOT activated without a later approved genuine
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
    "KiComEngramNativeMemoryRoute.php",
    "KiComEngramFirstPartyHostBridge.php",
]
ASSET = "engram-review-client.js"


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def build(dest: Path) -> dict:
    files, original = host_probe.read_verified_source()
    with tempfile.TemporaryDirectory(prefix="kicom-engram-disabled-") as tmp:
        host_probe.patch_candidate(files, Path(tmp) / "candidate")
    host_probe.patch_version(files)
    # Integrate ONLY a narrowly scoped branch in the existing native-allowlisted
    # api.php. Without a separately preloaded, trusted host runtime callback it
    # returns 404 BEFORE reading request body or constructing private storage.
    api = files["api.php"]
    anchor = b"header('Content-Type: text/plain; charset=utf-8');"
    if api.count(anchor) != 1:
        raise RuntimeError("KiCom api.php trust-boundary anchor changed")
    dispatch = b"""/* Engram native memory: no host-injected private trust => 404; no DEV bearer override. */
if (strtoupper((string)($_GET['q']??'')) === 'ENGRAM_MEMORY') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    if (!function_exists('kicomEngramServerRuntime')) {
        http_response_code(404);
        echo '{"ok":false,"code":"ENGRAM_MEMORY_UNAVAILABLE"}';
        exit;
    }
    try {
        // The ONLY accepted runtime comes from a separately reviewed, trusted
        // PHP server bootstrap, not $_GET/$_POST/$_SERVER or GitHub/Slack.
        $runtime=kicomEngramServerRuntime();
        if (!is_array($runtime) || ($runtime['enabled']??null)!==true) {
            throw new RuntimeException('ENGRAM_DISABLED');
        }
        require_once __DIR__.'/modules/engram/KiComEngramNativeMemoryRoute.php';
        $body=file_get_contents('php://input',false,null,0,16385);
        if (!is_string($body)) throw new RuntimeException('ENGRAM_BODY_UNAVAILABLE');
        $response=KiComEngramNativeMemoryRoute::handle($_SERVER,$body,$runtime);
        foreach (($response['headers']??[]) as $key=>$value) {
            if (is_string($key) && is_string($value)) header($key.': '.$value);
        }
        http_response_code((int)($response['http_status']??503));
        echo json_encode($response['body']??['ok'=>false,'code'=>'ENGRAM_UNAVAILABLE'],
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        http_response_code(404);
        echo '{"ok":false,"code":"ENGRAM_MEMORY_UNAVAILABLE"}';
    }
    exit;
}

"""
    files["api.php"] = api.replace(anchor, dispatch+anchor, 1)
    # First-party review branch: original admin PHP session + independently
    # signed WebAuthn; no anonymous or DEV-token-only approval issuance.
    review_dispatch = b"""/* Engram review requires ORIGINAL logged-in KiCom admin PHP session,
   trusted host runtime AND a fresh same-owner signed WebAuthn assertion. */
if (strtoupper((string)($_GET['q']??'')) === 'ENGRAM_REVIEW') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    if (!function_exists('kicomEngramServerRuntime')) {
        http_response_code(404);
        echo '{"ok":false,"code":"ENGRAM_REVIEW_UNAVAILABLE"}';
        exit;
    }
    try {
        if (session_status()!==PHP_SESSION_ACTIVE) session_start();
        $runtime=kicomEngramServerRuntime();
        if (!is_array($runtime) || ($runtime['review_enabled']??null)!==true) {
            throw new RuntimeException('ENGRAM_REVIEW_DISABLED');
        }
        require_once __DIR__.'/modules/engram/KiComEngramFirstPartyHostBridge.php';
        $body=file_get_contents('php://input',false,null,0,16385);
        if (!is_string($body)) throw new RuntimeException('ENGRAM_BODY_UNAVAILABLE');
        $response=KiComEngramFirstPartyHostBridge::reviewApi(
            $runtime,$_SESSION,session_id(),$_SERVER,$body);
        foreach (($response['headers']??[]) as $key=>$value) {
            if (is_string($key) && is_string($value)) header($key.': '.$value);
        }
        http_response_code((int)($response['http_status']??503));
        echo json_encode($response['body']??['ok'=>false,'code'=>'ENGRAM_REVIEW_UNAVAILABLE'],
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        http_response_code(404);
        echo '{"ok":false,"code":"ENGRAM_REVIEW_UNAVAILABLE"}';
    }
    exit;
}

"""
    files["api.php"] = files["api.php"].replace(anchor,review_dispatch+anchor,1)
    # Bind review creation/rendering to KiCom's original authenticated admin
    # session and existing CSRF gate, without introducing a public PHP file.
    admin = files["admin.php"]
    admin_anchor = b"if(isset($_GET['logout'])){session_destroy();header('Location: admin.php');exit;}"
    if admin.count(admin_anchor)!=1:
        raise RuntimeError("Original KiCom admin auth/session anchor changed")
    admin_review = b"""
if (isset($_GET['engram_review'])) {
    header('Cache-Control: no-store, private');
    if (empty($_SESSION['admin']) || !function_exists('kicomEngramServerRuntime')) {
        http_response_code(404); echo 'Engram review unavailable'; exit;
    }
    try {
        csrf(); // Existing admin CSRF initialization, never a client flag.
        $runtime=kicomEngramServerRuntime();
        if (!is_array($runtime) || ($runtime['review_enabled']??null)!==true) {
            throw new RuntimeException('ENGRAM_REVIEW_DISABLED');
        }
        require_once __DIR__.'/modules/engram/KiComEngramFirstPartyHostBridge.php';
        $result=KiComEngramFirstPartyHostBridge::adminPage(
            $runtime,$_SESSION,session_id(),$_SERVER,$_GET,$_POST);
        header('Content-Type: '.$result['content_type']);
        http_response_code((int)$result['status']);
        echo $result['body'];
    } catch (Throwable $error) {
        http_response_code(404); echo 'Engram review unavailable';
    }
    exit;
}
"""
    files["admin.php"]=admin.replace(admin_anchor,admin_anchor+admin_review,1)
    module_manifest = json.loads(files["genome/modules.json"])
    old_paths = {m["path"] for m in module_manifest["modules"]}
    if any(name.startswith("modules/engram/") for name in files):
        raise RuntimeError("Unrecognized original Engram code in trusted R3")

    additions: dict[str, bytes] = {}
    for name in MEMORY_MODULES:
        raw = (BASE / name).read_bytes()
        if not raw.startswith(b"<?php"):
            raise RuntimeError(f"Missing PHP module header: {name}")
        if name == "KiComEngramWebAuthnApprovalController.php":
            source_path = b"__DIR__.'/../../../source/0.9.26-r3/modules/dev/PasskeyBridge.php'"
            deployed_path = b"__DIR__.'/../dev/PasskeyBridge.php'"
            if raw.count(source_path) != 1:
                raise RuntimeError("Original PasskeyBridge development path changed; refuse to package")
            raw = raw.replace(source_path, deployed_path)
        if b"source/0.9.26-r3/" in raw:
            raise RuntimeError(f"Development-only source path leaked into deployed module: {name}")
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
    for probe in ("KiComEngramDevPathHandler.php", "KiComEngramPrivatePathProbe.php"):
        probe_path = "modules/dev/" + probe
        if probe_path not in files:
            raise RuntimeError("Missing verified DEV-only probe source")
        additions[probe_path] = files[probe_path]
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
                "role": "dev-only-probe" if rel.startswith("modules/dev/")
                else ("engram-inert-source" if rel.endswith(".php") else "engram-inert-asset"),
                "auto_heal": True,
            })
    if "lib.php" not in changed or "genome/modules.json" not in changed:
        raise RuntimeError("Version or module manifest not advanced")
    for critical in ("index.php", "guardian.php", "recovery.php", ".htaccess"):
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
        "native_memory_api_branch_registered": True,
        "native_memory_api_default_http_status": 404,
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
