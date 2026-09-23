# Mirage DEV-78 — consolidate one release, repair confirmed OAuth display-hint incompatibility
Date: 2026-09-23. Development-only. **Do not install partial sources**.

## Up-to-date baseline
- Parent PROD: KiCom 0.9.37, authenticated cross-chat read of synthetic project memory reported and proven in earlier workflow. Recheck live before any release claim.
- Night run DEV-76: isolated synthetic integrated OAuth/MCP/write-grant/private SQLite tests passed (10/10); no live write operators.
- Night run DEV-77 (GitHub tested SHA 3eaf8b7498d2b3b48990aa26230c37ea00600a10): proposed rotating refresh-token continuity passed 13/13 synthetic checks, but is NOT bound to native OAuth HTTP endpoint, nor does it establish ChatGPT will use refresh. Last night report: development/0.9.27/engram/night-reports/2026-09-23/MIRAGE-20260923T065923+0200-DEV77.json.
- Christoph's single consolidated native-package instruction remains binding: one install candidate including the night work and a targeted, testable new-chat/new-device OAuth fix; if that ONE operator-approved live test fails, stop auto-redesign and review the relevant auth/security paths with Christoph.

## Concrete newly reproduced OAuth incompatibility
The previously captured ChatGPT authorization request included `ui_locales=de-DE` in addition to the exact 8 security-critical OAuth request fields. At the time, the operator had to remove that harmless language hint manually to reach KiCom consent. On exact native 0.9.37 source, KiComEngramOAuthAuthorizeHttp::handle GET passes all query keys to KiComEngramOAuthTransactions::begin, which calls an exact-key validator. Thus a valid otherwise-authorized GET with `ui_locales` is rejected with blank 404. This is a verified server-side protocol mismatch. It does NOT prove this was the sole cause of the later failed mobile reauthorization; ChatGPT app-level confirmation is outside KiCom.

## DEV-78 source and safe integration design
- New developer module: `development/0.9.27/engram/dev78/KiComEngramOAuthOptionalParams.php`: strips ONLY well-formed optional `ui_locales`, after original first-party session gate, before original strict transaction validation. Unknown parameters remain untouched and rejected. It NEVER rewrites OAuth client, redirect, state, PKCE, scope, resource, owner, consent or passkey. Does not touch token/MCP POST.
- Targeted native integration instruction: `dev78/APPLY-TO-SINGLE-NATIVE-RELEASE.patch.txt`: require new module and use `authorizationQuery($query)` only inside GET new-authorization branch of `KiComEngramOAuthAuthorizeHttp`.
- Tests: `dev78/test-oauth-optional-params.php`, optional exact parent source set via `KICOM_PARENT_PACKAGE_DIR`; CI `.github/workflows/test-kicom-dev78-oauth-display.yml` for standalone test. A local test of identical normalization logic AGAINST THE EXACT extracted original 0.9.37 OAuthTransactions source passed 17/17 including confirmation that original exact-key validator rejects `ui_locales` and accepts normalized values; source/type syntax clean. The committed standalone regression suite/CI status must be checked separately, not assumed green.
- Development branch: `work/kicom-engram-dev78-consolidated`; HEAD at time of this note before checkpoint `81c6c5c7d13e8f26c21e3b53105bf2ef7379857a`. No complete native ZIP, new server installation, schema migration, token issuance or new-device success is claimed here.

## Next (do not split into additional install candidates)
1. Integrate exact DEV-77 refresh continuity into native private OAuth schema provisioning, token metadata, authorization-code exchange and refresh grant endpoint (honor actual ChatGPT support and revocation; do not silently upgrade existing tokens).
2. Integrate DEV-78 GET `ui_locales` compatibility without weakening strict original consent/session/PKCE/client/redirect checks.
3. Integrate DEV-76 three mutation operators into the same native package with actual independently approved write scope, then test real SQLite/OAuth/MCP end-to-end and read regression. Existing active memory text remains canonical.
4. Native package/Genome/recovery/rollback/checksum checks and synthetic browser/HTTP OAuth error-path tests. Keep optional FTS5 semantic-adjacent index off by default; embedding model capability has not been proven.
5. Present ONE fully verified whole-package artifact and obtain separate operator approval before ONE real new-chat/new-device test. If failure persists, report exact server-vs-platform step and jointly review code with Christoph instead of more automatic security adjustments.
