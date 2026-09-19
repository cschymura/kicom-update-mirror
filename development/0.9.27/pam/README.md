# KiCom 0.9.27 PAM — development candidate (not installed)

Status: isolated 0.9.27 development modules and CI only. On 2026-09-19, live KiCom was verified on 0.9.26 with genome kicom-0.9.26-g25r3, healthy/trusted/LKG OK, drift=unknown=0, SQLite quick_check=ok and no pending update. The separately published R3 ZIP has SHA-256 6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f. Live genome identity is NOT itself evidence of which exact ZIP bytes the updater installed. Previous package and release history remain retained. PAM 0.9.27 is NOT yet installed.

## Modules

- KiComPam.php: sourced append-only observations with TTL; stable idempotency, internal-only work leases, append-only outcomes, persistent checkpoints. Expired leases enter NEEDS_RECONCILIATION and cannot be retried until the actual target state has been checked and an evidence-hashed resolution is recorded. Accepts an existing PDO SQLite connection; creates only new pam_* tables. No network, execution, genome mutation or permission grants.
- KiComPamKclAdapter.php: accepts raw responses from four exact read-only endpoints (HELLO, GENOME_STATUS, SQLITE_STATUS, UPDATE_STATUS), maps observations, creates a semantic checkpoint that ignores ephemeral request IDs, and queues only internal release-identity review for a RED pending update. It never queues or executes a production installation.
- test.php, test-kcl.php and test-persistence.php: isolated SQLite, strict KCL parser, WAL crash/restart, integrity and snapshot recovery tests (32 + 22 + 13 checks). Installed 0.9.26-g25r3 and empty pending-state observations are covered by the KCL tests.
- KiComPamReleaseProof.php: exact, read-only identity verification of the existing trusted pending metadata and the actual stored ZIP bytes; rejects same-version but different hashes, filename inconsistencies, tampering and symlinks. Does not stage, install or authorize an update.
- KiComPamReadOnlyCycle.php: first bounded observe -> internal read-only action -> evidence-hashed result loop, with explicit health gates, stable SHA-bound action identity and no external action executor. A cached observation never grants authority.
- KiComPamSnapshotOrder.php and KiComPamRecoveryGate.php: fail-closed read-only snapshot-order and recovery preflight; independently verify native KiCom snapshot ID, metadata, file SHA-256 and full SQLite integrity. Legacy same-second random-suffix snapshots are deliberately treated as ambiguous; these modules do not restore any database and have NOT replaced KiCom's native production recovery.
- KiComPamSnapshotSequencer.php: isolated append-only sequential ledger with advisory file locking around a trusted native snapshot writer, per-entry SHA-bound identity, PHP fsync on new entry and cross-restart verification. Refuses legacy/orphan inventories BEFORE invoking a writer, detects orphan .sqlite bytes even without a JSON manifest and rejects symlinked lock files. **Not deployed; fsync of the entry does not establish directory-entry durability or an independent trust anchor. Every native writer must use the same lock before this can inform recovery.**
- KiComPamRecoveryPreflight.php: new development-only, read-only pre-quarantine decision across exact verified legacy and sequenced inventories. Both paths explicitly return restore_permitted=false and automatic_recovery_permitted=false; mixed legacy/sequence state, ambiguous ordering and missing independent journal anchoring never authorize recovery.
- KiComPamHighWaterVerifier.php: bounded read-only comparison of an externally supplied, PRE-AUTHENTICATED high-water claim against the exact sequencer head, native snapshot bytes and full local inventory. Detects truncation of an otherwise internally self-consistent journal. It DOES NOT authenticate the claim; passing a PHP array is not an independent anchor or recovery permission. An independently maintained and verified high-water publisher/provider is still missing.
- test-r3-callers.php: regression-enforced exact R3 native snapshot/recovery caller graph (daily maintenance, pre/post evolution, manual KCL snapshot, automatic/manual heal). Detects newly added direct writer/repair call sites that would bypass a future shared-lock integration.
- test-snapshot-order.php, test-sequencer.php and test-high-water.php: 11 legacy-order tests, 17 sequencing/crash/orphan/early-write-denial tests and 13 high-water/rollback/evidence tests. Current isolated total: 32 core + 22 KCL + 13 persistence + 11 snapshot-order + 17 sequencer + 13 high-water = 108 tests. Native R3 CI adds 10 caller-inventory and 61 integration tests; check each immutable per-run CI report and its TESTED_SHA before making claims.
- Research and tool-selection agreement: development/0.9.27/RESEARCH-AND-TOOLS-POLICY.md; official/upstream, university, authority and primary OSS sources have priority. Research and connected plugins never change action authorization.
- status/latest-ci.txt: most recently persisted PAM unit/KCL/persistence/snapshot-order CI outcome; its trigger SHA must match the tested code.
- test-r3-runtime.php and status/r3-runtime-ci.txt: separate CI verifies the exact R3 ZIP and manifest, then boots an isolated R3 runtime with pre-upgrade canonical-memory seeds, uses its actual SQLite PDO, extends it with PAM v2, exercises the pending-package proof against two different 0.9.26 ZIP files and executes the first bounded read-only PAM cycle. The unchanged native KiCom SQLite health/snapshot is verified. These are disposable CI fixtures, not a production installation, live backup, or evidence of which ZIP KiCom has currently staged.

