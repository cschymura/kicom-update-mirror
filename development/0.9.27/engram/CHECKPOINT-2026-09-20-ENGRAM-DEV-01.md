# KiCom Engram private memory — DEV-01 executable checkpoint

Date: 2026-09-20. Project task: `development/0.9.27/engram/TASK-ENGRAM-PRIVATE-MEMORY.md`. Collaboration protocol: `development/0.9.27/COLLABORATION-PROTOCOL.md`.

## Actual changes

- `KiComEngramStore.php`: **standalone DEV-only** non-network SQLite storage class. Requires existing mode-0700 directory outside resolved document root, rejects symlink to storage/database, hardlinked or world-readable preexisting DB. WAL / foreign_keys / synchronous FULL / quick_check. Keeps bounded provenance-bearing append-only revision rows with SHA-256 previous-hash linkage, optimistic concurrency and withdrawal-state revisions. Explicitly scoped subject+namespace literal substring search, bounded limit; no automatic ingest and no public HTTP route. Class is NOT an authenticator: higher-layer subject/namespace MUST be derived from verified KiCom identity, not raw user input. Hash chain is a tamper indicator, **not** a security root against the DB owner.
- `test-engram.php`: exclusively synthetic data, negative security tests and local private-directory SQLite checks. Test SQLite artifacts are cleaned from the CI runner and are not uploaded or committed.
- `.github/workflows/test-0927-engram.yml`: separate GitHub DEV test workflow and unchanged R3 package/source integrity preflight.
- `TASK-ENGRAM-PRIVATE-MEMORY.md`: durable backlog with substantive milestones and private-data exclusions.

## CI evidence

GitHub Actions `KiCom private Engram DEV`, run **35500934612**, job **106052449923**, tested code SHA **bc6b9dd11498f81eb8d5bf64b74eb5a50e57228f**: all job steps completed **successfully**. PHP lint passed on both code and tests. Exact parent R3 package digest `6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f` and source manifest verified unchanged. CI log ended `KICOM_ENGRAM_TESTS_PASSED=30`. Checks cover webroot, nested-webroot, symlink and excessive-permission denials, database-mode and WAL/quick_check, separate subjects and namespaces, literal needle versus SQL/LIKE injection, bounded retrieval, provenance, size/type validation, valid/rejected revision and withdrawal transitions, and DB integrity after negative tests.

Evidence URL: https://github.com/cschymura/kicom-update-mirror/actions/runs/35500934612

## Explicit limitations

**This is not yet a running private KiCom memory service.** No personal or live chat content, tokens, secrets, original SQLite or archived conversation was ingested. No directory was created on the production KiCom host; no claim about its actual host-level security or owner permissions is made. Private data handling requires a separately verified deletion/retention policy, genuine authorized identity-bound caller, independent backup/restore, audited scoped chat retrieval and review of redaction and consent/approval. Explicit user desire to preserve experience does not cancel future deletion/security obligations. An engram is external retrieval, not modification of ChatGPT's underlying model memory.

**Next safe DEV section:** implement and test (a) genuine data deletion/retention including historical revisions and WAL/backups, (b) full independent private backup/restore with WAL consistency and permission checks, and (c) a KiCom-auth-bound local API facade with negative cross-user tests, without opening public unauthenticated endpoints. Only after that assess an authorized private non-web KiCom staging filesystem destination. Keep existing daughter/membrane and production authorization gates untouched.

This documentation-only checkpoint commit is not a new executable code test; the exact SHA above is the tested code version.
