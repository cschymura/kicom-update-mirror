# Mirage DEV-79 — one-package OAuth refresh HTTP integration: exact grant-form contract

Date 2026-09-23. Active standalone development branch `work/kicom-engram-dev79-token-grant-form` forks directly from DEV-78 checkpoint commit `98b6e85ee0accc74c2df443d39236bc5af625862`. No native release ZIP, no production installation, no OAuth rights expansion.

## Verified baseline and scope
DEV-77 13/13 local/synthetic SQLite token-rotation tests passed, but the production 0.9.37 OAuth HTTP token endpoint handles only authorization_code and advertises no refresh_token grant. DEV-78 ui_locales parameter normalization has 17/17 local regression checks against extracted original OAuthTransactions; it has not yet been released. DEV-76 authenticated MCP mutation controller passed 10/10 CI synthetic checks; write/update/archive are not installed. Auth failure on a fresh iPhone/new ChatGPT chat has not been uniquely diagnosed; token refresh is only one plausible component. Maintain Christoph's explicit decision: ONE whole native package and ONE operator-approved live attempt, otherwise joint code review rather than successive auth updates.

## New actual source, not just planning
- `dev79/KiComEngramOAuthGrantForm.php` implements a strict, server-pinned form discriminator for the exact existing 6-field authorization_code grant and a bounded 4-field refresh_token grant (optional identical engram.read scope). It strips no OAuth security checks, trusts client/resource only when matching server-pinned identities, passes the original code exchange payload through unchanged, and never passes user-supplied scope/owner/writes to the refresh backend.
- `dev79/test_oauth_grant_form.php` contains 29 deterministic assertions: original auth-code contract, valid read-only refresh, optional exact read scope, 26 rejection cases including foreign client, duplicate/missing parameters, scope escalation, control characters, malformed URLs/tokens, owner/namespace/approval claims.
- `.github/workflows/test-kicom-dev79-oauth-grant-form.yml` runs PHP syntax and tests on pushes to this development branch.

The two new PHP files passed `php -l` and 29/29 assertions locally against local PHP 8.4; no pdo_sqlite is installed in this local runner, so this phase DOES NOT prove actual SQLite refresh-token issuance, single-use rotation on the native HTTP endpoint, or ChatGPT reauthorization. The GitHub Actions workflow was added but its remote completion has not been verified in this checkpoint; do not infer CI green from its presence.

## Precise next integration
1. In ONE consolidated native source tree, ensure original operator-approved inactive database preparation explicitly installs the DEV-77 private refresh-token schema. Do not create schema just because an unauthenticated refresh HTTP request arrived.
2. Wire the validated `KiComEngramOAuthGrantForm` through the original HTTPS, host, content-type, origin and trusted-runtime gates in the native OAuth HTTP route. Original auth-code requests must still pass the unchanged exchange() checks; refresh requests call DEV-77 rotate() with pinned connector/client/host evidence only.
3. Atomically issue the initial refresh token from a verified, owner-bound, read-only new access-token row within the original authorization-code transaction; preserve original code rollback if refresh issuance fails. Verify the old read-only tokens still work and never mint write.
4. Advertise refresh_token grant metadata only AFTER real integration. Check issuer response, code+PKCE, token rotation, replay/expiry/revocation, client/connector/host changes, owner withdrawal, concurrency and old engram.read regression with real SQLite on CI.
5. Integrate DEV-76 read/write/update/archive with separate real engram.write grant, DEV-78 ui_locales, and optional disabled FTS5 (only if migration/lifecycle tests pass); run whole-package SHA/Genome/recovery/rollback and exact parent verifier. Present ONE full candidate for Christoph's separate operator approval. Only then one live new-chat/new-device auth test; if it fails stop auth auto-redesign, review jointly with Christoph.

Security note: never upload private tokens, cookies, bearer material, owner personal records or full OAuth callback URLs to GitHub/Slack. No product changes were performed in DEV-79.
