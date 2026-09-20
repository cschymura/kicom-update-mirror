#!/usr/bin/env python3
"""Build only a REVIEWABLE KiCom 0.9.27 synthetic DEV host-probe release candidate.

Not an installer or uploader. Do not publish to an automatic update feed.
Exact R3 parent source and ZIP are rehashed. Private operator config is NEVER
included. Engram route remains INERT until separately provisioned with a fixed,
trusted, server-only config through KiCom's established protected boundary.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

BASE_ARCHIVE_SHA256 = "6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f"
BASE_GENOME_ID = "kicom-0.9.26-g25r3"
TARGET_GENOME_ID = "kicom-0.9.27-g26-engram-probe"
TARGET_VERSION = "0.9.27"
TARGET_PATHS = [
    "modules/dev/DevSession.php",
    "modules/dev/DevRouter.php",
    "modules/dev/DevHttpAdapter.php",
    "modules/dev/DevEndpoint.php",
    "modules/dev/KiComEngramDevPathHandler.php",
    "modules/dev/KiComEngramPrivatePathProbe.php",
]
MODULE_ADDITIONS = [
    "modules/dev/KiComEngramPrivatePathProbe.php",
    "modules/dev/KiComEngramDevPathHandler.php",
]
SOURCE_DIR = Path("source/0.9.26-r3")
DEV_DIR = Path("development/0.9.27/engram")
ARCHIVE = Path("releases/0.9.26/KiCom-0.9.26-R3.zip")


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def load_manifest(root: Path) -> dict[str, str]:
    entries: dict[str, str] = {}
    raw = (root / "MANIFEST.sha256").read_text()
    for line in raw.splitlines():
        m = re.fullmatch(r"([0-9a-f]{64})  (.+)", line)
        if not m:
            raise RuntimeError("Invalid source manifest")
        digest, rel = m.groups()
        if rel in entries or rel.startswith("/") or ".." in Path(rel).parts:
            raise RuntimeError("Invalid source manifest path")
        entries[rel] = digest
    if not entries:
        raise RuntimeError("Empty source manifest")
    return entries


def read_verified_source() -> tuple[dict[str, bytes], dict]:
    if sha(ARCHIVE.read_bytes()) != BASE_ARCHIVE_SHA256:
        raise RuntimeError("Original verified R3 release archive does not match expected SHA-256")
    entries = load_manifest(SOURCE_DIR)
    actual = {}
    for rel, expected in entries.items():
        path = SOURCE_DIR / rel
        if path.is_symlink() or not path.is_file():
            raise RuntimeError("Original source file missing or symlinked")
        body = path.read_bytes()
        if sha(body) != expected:
            raise RuntimeError("Original R3 source manifest SHA mismatch")
        actual[rel] = body
    genome = json.loads(actual["genome/genome.json"])
    if genome["id"] != BASE_GENOME_ID or genome["generation"] != 25 or genome["version"] != "0.9.26":
        raise RuntimeError("Unexpected original genome identity")
    if genome["parent"] != "kicom-0.9.25-g24":
        raise RuntimeError("Unexpected original parent genome")
    return actual, genome


def patch_candidate(files: dict[str, bytes], candidate: Path) -> None:
    candidate.mkdir(mode=0o700)
    originals = SOURCE_DIR / "modules/dev"
    script = (
        f"require {json.dumps(str((DEV_DIR / 'KiComEngramDevCandidatePatcher.php').resolve()))};"
        f"KiComEngramDevCandidatePatcher::generate({json.dumps(str(originals.resolve()))},"
        f"{json.dumps(str(candidate.resolve()))});"
    )
    subprocess.run(["php", "-r", script], check=True, stdout=subprocess.DEVNULL)
    for name in [
        "DevSession.php", "DevRouter.php", "DevHttpAdapter.php", "DevEndpoint.php",
        "KiComEngramDevPathHandler.php", "KiComEngramPrivatePathProbe.php",
    ]:
        path = candidate / name
        if not path.is_file() or path.is_symlink():
            raise RuntimeError("Missing generated trusted DEV candidate")
        files["modules/dev/" + name] = path.read_bytes()
    if files["modules/dev/DevHttpAdapter.php"] != (SOURCE_DIR / "modules/dev/DevHttpAdapter.php").read_bytes():
        raise RuntimeError("DEV HTTP adapter unexpectedly modified")


def patch_version(files: dict[str, bytes]) -> None:
    source = files["lib.php"].decode("utf-8")
    before = "const KICOM_VERSION = '0.9.26';"
    if source.count(before) != 1:
        raise RuntimeError("Original KICOM_VERSION anchor missing or ambiguous")
    files["lib.php"] = source.replace(before, "const KICOM_VERSION = '0.9.27';").encode("utf-8")


def patch_genome(files: dict[str, bytes], base_genome: dict) -> None:
    genome = json.loads(json.dumps(base_genome))
    genome["id"] = TARGET_GENOME_ID
    genome["version"] = TARGET_VERSION
    genome["parent"] = BASE_GENOME_ID
    genome["generation"] = 26
    genome["mutation_reason"] = "isolated-dev-engram-synthetic-path-probe-inert-until-trusted-config"
    # No change to kernel revision, invariants, healthchecks, mutable paths,
    # production entrypoints, recovery code or any actual project memory.
    components = genome["components"]
    by_path = {component["path"]: component for component in components}
    for path in TARGET_PATHS:
        if path in by_path:
            by_path[path]["sha256"] = sha(files[path])
        else:
            if path not in MODULE_ADDITIONS:
                raise RuntimeError("Unexpected new genome component")
            components.append({
                "path": path,
                "sha256": sha(files[path]),
                "role": "dev-engram-private-path-probe",
                "auto_heal": True,
            })
    # Explicitly register only isolated DEV modules for the trusted loader.
    modules = json.loads(files["genome/modules.json"])
    if modules["schema"] != 1 or not isinstance(modules["modules"], list):
        raise RuntimeError("Unexpected original trusted module manifest")
    existing = {entry["path"] for entry in modules["modules"]}
    for path in MODULE_ADDITIONS:
        if path in existing:
            raise RuntimeError("New module unexpectedly exists in original loader")
        modules["modules"].append({"path": path, "enabled": True})
    files["genome/modules.json"] = (json.dumps(modules, ensure_ascii=False, indent=2) + "\n").encode()
    by_path["genome/modules.json"]["sha256"] = sha(files["genome/modules.json"])
    # lib.php changes ONLY the version constant, but its genome digest MUST
    # still be advanced, or the trusted KiCom verifier rejects the package.
    by_path["lib.php"]["sha256"] = sha(files["lib.php"])
    files["genome/genome.json"] = (json.dumps(genome, ensure_ascii=False, indent=2) + "\n").encode()


def package(files: dict[str, bytes], destination: Path) -> dict:
    forbidden = [
        path for path in files if path.startswith("var/")
        and path != "var/.htaccess"
        or path.startswith("stage/") and path != "stage/.htaccess"
    ]
    if forbidden:
        raise RuntimeError("Private runtime state would enter the release candidate")
    manifest = "".join(f"{sha(body)}  {name}\n" for name, body in sorted(files.items())).encode()
    all_files = dict(files)
    all_files["MANIFEST.sha256"] = manifest
    destination.parent.mkdir(parents=True, exist_ok=True)
    if destination.exists() or destination.is_symlink():
        raise RuntimeError("Refuse overwrite of an existing staged release candidate")
    with zipfile.ZipFile(destination, "x", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for name, body in sorted(all_files.items()):
            zi = zipfile.ZipInfo(name, date_time=(2026, 9, 20, 12, 0, 0))
            zi.compress_type = zipfile.ZIP_DEFLATED
            zi.external_attr = (0o100600 << 16)
            z.writestr(zi, body, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    return {
        "artifact": destination.name,
        "sha256": sha(destination.read_bytes()),
        "parent_release_sha256": BASE_ARCHIVE_SHA256,
        "source_genome": BASE_GENOME_ID,
        "candidate_genome": TARGET_GENOME_ID,
        "version": TARGET_VERSION,
        "changed_paths": sorted([p for p in TARGET_PATHS if
                                 p not in load_manifest(SOURCE_DIR) or
                                 files[p] != (SOURCE_DIR / p).read_bytes()]),
        "private_config_included": False,
        "real_memory_ingestion_enabled": False,
        "installed": False,
        "published_to_update_feed": False,
        "human_approval_required": True,
    }


def main() -> int:
    if len(sys.argv) != 2:
        raise RuntimeError("Usage: build_staging_host_probe.py /absolute/path/REVIEW-CANDIDATE.zip")
    dest = Path(sys.argv[1])
    if not dest.is_absolute() or dest.suffix != ".zip":
        raise RuntimeError("Output must be a new absolute .zip path")
    files, original_genome = read_verified_source()
    with tempfile.TemporaryDirectory(prefix="kicom-engram-offline-candidate-") as tmp:
        patch_candidate(files, Path(tmp) / "dev")
    patch_version(files)
    patch_genome(files, original_genome)
    record = package(files, dest)
    print(json.dumps(record, sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (RuntimeError, OSError, ValueError, subprocess.CalledProcessError) as e:
        print("ENGRAM_STAGING_BUILD_DENIED=" + type(e).__name__, file=sys.stderr)
        raise SystemExit(1)
