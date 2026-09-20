# KiCom Engram — DEV-05 verified isolated authenticated path-probe candidate

Date: 2026-09-20. Follow DEV-04 and COLLABORATION-PROTOCOL.md. Public GitHub holds synthetic tests/code/proof only, never private engrams, source chats, tokens, live DBs, backup bytes, secrets, or private retrieval results.

## Verified baseline and scope

Live KiCom BOOTSTRAP reports 0.9.26. Original R3 files under source/0.9.26-r3 remain unmodified and continue to pass the pinned release/source preflight. No KiCom production, DEV runtime, kernel, daughter/membrane, webroot or private host files were installed or changed during this cycle.

## Executable DEV changes

- KiComEngramDevPathHandler.php: a single strict, inert-by-default handler for DEV_ENGRAM_PATH_PROBE, requiring an already-authenticated DEV router result containing the separate engram.path.probe capability; empty payload only, otherwise deny before consulting config. A trusted server-side callback supplies fixed data, backups and webroot paths (not request parameters). The handler calls the existing synthetic private-path probe and returns only booleans or a generic failure, never private content or absolute paths. Missing/invalid config fails closed.
- KiComEngramDevCandidatePatcher.php: offline, hash-pinned generation of isolated test-only candidate DevSession.php, DevRouter.php and unchanged DevHttpAdapter.php copies from exact original R3 source SHA-256 digests. It adds only one separate capability and one fixed operation mapping, hash-checks all inputs before writing any candidate file, refuses a nonempty output directory, and does not modify trusted source or production.
- test-engram-dev-route.php: executes the actual original-derived session manager + router + HTTP adapter in a disposable synthetic PHP test directory. Denies missing/invalid tokens, GET, unknown/protected operations, preexisting sessions without the new capability, request path injection, bad trusted config and revoked credentials; proves the capability-bound synthetic data/backup read/write and cleanup without returning private paths. Tests explicitly do NOT claim to verify a real passkey assertion, all public HTTP aliases, a live host process UID or a productive installation.
- The existing Engram test driver includes the dedicated DEV route suite, without changing the existing workflow itself. New DEV sessions in this isolated candidate include the bounded diagnostic capability; existing sessions do not gain it retroactively. Separate future identity-bound personal-memory read/write capabilities are NOT implemented or granted.

## Pinned CI evidence

**Tested code SHA:** b711bf5ed8825ea26b620511182d98cf5bfa8939

GitHub Actions workflow: KiCom private Engram DEV
Run: **35504227681**; job **106061188205**; result **success**.
Log markers: KICOM_ENGRAM_TESTS_PASSED=49; KICOM_ENGRAM_PATH_PROBE_TESTS_PASSED=11; KICOM_ENGRAM_DEV_ROUTE_TESTS_PASSED=23 — **83 synthetic checks**. Existing original R3 ZIP/source-manifest preflight passed and main PHP source/test-driver lint passed.
Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35504227681

## Unimplemented / next safe steps

The offline router/session candidate is NOT registered in KiCom's real DevEndpoint.php and has NOT been installed on the host. A deployable release requires separately reviewed and pinned first-party DEV endpoint wiring, trusted operator-provisioned filesystem configuration (never supplied via HTTP), checking all host-specific webroots and alternate aliases, and existing KiCom controlled update authorization/verification. A valid DEV token alone must never authorize real memory ingest or reveal private content.

Complete true deletion/retention (past revisions, SQLite WAL/temp, all backup generations and remote copies), independently controlled backup/restore and real-host file/identity checks with synthetic data before introducing any personal engrams. Do not register engram-private as a public deployment target and do not export memories to public GitHub, Slack or Mail.

This checkpoint documentation commit is NOT a new executable test: use the exact tested SHA above for code verification.
