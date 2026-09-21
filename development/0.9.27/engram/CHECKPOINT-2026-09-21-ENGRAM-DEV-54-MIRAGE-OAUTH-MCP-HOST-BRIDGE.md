# Mirage Engram DEV-54 — OAuth bearer connected to private MCP host reader

Date: 2026-09-21. Current operator-confirmed KiCom production version: 0.9.31, healthy and MCP inactive. Base checkpoint DEV-53 `92b2c9e0641d4a11d845c21cd2233c17d3801a19`. This milestone is DEV-only; no live deployment or config mutation.

## Concrete change

Added `development/0.9.27/engram/KiComEngramOAuthMcpHostBridge.php` and `test-engram-oauth-mcp-host-bridge.php` with a dedicated CI workflow. The first-party bridge checks HTTPS/POST/host/protocol and trusted operator-owned runtime, private file permissions, activation state, original current owner registry, then resolves a currently valid OAuth token from PRIVATE `mirage-oauth.sqlite` through the DEV-51 OAuth token store. It sends only the server-verified connector identity to DEV-46 MCP protocol/DEV-44 runtime gate. It **does not** use the provisional DEV-47 static token JSON, does not create OAuth/activation DB files on HTTP, does not publish a PHP endpoint and cannot enable KiCom's existing 0.9.31 inactive scaffold.

In isolated synthetic host fixture, original project-scoped private SQLite memory + preexisting activation table + owner registry + explicitly approved S256 code -> OAuth token -> full MCP initialize/tools/list/tools/call -> private memory read succeeded. No write tool; owner/host binding, owner revocation and token revocation enforced.

## Exact test proof

Code/test/workflow commit `3e65b36bfc287c6ae712f22618841ee677e6c813`. GitHub Actions run **35589563001**, job **106300619184**, conclusion **success**; 11 PHP syntax checks and **26/26 synthetic OAuth→MCP→SQLite integration assertions** passed. See https://github.com/cschymura/kicom-update-mirror/actions/runs/35589563001 . Actual All-inkl/ChatGPT/HTTP test NOT performed.

## Actual remaining work

The signed original KiCom Passkey→OAuth grant logic DEV-52 and OAuth discovery/token facade DEV-53 are already CI-green separately, but a real original KiCom `admin.php?engram_oauth=1` authorize/login/consent page and HTTP route, `.well-known` discovery routes, token endpoint and replacement of 0.9.31 MCP bridge are NOT packaged or installed. Real ChatGPT callback URI and preferred client identification need to be taken from the actual connection screen (no invented fixed `/connector/oauth/callback` endpoint); current DEV-51 sample is only a synthetic fixture. Actual host vhost/UID/backup review and separate operator permission are pending. Do not present this milestone as a live ChatGPT-plugin connection or actual cross-chat recall. Continue with the first-party authorize page plus real signed WebAuthn and a native update built from the EXACT operator-installed 0.9.31 package, not public GitHub main 0.9.26.
