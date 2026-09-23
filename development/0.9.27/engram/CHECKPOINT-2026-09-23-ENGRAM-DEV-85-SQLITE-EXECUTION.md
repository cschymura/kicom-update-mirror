# MIRAGE DEV-85 — native OAuth PHP control flow against the actual SQLite engine

Date: 2026-09-23. **DEVELOPMENT ONLY — DO NOT INSTALL OR MIGRATE PRODUCTION.**

## Correct source / reproducible fixture
- Original full KiCom-0.9.37-Mirage-MCP-META-FIX-VOLLSTAENDIG.zip: SHA256 `0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7` (verified in this turn's runtime).
- Original source module `modules/engram/KiComEngramOAuthTransactions.php`: SHA256 `a7b323006e6773325714084c8cb50d843fff24d85da23876feeac3e7c5fc380f`.
- Tested staged native module from the original parent + DEV-82 FULL atomic/rotating patch + DEV-84 incremental legacy-read patch: SHA256 `ffbbac043c43b5697552d90ecf75f5698b303fceb5a0962bad9df0445c7a387e`. Do not apply the superseded DEV-82 initial-only patch or the incremental patch twice.
- Current development branch: `work/kicom-engram-dev85-native-sqlite-regression`, based on DEV-84 checkpoint. New portable regression source `dev85/test_native_sqlite_flow.php` has been committed but MUST be run with `KICOM_DEV85_NATIVE_FILE` pointing to the EXACT staged native module. This repo does not magically contain the conversation-uploaded 0.9.37 parent ZIP. No native source fixture or FFI shim is claimed to be uploaded to GitHub by this checkpoint.

## New actually executed stronger test
The working runtime PHP 8.4.23 has PDO but **lacks ext-pdo_sqlite**. A test-only PHP PDO-compatible adapter backed by the real local libsqlite3.so.0 was built in the conversation container; the executable **actual staged PHP KiComEngramOAuthTransactions class** ran its begin/approve/issueApprovedCode/exchange/verify/refresh/revoke and SQL statements against the genuine SQLite C engine using ONLY artificial owner IDs, tokens and SQLite in-memory state.

The final completed execution returned **23/23 PASS**:
- Old read authorization remains usable when optional refresh table is absent and never runs DDL on a token request.
- Explicit additive schema provisioning preserves pre-existing read token.
- Fresh code exchange atomically issues read access+refresh; original code cannot replay; invalid PKCE does not consume valid code.
- Expired access renews via valid refresh; rotated token is readable; old refresh cannot replay.
- Host/client/connector mismatch, revoked parent, expired refresh all fail.
- Malformed PRESENT refresh schema fails closed, and the failed access insert and consumed code roll back; repairing the table permits the same original code.
- Actual SQLite C-engine PRAGMA quick_check returns ok.

NEGATIVE CONTROL: Running the SAME test against the pre-DEV-84 native DEV-82 staged class failed during the first legacy code exchange with `no such table: mirage_oauth_refresh_tokens`, confirming that DEV-84 specifically fixes the release-blocking regression rather than the test accidentally passing without exercising it.

Local test files (conversation workspace, NOT GitHub): `/mnt/data/mirage-dev85/test_native_oauth_ffi.php` SHA256 `89c5397ad104a02488612bd5b1a016da7793b333bee1629feb7b17a5c4007123` (before the FFI error-message-only change), and `/mnt/data/mirage-dev85/ffi_sqlite_pdo_compat.php` (test-only; no real user records). If they are not actually mounted in a future chat, do NOT pretend they are available or copy their sandbox links from this checkpoint.

### Important distinction
**23 real SQLite C-engine checks with a test-only PDO adapter != native ext-pdo_sqlite driver and != on-host HTTP / platform compatibility.** The committed `dev85/test_native_sqlite_flow.php` is the matching test for true PHP ext-pdo_sqlite; it has NOT yet been independently run in an environment with that PHP extension. The prior DEV-81 GitHub Actions completion was not verified. Do NOT claim native PDO, live mobile reauthorization or release readiness based on the FFI-adapter result.

## Next concrete work
1. Materialize the exact parent from the actual conversation attachment when available, apply full DEV-82 + DEV-84 patches once, verify hash, run the new committed native PDO regression in a PHP build that has ext-pdo_sqlite (e.g. synthetic GitHub CI fixture or isolated non-prod runtime). Also test the original HTTP gates, optional resource-refresh parser and explicit admin migration+backup against a realistic private-schema fixture.
2. Finish actual native MCP write/update/archive plus fresh separate engram.write OAuth/passkey consent (DEV-76 mutation core is not presently installed) and prevent automatic read-token escalation.
3. Assemble ONE complete exact-parent package with manifest/genome/backup/recovery/updater/rollback/regression passes, then obtain Christoph's separate approval for ONE production installation and ONE independent new-chat/iPhone connection test. At one failed live auth test STOP automatic security rewrites and jointly review server vs ChatGPT-platform steps with Christoph.

No production installation, private SQLite mutation, new OAuth scope, paid service or personal data transfer occurred during DEV-85.
