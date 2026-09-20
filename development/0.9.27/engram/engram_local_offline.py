#!/usr/bin/env python3
"""Engram synthetic-only, offline operator-PC key tool and encrypted transfer check.

Python 3.11+, PyNaCl 1.5.0 (free). NO network operations. The PC-held
Curve25519 secret key is never printed, uploaded or stored in public GitHub.
DO NOT use this synthetic CLI as a production personal-memory exporter.
"""
from __future__ import annotations
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import sys

try:
    from nacl.public import PrivateKey, SealedBox
    from nacl.exceptions import CryptoError
except ImportError as exc:
    raise SystemExit("PyNaCl fehlt. Lokal und kostenlos installieren: python3 -m pip install PyNaCl==1.5.0") from exc

FORMAT = "engram-synthetic-sealed-v1"
MAX_BUNDLE_BYTES = 4 * 1024 * 1024
EXPECTED_FIELDS = ["format", "recipient_public_sha256", "snapshot_sha256",
                   "snapshot_bytes", "revision_count", "ciphertext_b64"]
HASH_HEX = re.compile(r"^[0-9a-f]{64}$")
SYNTHETIC_PREFIX = "ENGRAM_SYNTHETIC_ONLY::"


def private_file(path: Path, data: bytes) -> None:
    """Exclusive creation only; no overwriting an existing private key or backup."""
    if path.is_symlink() or not path.parent.is_dir():
        raise ValueError("Zielpfad/übergeordnetes Verzeichnis ungültig")
    descriptor = os.open(str(path), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        if hasattr(os, "fchmod"):
            os.fchmod(descriptor, 0o600)
        with os.fdopen(descriptor, "wb", closefd=False) as f:
            written = f.write(data)
            f.flush()
            os.fsync(f.fileno())
            if written != len(data):
                raise OSError("Unvollständiger Dateischreibvorgang")
    finally:
        os.close(descriptor)


def keygen(path: Path) -> dict:
    if path.exists() or path.is_symlink():
        raise ValueError("Schlüsseldatei existiert bereits; niemals überschreiben")
    private = PrivateKey.generate()
    private_file(path, bytes(private))
    public = bytes(private.public_key)
    return {
        "key_created": True,
        "public_key_hex": public.hex(),
        "public_key_sha256": hashlib.sha256(public).hexdigest(),
        "private_key_exported": False,
    }


def _decode_json(data: bytes) -> dict:
    def unique_pairs(pairs: list[tuple[str, object]]) -> dict:
        output: dict = {}
        for k, v in pairs:
            if k in output:
                raise ValueError("Doppelte JSON-Felder nicht erlaubt")
            output[k] = v
        return output
    obj = json.loads(data.decode("utf-8"), object_pairs_hook=unique_pairs)
    if not isinstance(obj, dict) or list(obj.keys()) != EXPECTED_FIELDS:
        raise ValueError("Unbekanntes oder unvollständiges Bundle-Format")
    return obj


def _revision_hash(row: sqlite3.Row) -> str:
    fields = ("subject", "namespace", "id", "revision", "kind", "body",
              "source_kind", "source_ref", "entry_state", "previous_hash")
    values = [row[field] for field in fields]
    # PHP json_encode(..., JSON_UNESCAPED_UNICODE) escapes / by default.
    canonical = json.dumps(values, ensure_ascii=False, separators=(",", ":")).replace("/", r"\/")
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def check_synthetic_sqlite(data: bytes) -> int:
    """Deserialize to memory only. Never write plaintext SQLite onto the PC."""
    database = sqlite3.connect(":memory:")
    try:
        if not hasattr(database, "deserialize"):
            raise ValueError("Lokales Python-SQLite unterstützt keine speicherinterne Prüfung")
        database.deserialize(data)
        database.row_factory = sqlite3.Row
        ok = database.execute("PRAGMA quick_check").fetchall()
        if len(ok) != 1 or ok[0][0] != "ok":
            raise ValueError("SQLite-Strukturprüfung fehlgeschlagen")
        cursor = database.execute(
            "SELECT subject,namespace,id,revision,kind,body,source_kind,source_ref,"
            "entry_state,previous_hash,revision_hash "
            "FROM engram_revisions ORDER BY subject,namespace,id,revision"
        )
        count = 0
        previous_key = None
        previous_revision = 0
        previous_digest = "0" * 64
        previous_state = ""
        for row in cursor:
            count += 1
            if count > 100000:
                raise ValueError("Unzulässige Anzahl von Revisionen")
            if (row["subject"], row["namespace"], row["id"]) != previous_key:
                previous_key = (row["subject"], row["namespace"], row["id"])
                previous_revision = 0
                previous_digest = "0" * 64
                previous_state = ""
            if (row["subject"] != "synthetic-subject"
                or row["namespace"] != "synthetic-project"
                or row["source_kind"] != "synthetic_test"
                or not isinstance(row["body"], str)
                or not row["body"].startswith(SYNTHETIC_PREFIX)
                or type(row["revision"]) is not int
                or row["revision"] != previous_revision + 1
                or row["previous_hash"] != previous_digest
                or previous_state == "withdrawn"
                or row["entry_state"] not in ("active", "withdrawn")
                or not isinstance(row["revision_hash"], str)
                or not HASH_HEX.fullmatch(row["revision_hash"])
                or _revision_hash(row) != row["revision_hash"]):
                raise ValueError("Unzulässige oder manipulierte synthetische Revisionsdaten")
            previous_revision = row["revision"]
            previous_digest = row["revision_hash"]
            previous_state = row["entry_state"]
        if count != 2:
            raise ValueError("Unerwartete Anzahl synthetischer Datensätze")
        return count
    finally:
        database.close()


def inspect_bundle(keyfile: Path, bundlefile: Path, expected_bundle_sha256: str) -> dict:
    if not HASH_HEX.fullmatch(expected_bundle_sha256):
        raise ValueError("Unabhängig notierter Bundle-Hash fehlt oder ist ungültig")
    if keyfile.is_symlink() or not keyfile.is_file() or bundlefile.is_symlink() or not bundlefile.is_file():
        raise ValueError("Schlüssel/Bundle muss eine reguläre Datei ohne symbolischen Link sein")
    if bundlefile.stat().st_size > MAX_BUNDLE_BYTES:
        raise ValueError("Synthetisches Bundle überschreitet die Maximalgröße")
    raw = bundlefile.read_bytes()
    if hashlib.sha256(raw).hexdigest() != expected_bundle_sha256:
        raise ValueError("Bundle stimmt nicht mit dem unabhängig notierten Hash überein")
    secret = keyfile.read_bytes()
    if len(secret) != 32:
        raise ValueError("Lokaler privater Schlüssel ungültig")
    key = PrivateKey(secret)
    fields = _decode_json(raw)
    public_sha = hashlib.sha256(bytes(key.public_key)).hexdigest()
    if (fields["format"] != FORMAT
        or fields["recipient_public_sha256"] != public_sha
        or not isinstance(fields["snapshot_sha256"], str)
        or not HASH_HEX.fullmatch(fields["snapshot_sha256"])
        or type(fields["snapshot_bytes"]) is not int
        or not 1 <= fields["snapshot_bytes"] <= 2097152
        or fields["revision_count"] != 2
        or not isinstance(fields["ciphertext_b64"], str)):
        raise ValueError("Falscher Empfänger oder unzulässige synthetische Bündelmetadaten")
    cipher = base64.b64decode(fields["ciphertext_b64"], validate=True)
    if len(cipher) != fields["snapshot_bytes"] + 48:
        raise ValueError("Verschlüsseltes Bundle hat eine ungültige Länge")
    try:
        plain = SealedBox(key).decrypt(cipher)
    except CryptoError as exc:
        raise ValueError("Entschlüsselung fehlgeschlagen") from exc
    if (len(plain) != fields["snapshot_bytes"]
        or hashlib.sha256(plain).hexdigest() != fields["snapshot_sha256"]):
        raise ValueError("Entschlüsselte Daten entsprechen nicht dem Snapshot-Hash")
    revisions = check_synthetic_sqlite(plain)
    return {
        "encrypted_transfer_verified": True,
        "synthetic_revision_chain_verified": revisions == 2,
        "revision_count": revisions,
        "sender_authenticated": False,
        "real_memory_import_enabled": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Engram: rein synthetischer, lokaler Offline-Test")
    commands = parser.add_subparsers(dest="command", required=True)
    generate = commands.add_parser("generate-key")
    generate.add_argument("--private-key", required=True, type=Path)
    inspect = commands.add_parser("inspect-synthetic")
    inspect.add_argument("--private-key", required=True, type=Path)
    inspect.add_argument("--bundle", required=True, type=Path)
    inspect.add_argument("--expected-bundle-sha256", required=True)
    args = parser.parse_args()
    try:
        result = (keygen(args.private_key) if args.command == "generate-key"
                  else inspect_bundle(args.private_key, args.bundle, args.expected_bundle_sha256))
    except (OSError, ValueError, sqlite3.Error, json.JSONDecodeError) as exc:
        # No private path or decrypted file contents in normal output.
        print(json.dumps({"ok": False, "error": type(exc).__name__}), file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
