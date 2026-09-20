# KiCom Engram — DEV-09 exact-consent ingestion gate (isolated synthetic)

Date: 2026-09-20. Continuation of DEV-08 under `development/0.9.27/COLLABORATION-PROTOCOL.md` and `TASK-ENGRAM-PRIVATE-MEMORY.md`. Public repository contains only technical code, synthetic fixtures, CI references and this checkpoint. Do NOT commit real memories, original conversations, private backups/DBs, user identifiers, credentials, OTP, private retrieval results or runtime secrets.

## Live and pinned baseline

Re-read KiCom live BOOTSTRAP + PROJECT_STATE/ARCHITECTURE/PROTOCOL/DECISIONS/CHANGELOG/NEXT. Live baseline continues to report **0.9.26**. Last predecessor: DEV-08, executable code SHA `0b087cdc1840fc605a35568459fe03f154270c5a`, CI 35505289673 / job 106063940386, 125 synthetic checks. No KiCom production or private host modification in this cycle.

## Implemented, synthetic-only

New `development/0.9.27/engram/KiComEngramIngestionGate.php` wraps `KiComEngramStore::create` but is NOT an HTTP endpoint, authenticator, approval issuer or bulk importer. It takes two FUTURE TRUSTED SERVER-SIDE callbacks: `trustedIdentity()` to supply verified subject and allowed namespaces (never request-provided subject), and `consumeApproval(exactBinding)` to atomically consume a separately granted, authenticated, one-use exact-record approval. These are explicitly external assumptions requiring a reviewed real KiCom adapter. No grant is minted by entering a namespace or saying `sensitivity=ordinary`. Any missing, revoked, malformed or mismatched identity/approval fails closed. Binding includes subject, namespace, kind, exact content SHA-256, provenance kind, provenance reference SHA-256, and ordinary sensitivity classification.

Entry fields are allowlisted and exact. No automatic chat ingestion, unverified checkpoint ingestion or blanket summary permissions. Only `explicit_user` and `approved_summary` provenance candidates are allowed, each still needs record-specific trusted approval. Source references are bounded opaque note/summary identifiers, not URLs or raw chats. Sensitive-classified entries are refused until a separately reviewed policy exists. Conservative recognizable secret/OTP/token/private-key, query-bearing URL and payment-card patterns are rejected rather than silently redacted. The detector is purposely incomplete: it is NOT a general PII, consent, secret or medical/financial data classifier; a false-negative or a dishonest `ordinary` tag is possible. Thus it MUST NOT process real personal memory until human classification, safe redaction, deletion/retention and trusted consent plumbing are proven.

New `test-engram-ingestion.php` contains **only synthetic examples**: no approval/no DB write; one-use exact grant; replay denied; post-grant body substitution denied; no attacker-specified subject; cross-namespace/cross-subject denied; revoked identity; sensitive or unapproved provenance denied; untrusted URL source reference denied; several synthetic recognizable secret shapes rejected; invalid UTF-8/empty/oversized payload rejected; unauthorized external instruction cannot approve itself, and separately approved external text is still stored as isolated inert data, not executed as instructions. Tests check the callback receives only hashes/binding metadata, not the raw body. The existing CI driver invokes this new suite.

## Verified complete CI

Tested executable HEAD SHA: **`3adc626a2d084be98f6ebc2fde9241e8c738d729`**.
GitHub Actions `KiCom private Engram DEV` run **35505457069**, job **106064379793**, conclusion **success**. Original KiCom 0.9.26-R3 release/source-manifest preflight and existing source/test driver PHP lint passed. All suite markers: `KICOM_ENGRAM_TESTS_PASSED=49`; `KICOM_ENGRAM_PATH_PROBE_TESTS_PASSED=23`; `KICOM_ENGRAM_DEV_ROUTE_TESTS_PASSED=23`; `KICOM_ENGRAM_ENDPOINT_TESTS_PASSED=14`; `KICOM_ENGRAM_INTEGRITY_TESTS_PASSED=16`; `KICOM_ENGRAM_INGEST_TESTS_PASSED=29` = **154 synthetic checks**.
Proof: https://github.com/cschymura/kicom-update-mirror/actions/runs/35505457069

## Explicit remaining deployment and privacy gates

1. The trusted identity/atomic consent callback is ONLY a synthetic fixture contract. No real WebAuthn/user identity-bound approval issuer/receipt storage, approval audit, revocation, source provenance attestation, or host-side activation was implemented. Exact SHA binds bytes, but a privileged attacker who can mint approvals is outside this stand-alone helper's trust model. A `true` consent callback supplied by untrusted code would make this gate useless; never expose it directly through a public entry point.
2. No private memory retrieval/update API connected to a live authorized chat/connector. `KiComEngramStore` retains all past revisions, and backup snapshots retain withdrawn contents. No true erasure/retention of all backups, SQLite WAL/temp, archived copies and remote replicas is yet verified. Do not claim full DEV-3 milestone completed.
3. Private host directory mode 0700 and 404 on selected canary URLs do NOT prove isolation from an alternate default host or another same-UID PHP app. No actual PHP-SAPI identity, open_basedir, canonical alias inventory or safe install/run/rollback proof yet. Keep `engram-private` OUT of public deployment targets. Neither this ingestion gate nor the earlier DEV endpoint candidate has been installed on the live host.
4. Next internal DEV: design/test independently authenticated, narrowly scoped and atomically consumable consent receipts with record-specific digest binding; add consent audit metadata with no raw memory/secret logging; design complete deletion/retention and backup invalidation. Prepare real-host PHP cross-app boundary assessment before any operator-approved controlled install. No automatic import of chat archives or personal records.

This documentation commit is not another executable test; use the pinned tested SHA and CI run above as evidence.