## Explicit non-capabilities

The proof module is retained for historical 0.9.25->0.9.26 package transitions and for future generalization, but must not be invoked on the installed 0.9.26 state without an eligible pending update. The proof module can only use KiCom's existing, server-local pending/package-path helpers. It has no HTTP, webhook, admin, secret, staging, installation or approval interface. The read-only cycle consumes KCL evidence already fetched by a trusted caller from four fixed endpoints. Production actions stay under the unchanged KiCom updater and its exact transaction-bound authorization. The tested cycle is designed for the 0.9.25-to-0.9.26 R3 case; it is not yet a general task executor.

## Unpublished schema revision

PAM development schema is now v2 to represent NEEDS_RECONCILIATION. Existing v1 prototype databases are deliberately rejected rather than silently or destructively rewritten; an explicit backed-up and tested v1-to-v2 migration must be developed if such persisted prototype data are found. This does not migrate the existing KiCom operational-memory schema.

## Integration gates — not yet completed

1. Verify the exact SHA-256 of the pending KiCom release after feed refresh. Version equality alone does not establish release identity. Use the normal verifier and protected RED production authorization, including pre-write backup, post-write health and rollback.
2. DONE (isolated): PAM v2 has passed the exact 0.9.26-R3 source manifest/ZIP check and integration on R3's native SQLite connection and native snapshot. Still required: test the real deployment environment, existing live SQLite data compatibility, and any actual migration/backup before production use. A test against an isolated copy is not a live migration.
3. Pin all new executable files and module definitions to the verified 0.9.27 genome manifest before promotion. Use the existing internal allowlisted execution boundary; never offer arbitrary filesystem, SQL, shell or remote URL capabilities.
4. Scheduled development loop: load latest checkpoint; capture fresh read-only evidence; create or find an idempotent internal task; claim atomically; independently re-check authorization at the real execution boundary; act via existing KiCom tools; record an evidence-hashed result; re-observe and checkpoint. An expired lease permits reconciliation, not blind replay of uncertain side effects.
5. Protected external actions remain blocked until the existing human-approval and exact target/version/package-SHA checks pass; PAM memory is never an authority source.
6. Only after verified promotion: check backup, health, full SQLite integrity, genome trust/LKG, drift and unknown counts, then update canonical memory.
7. DONE (R3 source inventory + isolated read-only preflight only): the exact lib.php/index.php snapshot and recovery caller graph is enforced by test-r3-callers.php. KiComPamRecoveryPreflight.php rejects ambiguous legacy and mixed legacy/sequence inventories without touching the native DB or quarantine. NOT DONE: integrate a shared trusted lock across every writer, durable publication, an independently anchored high-water record and actual native recovery preflight BEFORE its quarantine/restore operation. The existing R3 kicomSqliteLatestSnapshot() still sorts random-suffixed, second-resolution filenames and is not yet fixed.
8. DO NOT PROMOTE: a tested comparison against a supplied high-water claim is NOT a deployed independent anchor. Specify and test a separate append-only, protected trust-root publisher, exact per-snapshot sequence transaction, directory-entry durability, orphan reconciliation and first-writer migration from legacy before any recovery wiring. Current fail-closed checks must not be interpreted as a proven power-loss-safe production recovery system.

Run in an isolated development environment with PHP 8.2+ and PDO SQLite:
    php -l development/0.9.27/pam/KiComPam.php
    php -l development/0.9.27/pam/KiComPamKclAdapter.php
    php development/0.9.27/pam/test.php
    php development/0.9.27/pam/test-kcl.php
    php development/0.9.27/pam/test-persistence.php
    php development/0.9.27/pam/test-snapshot-order.php
    php development/0.9.27/pam/test-sequencer.php
    php development/0.9.27/pam/test-high-water.php
    # R3 integration is run by .github/workflows/test-0927-pam-r3.yml on a disposable runtime clone

Privacy: store evidence fingerprints, not credentials, OTPs or raw remote content. Preserve history instead of hard-deleting observations and action outcomes.
