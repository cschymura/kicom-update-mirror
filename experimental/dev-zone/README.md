# KiCom Development Zone v1

This candidate separates development authority from production authority.

## Goal

After one strong human authentication (normally a passkey), KiCom issues a DEV-scoped session that is practical for iterative work:

- absolute lifetime: 7 days
- idle lifetime: 24 hours
- token does not rotate per request
- one token may be reused across normal DEV requests
- token is stored server-side only as SHA-256
- the token may be transported through approved development tooling because it has no production/RED/kernel authority
- session can be revoked at any time

The DEV session is intentionally incapable of crossing the production boundary.

## Fixed DEV capabilities

- source.snapshot.read
- workspace.read
- workspace.write
- workspace.delete
- workspace.history
- build.begin
- build.patch
- build.status
- build.test
- build.finalize_candidate
- candidate.read
- candidate.discard
- logs.read

Explicitly excluded: production deploy, self-update install, kernel, recovery, auth administration and secrets.

## Intended runtime API

The runtime integration should expose a separate `/dev-api.php` or equivalent DEV router. DEV credentials should preferably be supplied through headers (`X-KiCom-Dev-Session`, `X-KiCom-Dev-Token`), with form/JSON body fallback for clients that cannot set headers. Query-string credentials should be disabled by default.

Suggested operations:

- `DEV_SESSION_STATUS`
- `DEV_SESSION_REVOKE`
- `DEV_SOURCE_SNAPSHOT`
- `DEV_WORKSPACE_READ`
- `DEV_WORKSPACE_WRITE`
- `DEV_WORKSPACE_DELETE`
- `DEV_WORKSPACE_HISTORY`
- `DEV_BUILD_BEGIN`
- `DEV_BUILD_PATCH`
- `DEV_BUILD_STATUS`
- `DEV_BUILD_TEST`
- `DEV_BUILD_FINALIZE_CANDIDATE`
- `DEV_CANDIDATE_DISCARD`
- `DEV_LOG_READ`

Every handler must call `KiComDevSessionManager::authenticate()` with the exact required capability. There must be no catch-all or wildcard capability.

## Passkey flow

1. Browser creates a standard KiCom WebAuthn challenge.
2. User confirms with Face ID / platform passkey.
3. `KiComDevAuthAdapter` serializes verification and DEV session issuance for that challenge.
4. One DEV session ID/token pair is returned.
5. The same token is reused until revoked, idle-expired or absolutely expired.

No rolling-token relay is used inside DEV.

## Production boundary

Promotion from a DEV candidate to live KiCom remains a separate operation. DEV sessions cannot call production/self-update/kernel/recovery paths. A release can therefore be developed and tested without repeated human interruptions, while the final live promotion can still require one transaction-bound approval.

## Non-goals

- no arbitrary shell
- no arbitrary filesystem access outside KiCom development roots
- no secret-store access
- no direct production writes
- no weakening of RED/production/kernel approval semantics

## Integration order

1. Install passkey support.
2. Add DEV session manager and a dedicated DEV router.
3. Map existing workspace/build/test/log primitives behind fixed DEV capabilities.
4. Add trusted-source snapshot export into DEV.
5. Run isolation tests proving DEV credentials cannot reach production handlers.
6. Only then expose the DEV token to automation tooling.
