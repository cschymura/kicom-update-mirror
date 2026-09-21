# Mirage Engram DEV-52 — real KiCom 0.9.31 WebAuthn -> OAuth consent, CI GREEN

2026-09-21. Based on DEV-51 `cc79b06ca0fa5ff1a77d4d1991c9b5ee970b9a05` and current actual operator-reported KiCom 0.9.31 installation. The full previously produced 0.9.31 ZIP is present in this working turn; its `api.php` SHA-256 `8fc7605a1d895efbeeadb7646b9809c4ded4c05e399ad8fe6a3921177545e3f4`. Original in-package `modules/dev/PasskeyBridge.php` git blob `57023a0681702cf63cefe1f75dc71b0eed7e2cb7` equals the immutable GitHub R3 source byte-for-byte. No production server files or data were edited.

## Actual code

- Hardened `KiComEngramOAuthTransactions.php`: approved code emission now requires BOTH the original admin session ID and the same cryptographically verified/passkey-bound fingerprint, not possession of a browser-visible request ID alone. Added read-only `pending()` returning only the pending client's pinned metadata for owner review. Client/server PKCE and one-use code/token revocation from DEV-51 retained.
- Added `KiComEngramOAuthPasskeyConsent.php`: works through the ORIGINAL KiComPasskeyBridge and KiComEngramPrivateOwnerRegistry. It enforces authenticated original KiCom PHP admin session, CSRF bound to actual `$_SESSION['csrf']`, HTTPS, an original fresh user-verified WebAuthn challenge, transaction ID bound to browser session/CSRF, signature verification via existing KiCom bridge, and current original private owner registry. It burns the browser challenge on actual failed signature/denied consent, binds the successful exact read-only OAuth approval to the owner, and emits one code for the operator-pinned client redirect.
- Added `test-engram-oauth-signed-passkey.php` and dedicated workflow `.github/workflows/test-0927-engram-oauth-signed-passkey.yml`. Test uses an actual synthetic P-256 key pair and real OpenSSL signatures checked by original KiCom verifier; never a mock verified=true flag. Original packaged 0.9.31 passkey PHP blob comparison, immutable R3 archive/source and PHP syntax checks are in CI.

## Tested and corrected

Earlier CI runs 35588187889 and 35588290589 FAILED due to the synthetic test's PHP arrow-function by-value capture (false replay assertion) and an overly broad negative-test exception-code matcher. Both mistakes were corrected; do not report those runs as green.

Final tested code/test/workflow HEAD **`9bb9aa06fd8b98f3993c0b1d9c7091fa944e6baf`**: GitHub Actions run **35588381825**, job **106296952669**, conclusion **SUCCESS**. Original R3 zip/source and 0.9.31 PasskeyBridge git blob verified; five PHP files lint clean; **30/30 OAuth transaction and 18/18 genuine synthetic P-256 signed consent tests passed**. Run: https://github.com/cschymura/kicom-update-mirror/actions/runs/35588381825 .

Additional CSRF-to-real-PHP-session guard implemented thereafter at commit `d5af869e854dc31868a430510340e553043b5a45`; **independently retested and GREEN**: GitHub Actions run **35588532126**, exact tested SHA `d5af869e854dc31868a430510340e553043b5a45`, conclusion `success`. The preceding run 35588381825 remains a separately recorded GREEN for its earlier SHA.

## Exact remaining blocker

DEV-52 is executable development code and green synthetically; it is NOT a complete OAuth HTTP server, installed KiCom update, live OAuth operator approval, real ChatGPT plugin registration, real host/vhost/PHP UID/backup evidence or independent ChatGPT recall. No passkeys/tokens/private memories are stored in GitHub/Slack. Next integrate protected resource and authorization server metadata, 401 challenge and OAuth token/authorize HTTPS handlers with verified original session and first-party consent; use actual 0.9.31 source, then test full HTTP→MCP with OAuth access token. Do not ship another inert update in lieu of the actual connector flow; keep live 0.9.31 unchanged until separate operator approval.
