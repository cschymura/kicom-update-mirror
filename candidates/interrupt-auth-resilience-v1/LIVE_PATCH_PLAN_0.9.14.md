# KiCom 0.9.14 → Interrupt/Auth Resilience v5 — isolated build patch plan

This plan is bound to the live source hashes recorded in `LIVE_INTEGRATION_0.9.14.json`. Live KiCom remains authoritative. Apply only to an isolated non-executable Fast Build. Never patch production files directly.

## Preconditions

- `BOOTSTRAP` still reports runtime `0.9.14` or the plan is explicitly rebased.
- Live source hashes match `LIVE_INTEGRATION_0.9.14.json`.
- Canonical DECISION D024 exists; confirmed DECISIONS SHA after write: `48bbda6275a0441870e0ce5f1362adcb021e4b4336a55e930acbfccc6e3aa532`.
- Unknown RED pending `0.9.15` remains untouched. Do **not** finalize a new update while it would overwrite `pending.json`.
- Every mutating build request uses the current rolling token and records the returned next token immediately.
- After each build patch, use the returned target SHA as the next `base_sha256`; never guess future SHAs.
- A gateway denial/lost response is ambiguous. Do not blindly repeat a build mutation; query build status/current SHA first when transport permits.

## Patch group A — `living.php`

Live base SHA: `dc6420812118d31076384642ee2d7a8dc334a246885f8be84650ef0c52123391`

1. Rename the existing function declaration only:
   - find: `function kicomAutonomySessionConsume(string $id,string $token): array {`
   - replace: `function kicomAutonomySessionConsumeLegacyV5(string $id,string $token): array {`
2. Insert the explicit promote-core functions from `PROMOTION_MANIFEST.json` into `living.php` at one stable function-boundary anchor. Strip candidate `<?php`, `declare(strict_types=1)` and local `require_once` lines when embedding.
3. Add the compatibility bridge under the canonical name:
   - if no deferred `client_request_id` context exists: call `kicomAutonomySessionConsumeLegacyV5()` exactly, preserving legacy behavior;
   - if a deferred context exists: call `kicomAutonomySessionConsumeResilientV3(..., client_request_id)`.
4. Include the read-only helpers:
   - `kicomAuthApprovalStatusReadV1`
   - `kicomUpdatePendingInspectV1`
   - `kicomSourceManifestStatusV1`
5. PHP/content validation must remain green after every step. The build is non-executable, so an intermediate semantic gap is acceptable only until the immediately following wrapper insertion; checkpoint it explicitly.

### Living invariants

- No change to `kicomTotpVerifyConsume*` semantics.
- No change to critical `kicomAuthApprovalExecute` current-counter requirement.
- No second standing recovery bearer.
- Legacy clients without `client_request_id` retain the current rolling-token behavior.
- Resilient clients bind the previous-token fallback to the same `client_request_id` only.

## Patch group B — `index.php`

Live base SHA before any index patch: `b798947e5601d943108e01592590e87b941ba03b3a065c27ad399a16e1b2bd54`

1. Hook central `out(array $lines,int $status=200)`:
   - before emitting, if a deferred guard context exists, persist exact `{status, lines}` response;
   - clear context before any secondary error path;
   - never suppress the original response if replay persistence fails.
2. Replace `requireAutonomySession(string $rid)` with backward-compatible preflight:
   - read `client_request_id` from request;
   - no client id => legacy path;
   - fresh id => guard claim, then existing session consume bridge;
   - REPLAY => emit exact previously stored status/lines without action execution;
   - IN_FLIGHT/UNCERTAIN => explicit recovery error; never execute.
3. Add one preflight hook before `AUTONOMY_TX_COMMIT` and one before `AUTONOMY_CB_EXEC`. Their nested calls then reach the bridged `kicomAutonomySessionConsume()`.
4. Change `AUTH_SESSION_OPEN` only when `client_request_id` is supplied:
   - with client id => `kicomSessionOpenIdempotentV2(code, client_request_id)`;
   - without client id => existing `kicomAutonomySessionOpen(code)` unchanged.
5. Add read-only routes:
   - `AUTH_APPROVAL_STATUS&approval_id=...&binding_sha256=...`
   - `UPDATE_PENDING_INSPECT`
   - `SOURCE_MANIFEST_STATUS`
6. Existing `FACT request_id` remains the server-generated request id. `client_request_id` is a separate caller-supplied idempotency key.

