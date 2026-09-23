# MIRAGE DEV-81 — atomic first OAuth access+refresh issuance, tightened refresh revocation

Date: 2026-09-23. This is development source on a new branch from DEV-80, **not a deployable ZIP or productive modification**. Operator requests ONE comprehensive native release, ONE live new-chat/new-device correction attempt only after separate approval; then joint code review on failure.

## Source actually committed
- `dev81/KiComEngramOAuthAtomicExchange.php` copies the original 0.9.37 *strict* authorization-code security contract into an isolated prototype and, in ONE `BEGIN IMMEDIATE` SQLite transaction, consumes the original single-use PKCE code, creates the read-only access token, and creates its linked initial rotating refresh token. If the explicitly prepared private refresh schema is missing, the transaction rolls back both the code consumption and the access-token insert. The caller does not install schema from an unauthenticated token HTTP request. The eventual native release should CONSOLIDATE the prototype with the original validator rather than leave two independent implementations.
- `dev81/test_atomic_exchange.php`: true PDO SQLite in-memory regression fixture matching the ORIGINAL 0.9.37 private OAuth table schemas. Tests atomic rollback before explicit schema setup, idempotent explicit setup, valid access+refresh issuance, token hash storage, owner/credential/scope binding, consumed-code replay denial, PKCE/client/redirect/resource/scope/owner/connector/host negative cases, SQLite quick_check. Synthetic tokens ONLY.
- Existing `dev77/KiComEngramOAuthContinuity.php` **tightened** on this branch: removed both `install()` invocations from issuance and rotation request paths (previous DEV-77 code could create the refresh schema as a side effect of an HTTP token request); added a check that the parent access row exists, is not revoked and has unchanged owner/credential/client/connector/host/scope bindings BEFORE consuming a refresh token.
- Existing `dev77/test-oauth-continuity.php` extended with assertions that a missing refresh schema stays absent after request and that a refresh token linked to a revoked access row fails.
- CI workflow `.github/workflows/test-kicom-dev81-atomic-oauth.yml` created and updated to run both real SQLite scripts after PHP lint on changes to dev77 or dev81.

## Verified vs outstanding
GitHub contents commits: source `5d3ac401a4387773a121f694e1857a4d4c4bd438`; atomic SQLite test `c0cdff1a1c9deeadfe6f80c24e167735f9f1e670`; DEV-77 fix `d2c39d15497bbc4753af45860d9ade830f23d875`; updated tests `a33364f0e16c688ff08d13e34821383077703352`; CI workflow head `2267783754592f9d9b691375835b3bcc6a22b608`. Local PHP 8.4 runner lacks pdo_sqlite and local GitHub egress, so NEW DEV-81 real SQLite test completion has **NOT** been directly observed. The connector's commit-workflow lookup returned no PR-triggered runs; this does not prove CI failure or success. Do NOT advertise a passed DEV-81 test count until run/job logs or an actual local compatible test shows it.

The productive KiCom version was last verified 0.9.37 and synthetic cross-chat READ succeeded; none of the three write operators, DEV-77/78/79/80/81 refresh changes or FTS5 are installed or re-tested live in this work.

## Next strict tasks
1. Verify DEV-81 GitHub Actions job results (or run original scripts in a real PHP pdo_sqlite environment); fix real failures.
2. Consolidate original KiCom OAuthTransactions::exchange and DEV-81 atomic path WITHOUT duplicating security-critical checks; avoid silent write scope upgrades. Keep private refresh schema install behind original explicit authenticated admin preparation, not GET or token requests.
3. Integrate the real HTTP routing from DEV-79/80 with initial atomically issued refresh, revocation + current owner/host binding and appropriate metadata; verify RFC OAuth response/client support. Integrate DEV-78 narrow ui_locales query handling.
4. Integrate DEV-76 real MCP+SQLite write/update/archive with genuinely separately authorized engram.write, preserve previously working engram_search and private data.
5. Build ONE exact-parent complete package after real CI and original native manifest/genome/recovery/rollback checks; request operator installation approval. ONE live new-chat/iPhone test. On failure: stop auto security rewrites and perform joint developer review with Christoph.

No secrets, OAuth callback URLs with tokens, real memory contents or passkeys were included in GitHub files.
