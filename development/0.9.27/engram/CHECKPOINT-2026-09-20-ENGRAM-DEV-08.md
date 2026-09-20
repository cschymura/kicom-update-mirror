# KiCom Engram — DEV-08 revision-content and backup-chain integrity

Date: 2026-09-20. Continuation from DEV-07 under COLLABORATION-PROTOCOL.md. Public GitHub contains ONLY code, synthetic tests and non-personal proof metadata; no actual engrams, messages, private DB, backup, filesystem credentials, OTP, DEV session, private host configuration or live paths. No production/host installation performed.

## Canonical baseline and operator state

Re-read live KiCom BOOTSTRAP and all named canonical resources, active version remains **0.9.26**; the installed live Engram integration remains **absent**. Latest previous Engram code was DEV-07 (tested SHA 8b11e56aca0163db1ab10ddb745ebde482720725; 109 synthetic checks). Server-side data and backups were previously provisioned by the operator with mode 0700 and harmless canary at 0600. This is NOT host-side PHP or cross-site isolation attestation. Different website directories under the same hosting account may share an OS effective UID / filesystem authority. Mode 0700 alone cannot isolate two applications running with the same UID. The provider's general domain setup instructions explicitly allow multiple domains in one account with shared KAS/FTP credentials (https://all-inkl.com/wichtig/anleitungen/providerwechsel/bestellung/domainbestellung/anlegen-der-domain-im-kas_118.html). Need actual PHP identity / open_basedir / process and default-host/alias evidence before private live memory can be imported.

## Executable changes

1. `KiComEngramStore::search()` now fetches full rows for internal SHA-256 content/revision verification before returning the original minimal selected fields; a body modified without updating its stored revision hash is never returned. This does NOT prove that a missing query hit is intact or cryptographically authenticate an attacker who can rewrite the whole DB and hashes.
2. New bounded `auditRevisionChain()` checks ALL stored revisions (up to 100,000 revisions in this DEV implementation), ordered by subject, namespace, id, revision. Each chain must start at revision 1, revisions must be consecutive, previous hash must match the immediate predecessor's revision hash, a withdrawn entry must never be followed by a new revision, and every full-row content hash must verify. Public `auditHistory()` reports only a count and boolean; no content or source_ref escapes through the audit API.
3. `backup()` checks the **consistent created snapshot** with full audit before accepting/digesting it. If revision-chain audit fails, only its own newly created incomplete snapshot is removed; existing verified backups remain untouched.
4. `restore()` verifies an independently digest-checked source snapshot's full revision chain prior to any copy, and verifies the restored copy's revision chain and count after opening. Existing no-clobber destination and source permission checks stay in place. A checksum must be independently trusted; SHA-256 stored alongside a modifiable backup is NOT authenticity.
5. New synthetic `test-engram-integrity.php` (invoked from the existing Engram CI driver) verifies clean search projection and history, clean backup/restore, SQLite quick_check remaining OK after logical content tampering, search/audit/backup refusing the tampered body, previous verified snapshot survival, and detection of a deleted historical revision even while the latest entry still passes individual digest validation. No private memory was used.

## Verified CI and exact tested SHA

**Tested executable SHA: `0b087cdc1840fc605a35568459fe03f154270c5a`**.
GitHub Actions: `KiCom private Engram DEV` run **35505289673**, job **106063940386**, conclusion **success**. Original 0.9.26-R3 release archive and original source MANIFEST SHA preflight passed. PHP source/test-driver syntax check passed.
Markers: `KICOM_ENGRAM_TESTS_PASSED=49`, `KICOM_ENGRAM_PATH_PROBE_TESTS_PASSED=23`, `KICOM_ENGRAM_DEV_ROUTE_TESTS_PASSED=23`, `KICOM_ENGRAM_ENDPOINT_TESTS_PASSED=14`, `KICOM_ENGRAM_INTEGRITY_TESTS_PASSED=16` = **125 synthetic checks**.
Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35505289673

## Remaining blockers and next steps

- The new audit is a consistency check, not an independent signature or tamper-proof proof against a fully privileged attacker. It cannot detect deleting a complete final engram, replacing all hashes, hidden backup copies, unexpected HTTP aliases, or another PHP application with the same UID. An independent trusted audit head or operator manifest will be needed before using it for high-assurance provenance.
- A valid offline path probe + 404 on specific tested URLs does not prove complete HTTP isolation, PHP SAPI identity, open_basedir limits or cross-app read denial. Need actual authorized, reviewed first-party host-side probe and provider/default-host mapping; do not register engram-private as a public deployment target.
- Current actual host does not have a verified fixed trusted `kicomEngramDevTrustedConfig()` or the candidate code installed. No memory ingestion, authorized real-user retrieval, backup schedule, purge across copied backups, or real-host restore drill has occurred. Secure deletion/retention and scope/consent gates are required before any personal memory import.
- The 100,000-revision cap is an explicit DEV fail-closed bound, not a production capacity promise; implement a paginated/incremental audit before very large archives.
- Next useful DEV: add separately trusted metadata anchor/manifest for history heads or implement a stricter, testable memory-ingestion consent/redaction boundary, and prepare private-host isolation proof prior to any controlled install. Preserve user-project history by default; handle legally/user-required confidential-data erasure without retaining deleted content in old WAL/backup copies.

This checkpoint is documentation, not a new executable test. Resume from pinned code SHA/run above and re-read current live BOOTSTRAP before any new work.