### GET rotation map verified on live 0.9.14

21 cases through `requireAutonomySession()`:

`AUTONOMY_READ_LEASE`, `AUTONOMY_SOURCE_LIST`, `AUTONOMY_SOURCE_READ`, `AUTONOMY_UPLOAD_BEGIN`, `AUTONOMY_UPLOAD_CHUNK`, `AUTONOMY_UPLOAD_FINISH`, `AUTONOMY_COMPACT_BATCH`, `AUTONOMY_MEMORY_PATCH`, `AUTONOMY_WORKSPACE_PATCH`, `AUTONOMY_BUILD_BEGIN`, `AUTONOMY_BUILD_PATCH`, `AUTONOMY_BUILD_STATUS`, `AUTONOMY_BUILD_PREPARE_RELEASE`, `AUTONOMY_BUILD_FINALIZE`, `AUTONOMY_DEPLOY`, `AUTONOMY_DEPLOY_ROLLBACK`, `AUTONOMY_TEST_HTTP`, `AUTONOMY_GOAL`, `AUTH_APPROVAL_PREPARE`, `ARCHIVE_BACKFILL`, `PRIMARY_PUBLISH_CURRENT`.

Special rotating GETs requiring explicit preflight: `AUTONOMY_TX_COMMIT`, `AUTONOMY_CB_EXEC`.

Nonrotating special flows must remain nonrotating: TX begin/append/backoff/status/abort and CB begin/validate/status.

## Patch group C — `api.php`

Live base SHA before any API patch: `9c55813ea725e8f1e537094c1c9c22be7e870f72bb8217066c96d029b1e65421`

1. Hook central `apiOut()` using the same deferred completion semantics as `out()`.
2. `AUTONOMY_BATCH`:
   - accept caller `client_request_id` in parsed data;
   - guard fingerprint covers canonicalized batch operations but excludes auth secrets;
   - use bridged session consume;
   - exact response is replayable.
3. `AUTONOMY_UPDATE_UPLOAD`:
   - accept `X-KiCom-Request-Id` or query `client_request_id`;
   - validate bounded content length first;
   - read bounded raw body before token consumption;
   - fingerprint must bind raw body SHA-256 + normalized filename + byte length;
   - then guard/consume/stage exactly once;
   - a duplicate with same id/token/body retrieves the prior response; a different body or filename conflicts.
4. No POST path may expand package size, target allowlists or update authorization.

## Diagnostic routes added by this release

### `AUTH_APPROVAL_STATUS`
Read-only. Requires `approval_id` + exact `binding_sha256`. Returns only action/risk/status/expiry/used_at/result_code. It never executes or authorizes an approval.

### `UPDATE_PENDING_INSPECT`
Read-only. Returns sanitized pending metadata already present in `pending.json`: from/to version, package/manifest/genome hashes, kernel flag, source, risk class/reasons and changed paths. It must never return internal package filesystem paths.

### `SOURCE_MANIFEST_STATUS`
Public read-only hashes only: path, bytes, SHA-256 and aggregate manifest SHA. Never source content. Purpose: cryptographically compare a mirror snapshot to live even when authenticated source transport is unavailable.

## Validation before any release preparation

1. PHP syntax/content validation after every patch.
2. Build status shows only expected files modified.
3. Deliberate normal-action lost-response test:
   - execute once with `client_request_id`;
   - discard client response;
   - retry same id + old token;
   - exact prior response/next token returned;
   - operation count remains one.
4. Different fingerprint with same client id is rejected.
5. Different old token with same client id is rejected.
6. Crash window stays `IN_FLIGHT`, later becomes `UNCERTAIN`, never auto re-executes.
7. Idempotent session-open retry does not consume FreeOTP twice and cannot roll back a later action token.
8. Lost RED execute response is resolved by read-only `AUTH_APPROVAL_STATUS`, not another FreeOTP code.
9. `UPDATE_PENDING_INSPECT` explains the currently unknown 0.9.15 before any new finalize.
10. Genome/manifest verifier passes; drift/unknown remain zero after eventual install.
11. Critical RED/production/kernel approval semantics are unchanged and fresh current-counter TOTP remains mandatory.

## Finalization rule

Do **not** call `AUTONOMY_BUILD_FINALIZE` while the existing unknown RED pending 0.9.15 would be replaced. First inspect/audit 0.9.15. Preserve its package/history; no hard delete.
