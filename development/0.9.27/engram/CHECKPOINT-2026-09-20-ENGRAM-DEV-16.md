# KiCom Engram — DEV-16 external Ed25519 trust-anchor verifier (synthetic)

Date: 2026-09-20. Follows DEV-15. User authorized autonomous isolated development until a real operator decision/action is required. Re-read KiCom canonical BOOTSTRAP/PROJECT_STATE/ARCHITECTURE/PROTOCOL/DECISIONS/CHANGELOG/NEXT at session start; live reports 0.9.26. Public GitHub holds technical source and synthetic tests ONLY; NEVER actual memory, chats, private DBs/backups, private signing keys, real anchor receipts, host paths/configuration, credentials, OTP, sessions or private retrieval data.

## Executable changes

- New `KiComEngramAnchorVerifier.php` is a READ-ONLY offline/dev verifier. It NEVER generates/stores any real private key, signs real data, issues approval, updates a database or creates a web route. It requires a trusted, independently provisioned Ed25519 public key, expected opaque store ID, monotonic minimum receipt sequence and trusted clock; NONE may originate in request parameters, source text, mirrored data or an attacker-editable manifest. It validates exact versioned canonical receipt envelope/payload format, detached signature, scope, receipt issuance/optional expiry, generation manifest/digest and optional immediate parent digest. Return contains metadata only, no memory text/private paths/raw signature.
- `KiComEngramMirrorSet::inspect()` exposes only the manifest format (v1/v2) and v2 parent SHA-256 as bounded metadata. `inspectSignedMirror()` checks cryptographically signed receipt, actual manifest SHA/file status, and binding between signed parent digest and actual v2 manifest fields. For v1 the parent field must be null. This does NOT authenticate the parent generation itself or make an anchored receipt current without independently protected minimum-sequence policy.
- New `test-engram-anchor.php` generates ephemeral random synthetic Ed25519 key pairs IN MEMORY within CI only (not committed or logged). Tests valid v1/v2 signed anchors; scope separation, wrong public key, digest/signature tampering, algorithm downgrade, missing/extra fields, temporal bounds, independently protected sequence floor (rollback refusal), mismatched signed parent metadata, nonexisting/modified manifest, no raw memory/paths or signing secrets in result. The synthetic signing secret is zeroed during teardown.
- Existing R3 original SHA/source preflight and prior Engram suites remain unchanged. No live endpoint or private host code installed.

## Verified executable CI

Tested executable SHA **`27e872399991bdc4f9719bb2bc57650a65dde2fc`**.
GitHub Actions `KiCom private Engram DEV` run **35507249773**, job **106069043592**, conclusion **success**. Existing protected original KiCom 0.9.26-R3 release/source manifest preflight passed; PHP syntax and synthetic suite passed.
Suite markers: main 49 + path 23 + DEV route 23 + endpoint 14 + integrity 16 + ingestion 29 + mirror 46 + exception-fault 45 + SIGKILL 44 + concurrency 8 + external-anchor 22 = **319 synthetic checks**.
Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35507249773

## Remaining operator-controlled barriers

- NO independently protected real signing key/public trust root, verified protected monotonic sequence floor, off-host replica, real host access restrictions, signed receipt issuance policy, generation promotion approval, or secure key recovery/rotation plan. Self-signing from the mirror host with a key stored under the same compromised UID would defeat the claimed independence. SHA-256 alone, a synthetic ephemeral CI key and a copied receipt never certify real integrity or off-host protection.
- Historical snapshots contain withdrawn revisions; complete retention/erasure across live SQLite WAL/SHM/temp, every immutable generation, all backups, archives, off-host copies and independent receipts is NOT yet implemented. Separate real user classification/consent and host isolation/recovery checks remain unverified. No real memory may be ingested or public private-folder deployment set.
- Next internal DEV if no operator decision yet: add a restrictive synthetic retention/erasure inventory and explicit hold/approval semantics that FAIL CLOSED on incomplete replica lists; never delete private real memories based on synthetic code. The real operator needs to decide actual independent backup/signing location, key custody, acceptable retention and approve a controlled host filesystem/PHP identity probe before installation. The KiCom protected update/kernel/production boundaries remain unchanged.

Documentation-only checkpoint does not change pinned executable tested SHA above.
