# MIRAGE DEV-82 — Native OAuth read-refresh staging, consolidated original KiCom source

Date 2026-09-23. **DEVELOPMENT STAGING ONLY; DO NOT INSTALL.** Christoph explicitly wants ONE complete native KiCom package containing all four read/write/update/archive operators, the night work, and ONE targeted new-chat/iPhone OAuth correction, not serial partial release ZIPs.

## Exact parent and current staging
Exact original parent mounted for this development session: `KiCom-0.9.37-Mirage-MCP-META-FIX-VOLLSTAENDIG.zip`, original `modules/engram/KiComEngramOAuthTransactions.php` SHA256 `a7b323006e6773325714084c8cb50d843fff24d85da23876feeac3e7c5fc380f`. Production was last confirmed 0.9.37; no productive files or private SQLite were modified in DEV-82.

Unlike isolated DEV-77/79/80/81 helpers, this development step APPLIED changes to a complete local copy of the original native 0.9.37 source tree, retaining the SAME original `KiComEngramOAuthTransactions::exchange` PKCE/client/redirect/code/state validity checks and single-use code transaction. It does not introduce a second independently maintained PKCE validator.

## Actual source changes (all attached as executable exact-parent unified patches)
- `dev82/native-original-oauth-atomic-and-rotating-read-refresh.patch`: supersedes the earlier `dev82/native-original-oauth-atomic-initial-refresh.patch`; apply ONLY the complete newer patch, never both. Original operator-only schema preparation receives a separately callable additive refresh table migration for future active databases. Original `exchange` atomically mints BOTH read access and first refresh token within the existing SQLite transaction; if refresh insert fails, code consumption and access insertion roll back. Native `refresh` uses the original pinned client policy, server database owner+connector+host+scope identity, single-use transactional rotation and parent-access revoked/binding checks. No write scope is minted.
- `dev82/native-original-oauth-http-grant-routing.patch`: preserved original OAuth HTTP HTTPS/host/method/content-type/origin runtime gates; after these gates only, the previously developed strict DEV-79 form parser routes original authorization_code to unchanged native exchange and refresh_token to native refresh. The obsolete second strictForm parser is removed to prevent divergent validation.
- `dev82/native-original-oauth-ui-locales.patch`: original first-party GET now removes only validated optional ui_locales after original admin-session/CSRF/HTTPS guards and before original strict OAuth transaction key validation. This was a reproducible source-side cause of the prior manual URL adjustment; NOT yet proven as exclusive source of iPhone/platform reconnection error.
- New unchanged native helper modules required when assembling full package: `dev78/KiComEngramOAuthOptionalParams.php` and `dev79/KiComEngramOAuthGrantForm.php`, available on ancestral branch and in local staging.
The complete staging tree also contains all unmodified original 0.9.37 native files. No valid new MANIFEST/genome/install version was generated; this is deliberately NOT a downloadable/installable KiCom release.

## Actually observed local tests
- PHP -l passed for all **63** PHP files in staging (the 61 original native PHP files plus the 2 additional helpers).
- Existing DEV-78 normalizer regression **17/17 passed** locally; existing DEV-79 strict token form regression **29/29 passed** locally.
- The first-stage exchange native validator/static comparison returned **9/9**, before the rotating refresh and second helper changed additional paths.
- Python sqlite3 replay of the exact SQLite DDL/INSERT statements extracted from the latest native staged PHP source returned **7/7**: absence of operator-prepared table rolls back code+access; explicit table creation is idempotent; after schema creation both hashed tokens persist in a single transaction with unchanged owner/fingerprint/read scope; PRAGMA quick_check OK.
- A dry run of the native original OAuth exchange patch and ui_locales patch applied to the exact original 0.9.37 sources; PHP lint passed after full local staging.
- These are LOCAL syntax, format and SQL replays, **NOT PHP PDO SQLite integration, real first-party HTTP, native KiCom verifier, OpenAI refresh implementation support or live iPhone proof**. GitHub CI completion for DEV-81 is not yet independently observed; do not claim it passed. No real private memory/token used.

## Release-blocking integration tasks (must NOT claim READY_FOR_RELEASE)
1. Existing production OAuth SQLite is ACTIVE and its refresh table is absent. Original admin inactive-db preparation refuses existing active DB by design. Implement an explicitly operator-controlled **additive safe migration for that existing private active OAuth database**, with its original first-party auth, CSRF, path/permissions and backup/quick_check gates. Do not invoke DDL on a public OAuth/token/MCP request. Ensure original engram.read continues to work until migration is complete.
2. Finish and actually test the native refresh HTTP transport against real PHP PDO SQLite including code+PKCE, initial refresh, expiry, replay, revocation and owner/host mismatch. Add refresh_token to OAuth discovery ONLY when both code/token implementation AND actual private DB readiness are proven.
3. Merge DEV-76 canonical native MCP/SQLite engram_write/update/archive with truly separate engram.write consent/scope and grant. Preserve original verified engram_search; no automatic read-token upgrade. Test store schema/owner/namespace/revision/idempotency/archived exclusion with actual private-schema fixture.
4. Finish original whole-package MANIFEST+Genome+updater+recovery/rollback and real PHP/HTTP integration. No production deployment without separate Christoph approval. After ONE real new-chat/iPhone trial, if failure remains stop automatic OAuth/security code changes and review jointly with Christoph at source-level, distinguishing KiCom vs ChatGPT platform prompts.

## Persistence
Code is preserved in GitHub as exact-parent executable patch files and reusable DEV-78/79 source modules. The full local staging tree in this chat's working container is NOT itself a GitHub source tree; new chat must first locate the exact 0.9.37 parent ZIP and reapply the newer patch plus ui_locales/HTTP patches once, then verify hashes and rerun tests. Do not claim the full integration or valid release archive has been uploaded to GitHub.

No manual step by Christoph is needed to continue development.
