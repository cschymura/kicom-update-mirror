# Mirage Engram DEV-47 — private bearer-authenticated MCP HTTP transport, CI GREEN

Stand 2026-09-21. Prior checkpoint DEV-46 commit `90b6f9fb12dc1c75a0207ecd638740e8132a0a6b`. Branch `work/kicom-0.9.27-pam`. Checked current branch/Slack before code modifications; 30-minute DEV-47 claim posted.

## Executed code
- `KiComEngramMcpBearerVerifier.php`: hashes a canonical 32-byte-base64url bearer token; matches SHA-256 and short-lived scope in a strictly typed operator-owned private `mirage-mcp-token.json` outside the web root. Requires restrictive private directory and file modes, safe path, exact schema, expiry and connector ID; returns only already verified connector id, credential fingerprint, owner binding, host evidence ID. Does NOT accept owner or privileges from MCP request JSON, issue an access token, enroll or validate a passkey, enable Engram or write any live configuration.
- `KiComEngramMcpHttpTransport.php`: genuine HTTP method/header/status/body handling for a first-party HTTPS POST route; checks HTTPS, exact host, JSON content type, Accept JSON+event-stream, optional first-party Origin, optional MCP version, request bound, bearer credential, then delegates to DEV-46 protocol and DEV-44 runtime gate. Default scaffold remains inactive and unauthorized callers cannot enumerate tools. Missing externally issued/registered token -> 404; no raw tokens logged.
- `test-engram-mcp-http-transport.php`: synthetic host filesystem with 0700 token directory/0600 token record, real SQLite private store and activation fixture. Tests actual PHP HTTP request metadata -> token verification -> MCP JSON-RPC initialize/list/read -> owner-gated store; 29 successful assertions include disabled policy, method/host/content type/origin, expired/revoked/incorrect/mode-insecure token, unsolicited write denied and healthy SQLite.
- Dedicated CI workflow `.github/workflows/test-0927-engram-mcp-http-transport.yml` checks immutable original R3 package/source manifest, PHP lint of nine scripts and synthetic full-stack tests.

## Immutable green evidence

**Code/workflow/test SHA: `599deedbf3a02415f7672200954b5ba290b5236f`.** GitHub Actions run **35579780736**, job **106269716515**: completed `success`. All original R3 integrity checks and 9 PHP lint checks passed; `KICOM_ENGRAM_MCP_HTTP_TESTS_PASSED=29`. Run: https://github.com/cschymura/kicom-update-mirror/actions/runs/35579780736

## Explicit limitations

This is a tested **transport class** accepting real HTTP server metadata, **not yet an installed KiCom route**. No production file was deployed, no private record, activation, All-inkl credential, host policy or passkey changed. The token record is synthetic: no real token was created for Christoph, and a privately issued token is not equivalent to a standards-complete ChatGPT OAuth client registration. Real ChatGPT Work plugin needs its actual supported connection/auth configuration. No actual HTTP network roundtrip on All-inkl or independent ChatGPT-instance recall has been demonstrated. Current 0.9.30 native host-audit ZIP is prepared but was not confirmed installed.

## Next executable step

Integrate the already tested DEV-47 transport in a real first-party KiCom route **default disabled**, with fixed private operator-owned path and existing host loader/owner registry; perform HTTP-level synthetic integration tests through a PHP server, including 404 on inactive 0.9.29/0.9.30 config and no access to private store. Implement an authenticated, passkey-controlled way to issue/revoke the private connector credential (or a compatible OAuth2/PKCE code/authorization server) and test it before requesting a live connector registration. Compare actual operator-installed version before building native follow-up release; don't ask Christoph to repeat his successful 0.9.29 SQLite/passkey tests. A complete vhost/UID/backup isolation review and explicit owner approval still gate actual personal-memory activation.
