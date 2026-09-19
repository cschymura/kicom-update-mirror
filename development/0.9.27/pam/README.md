# KiCom 0.9.27 PAM — development candidate (not installed)

Status: isolated development module and CI only. Live KiCom stays on 0.9.25 until the normal 0.9.26 R3 release process. The GitHub mirror now points to the separate R3 ZIP at SHA-256 6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f. Previous package and release history remain retained.

## Modules

- KiComPam.php: sourced append-only observations with TTL; stable idempotency, internal-only work leases, append-only outcomes, persistent checkpoints. Expired leases enter NEEDS_RECONCILIATION and cannot be retried until the actual target state has been checked and an evidence-hashed resolution is recorded. Accepts an existing PDO SQLite connection; creates only new pam_* tables. No network, execution, genome mutation or permission grants.
- KiComPamKclAdapter.php: accepts raw responses from four exact read-only endpoints (HELLO, GENOME_STATUS, SQLITE_STATUS, UPDATE_STATUS), maps observations, creates a semantic checkpoint that ignores ephemeral request IDs, and queues only internal release-identity review for a RED pending update. It never queues or executes a production installation.
- test.php, test-kcl.php and test-persistence.php: isolated SQLite, strict KCL parser, WAL crash/restart, integrity and snapshot recovery tests (latest full CI result: 32 + 15 + 13 checks).
- status/latest-ci.txt: most recently persisted CI outcome; its trigger SHA must match the tested code, not merely show a previous success.

## Unpublished schema revision

PAM development schema is now v2 to represent NEEDS_RECONCILIATION. Existing v1 prototype databases are deliberately rejected rather than silently or destructively rewritten; an explicit backed-up and tested v1-to-v2 migration must be developed if such persisted prototype data are found. This does not migrate the existing KiCom operational-memory schema.

## Integration gates — not yet completed

1. Verify the exact SHA-256 of the pending KiCom release after feed refresh. Version equality alone does not establish release identity. Use the normal verifier and protected RED production authorization, including pre-write backup, post-write health and rollback.
2. Re-test PAM on an isolated copy of the actual 0.9.26 runtime, including migration, WAL/concurrency and backup/restore. Local unit tests are not evidence of production compatibility.
3. Pin all new executable files and module definitions to the verified 0.9.27 genome manifest before promotion. Use the existing internal allowlisted execution boundary; never offer arbitrary filesystem, SQL, shell or remote URL capabilities.
4. Scheduled development loop: load latest checkpoint; capture fresh read-only evidence; create or find an idempotent internal task; claim atomically; independently re-check authorization at the real execution boundary; act via existing KiCom tools; record an evidence-hashed result; re-observe and checkpoint. An expired lease permits reconciliation, not blind replay of uncertain side effects.
5. Protected external actions remain blocked until the existing human-approval and exact target/version/package-SHA checks pass; PAM memory is never an authority source.
6. Only after verified promotion: check backup, health, full SQLite integrity, genome trust/LKG, drift and unknown counts, then update canonical memory.

Run in an isolated development environment with PHP 8.2+ and PDO SQLite:
    php -l development/0.9.27/pam/KiComPam.php
    php -l development/0.9.27/pam/KiComPamKclAdapter.php
    php development/0.9.27/pam/test.php
    php development/0.9.27/pam/test-kcl.php
    php development/0.9.27/pam/test-persistence.php

Privacy: store evidence fingerprints, not credentials, OTPs or raw remote content. Preserve history instead of hard-deleting observations and action outcomes.
