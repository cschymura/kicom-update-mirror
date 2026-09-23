# MIRAGE DEV-84 — Preserve the already working read flow before the active OAuth DB migration

Date: 2026-09-23. **Development-only. No production install or real private-data mutation.** Parent production last confirmed KiCom 0.9.37. Independent synthetic memory retrieval through engram_search was previously proven.

## Concrete release-blocking regression found and fixed
DEV-82's native `KiComEngramOAuthTransactions::exchange()` issued an initial refresh unconditionally. On Christoph's existing ACTIVE, yet-unmigrated private OAuth SQLite, `mirage_oauth_refresh_tokens` does not exist. The first OAuth code exchange after installing such a release would ROLLBACK the whole access-token issuance and make a previously working read login unavailable until an additional admin migration. That violates the one-installation/one-test and continuity goals.

DEV-84 **retains the original native PKCE/client/redirect/consent code checks**, and uses one schema-readiness check inside its existing `BEGIN IMMEDIATE` code-exchange transaction. If the refresh table is absent, the already proven single-use access-token flow completes, returns ONLY the old `engram.read` response, and does NOT create a table from an OAuth HTTP request. After an explicitly authorized additive operator migration, the same native code atomically issues BOTH read access and read refresh in that transaction. An existing malformed refresh table fails CLOSED rather than silently falling back.

## Exact source delivery and replay order
Branch `work/kicom-engram-dev84-read-fallback`, from latest DEV-83 branch at `40e5c08075adcaaf5ed2a37f5e7d7b5e43d67f48`.
- The committed executable `dev84/AFTER-DEV82-legacy-read-fallback.patch` is an **INCREMENTAL patch** for a source tree with the complete DEV-82 `dev82/native-original-oauth-atomic-and-rotating-read-refresh.patch` applied ONCE. DO NOT apply the older DEV-82 initial-only patch or apply two copies of the full refresh patch.
- A complete exact-parent replacement diff was generated locally against the SHA-verified original 0.9.37 ZIP, but has NOT been uploaded to GitHub. Its SHA256 was `48e2c54b16ca79d83172ded678df6106baf59b10faeb4fe637f11fe55afc68bc`. The local incremental patch had SHA256 `fe484c799534214c16c31dedb5d7e261fb352ca98f0a735976dd6972c7eb9701`; re-verify bytes in any subsequent runtime.
- Exact parent ZIP SHA256 `0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7`. GitHub checkpoint file/filename alone is NOT a download link or guarantee the ZIP is mounted in a different runtime.

## Reproducible observed local checks
- Original parent source -> complete DEV-84 patch: `patch -p1 --dry-run` PASS.
- Existing DEV-82 original native source -> incremental DEV-84 patch: `patch -p1` PASS, resulting native source byte-identical to the syntax-checked local DEV-84 source.
- PHP native OAuthTransactions syntax: PASS.
- Actual native `refreshSchemaReady` invoked by reflection with PDO test doubles: 8/8 PASS (legacy missing-schema, present ready schema, malformed present schema, SQL query count, one fallback branch and original commit/rollback). Same test is committed as `dev84/test_read_fallback.php` and requires `KICOM_DEV84_NATIVE_FILE` pointed to the verified staged native source.
- Python sqlite3 **SQL REPLAY of actual SQL extracted verbatim from local DEV-84 PHP source**: 8/8 PASS (old read token persists without auto-DDL, additive operator-created table preserves original token, new access+refresh remains read-only/owner-bound, malformed schema fails, quick_check OK). The Python fixture lives in the current turn's container only, NOT in GitHub.
- **NO real PHP PDO SQLite extension in the local runner**. GitHub CI for this exact DEV-84 patch and real first-party HTTP/ChatGPT new-device flow has NOT been proven. Do not count Python SQL replay or PDO test-double behavior as PHP PDO transaction or live OAuth success.

## Next step before ANY release
1. Run the native DEV-84 PHP test against the applied source; execute real PHP PDO SQLite code+refresh+rollback+old-token regression in compatible CI or non-production All-inkl instance, not on live private records.
2. Merge DEV-83 optional refresh resource form compatibility and exact DEV-82 native admin additive migration into ONE source, preserve old read until migration. The OAuth metadata must not claim the refresh grant as actually ready until server and client support are verified.
3. Complete the still-missing native, separately authorized `engram.write` OAuth consent and MCP write/update/archive integration, plus original-store schema/owner/namespace/isolation and package/genome/update/rollback verification. Disabled FTS5 only if tested; no unsupported embedding promise.
4. ONE complete exact-parent install candidate, separate user approval, ONE independent new-chat/iPhone authentication test; on failure STOP automatic auth revisions and jointly review KiCom-vs-ChatGPT failure with Christoph as developer. No further piecemeal installation.

This change is intended to protect the existing proven working Engram read path and eliminate forced manual migration as a prerequisite for an old-style login; it does NOT by itself make token refresh functional before the explicit active-DB migration.
