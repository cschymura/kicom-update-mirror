# Mirage DEV-80 — OAuth token-grant dispatch for ONE consolidated KiCom release

2026-09-23. **Development only; no production change or installer.**

## Verified input state
The current DEV-79 branch contained a strict token-form parser; the original native 0.9.37 KiComEngramOAuthHttp::token() still accepts only authorization_code. The separately tested DEV-77 rotating refresh store does NOT, by itself, make ChatGPT reuse a connection. DEV-78 optional ui_locales request handling is prepared but not installed. DEV-76 has synthetic SQLite mutation checks; the three write operators are NOT currently live.

## Actual new source
`dev80/KiComEngramOAuthRefreshDispatch.php`: server-only dispatcher invoked exclusively AFTER original KiCom OAuth HTTP origin/HTTPS/host/method/runtime/content-type gates. Validates exact DEV-79 token form; unchanged authorization_code requests go to the existing KiComEngramOAuthTransactions::exchange(). Strict read-only refresh form routes to DEV-77 KiComEngramOAuthContinuity::rotate() with client_id, connector_id and host_evidence_id supplied ONLY from trusted server configuration. Untrusted caller payload cannot supply owner, namespace, connector or write grant.

`dev80/test_refresh_dispatch.php`: synthetic dispatch tests; stubs stand in for exchange()/rotate() and do NOT prove SQLite issuance/revocation or OpenAI reconnection. Local equivalent source was syntax-checked with PHP 8.4 and passed **12/12** dispatch checks; exact existing DEV-79 parser ran **29/29** in local PHP. The committed GitHub test has more assertions; its separate CI status MUST be verified and cannot be inferred from this checkpoint.

`dev80/NATIVE-HTTP-INTEGRATION.patch.txt`: exact native integration map replacing only the token() post-gate dispatch call in ONE complete eventual release. `.github/workflows/test-kicom-dev80-refresh-dispatch.yml` added. GitHub source and test writes were confirmed; no native ZIP produced.

## Critical release gates
1. PRIVATE explicit operator schema provisioning must install refresh-token table; do not create schema on anonymous HTTP or token requests.
2. Mint initial refresh in SAME database transaction as authorization-code consumption and original access-token issuance. Until atomically implemented, do NOT advertise refresh_token in OAuth discovery or call this feature release-ready.
3. Verify owner/credential/host revocation and refresh-family invalidation, single-use rotation, allowed scope, replay, malformed form and failure rollback against REAL SQLite in CI and native host.
4. Merge DEV-76 real read/write/update/archive OAuth/MCP/SQLite integration with independent engram.write consent, DEV-78 optional display-hint compatibility, and DEV-77 refresh; preserve original functional engram.read and verified original backup/recovery/updater/Genome.
5. Build and verify ONE full native package from exact current production parent. Only after separate Christoph approval make ONE actual new-chat/iPhone OAuth test; if that still fails, STOP automatic auth revisions and conduct joint code/path review, including whether prompt belongs to ChatGPT rather than KiCom.

Neither the latest mobile authorization failure's exclusive cause nor compatibility with a ChatGPT plugin refresh client has yet been demonstrated.
