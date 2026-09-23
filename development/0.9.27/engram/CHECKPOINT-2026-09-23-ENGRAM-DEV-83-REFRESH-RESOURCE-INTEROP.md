# Mirage DEV-83 — OAuth refresh interoperability, strict audience preservation

Date: 2026-09-23. **DEVELOPMENT ONLY. NO INSTALLATION.**

## Parent and observed result
A full LOCAL staging directory based on the byte-verified KiCom 0.9.37 parent ZIP SHA256 `0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7` currently contains native OAuth transaction refresh staging, explicit first-party admin additive-migration route, narrowly normalized optional `ui_locales` GET, and strict OAuth code/refresh token POST handling from DEV-82. It does NOT yet contain the integrated separately approved WRITE/UPDATE/ARCHIVE MCP and native write OAuth consent, and its current manifest/genome still describe the unchanged parent. Never install this partial staging as a release.

## New concrete compatibility defect fixed locally and saved in GitHub
Earlier DEV-79 strict refresh token form required a `resource` POST parameter on each refresh. OAuth 2 resource indicators are optional at token requests (RFC 8707); a client may omit it during refresh. Requiring an unnecessary field could cause a valid refresh request to fail and trigger another authorization attempt. This is an independently identified compatibility risk, NOT proof it caused the user's actual iPhone issue.

`dev83/KiComEngramOAuthGrantForm.php`: preserves the original SIX required authorization_code fields unchanged, requires pinned client ID for both flows, and allows `resource` to be absent ONLY for refresh_token. If present, it must still match the single server-approved canonical resource exactly. In both cases the private server refresh row, not caller-supplied scope/owner/resource, fixes the token audience and read-only authority. Maintains exact-key rejection, duplicate/invalid-form denial, no silently added write scope.

`dev83/test_oauth_grant_form.php`: extends the prior 29-test suite with refresh-without-resource positive and code-missing-resource negative tests (31 checks total). Added .github/workflows/test-kicom-dev83-refresh-interop.yml; do not claim CI green without independently checking the job.

## ACTUALLY OBSERVED local regression, not CI or live
- Native complete DEVELOPMENT staging: PHP lint **64/64** passed.
- Standalone DEV-83 strict token parser **31/31** passed locally.
- Existing staged authenticated first-party refresh-migration HTTP guard tests **10/10** passed without opening a private DB.
- Native-code static differential regression **12/12** passed: original PKCE/pinned-client code prefix unchanged; exactly four original native files changed and three helper PHP files added in local source; parent no deletions; atomic refresh inside original transaction and rollback; no implicit schema create on token request.
- Python sqlite3 SQL replay **7/7** passed using DDL and INSERT SQL extracted verbatim from native staged PHP source; it is NOT PHP PDO-SQLite integration, native HTTP or actual All-inkl host verification.
- No production installation, token issuance, user-data transfer, or real ChatGPT reconnection was performed. Existing artificial memory READ baseline remains previously proven.

## Next release gate
(1) Integrate DEV-83 parser into ONE full candidate with existing native read-refresh, and test real PHP PDO SQLite issuance/rotation/replay/revocation and actual POST flow; preserve old read tokens during optional operator-admin additive schema preparation. (2) Integrate original native search + all three mutation operators with a distinct genuine engram.write scope and user consent, owner/namespace protections, canonical revisions and exhaustive regressions; not yet done. (3) Create correct version/manifest/genome/recovery/rollback and verify exact whole package before presenting ONE operator-approved install and ONE independent new-chat/iPhone OAuth test. After failed one live test, stop automated auth redesign and review jointly with Christoph, distinguishing ChatGPT platform prompt from KiCom response.

GitHub branch: `work/kicom-engram-dev83-oauth-refresh-interop`.
