# PR #13 candidate status

Current promote candidate: **Interrupt/Auth Resilience v5**.

## Implemented

- persistent non-secret jobs/checkpoints with CAS revision protection
- atomic pre-execution request claims
- exact AES-256-GCM response replay for the same session + client_request_id + request fingerprint + presented old token
- `IN_PROGRESS` handling that prevents duplicate execution
- `UNCERTAIN` handling: expired in-flight work is never automatically re-executed
- recursive canonical request fingerprints with nested secret stripping
- shared normal-session lock
- request-bound previous-token fallback: an old token cannot authorize a different resilient client_request_id
- legacy-compatible session consume bridge: callers without client_request_id stay on the original KiCom path
- idempotent FreeOTP normal-session open v2 with short exact replay and no secondary recovery bearer
- deferred router guard preserving exact HTTP/KCL response for replay
- read-only `approval_id + binding_sha256` recovery for lost critical-execute responses
- read-only pending-update inspection
- public read-only source-manifest hashes without source content
- strict secret filtering for resumable jobs/checkpoints

## Canonical/live work completed

- DECISION D024 is now confirmed in KiCom canonical memory: chat/stream interruption is a normal operating condition; persist checkpoints, use idempotent authenticated retries, and consume FreeOTP only at real trust boundaries.
- DECISIONS SHA after D024: `48bbda6275a0441870e0ce5f1362adcb021e4b4336a55e930acbfccc6e3aa532`.
- Exact live KiCom 0.9.14 source hashes and token-consumer map were re-read through a nonrotating read lease and recorded in `LIVE_INTEGRATION_0.9.14.json`.
- 21 GET rotators are centralized through `index.php::requireAutonomySession()`.
- special rotating GETs confirmed: `AUTONOMY_TX_COMMIT`, `AUTONOMY_CB_EXEC`.
- rotating POSTs confirmed: `AUTONOMY_UPDATE_UPLOAD`, `AUTONOMY_BATCH`.
- live approval record/execute semantics were re-read and critical current-counter FreeOTP semantics remain unchanged.
- live pending.json format was re-read and confirmed to already contain `risk_reasons` + `changed_paths`.
- exact isolated-build patch sequence is recorded in `LIVE_PATCH_PLAN_0.9.14.md`.

## Deterministic bundle / CI

- promotion bundle is generated directly from `PROMOTION_MANIFEST.json`; superseded files cannot enter through a second hand-maintained list.
- GitHub Actions regression run #52: PASS.
- generated living bundle: 42,750 bytes.
- bundle SHA-256: `8e4cbc006b02d03f07edd4ea2a6db17d755141d54364bf973f44199a70dfad2d`.
- bundle internal SHA check: PASS.
- bundle PHP lint: PASS.
- forbidden/superseded recovery-handle functions absent: PASS.
- required promote symbols present: PASS.
- bundle is now persisted on the candidate branch under `generated/` by the build bot, not only retained as a temporary Actions artifact.

## Superseded history retained but forbidden from promotion

- `session_recovery_v1.php` — rejected because a long-lived recovery handle creates an unnecessary second bearer credential
- v1/v2/v3/v4 intermediate guards/wrappers listed in `PROMOTION_MANIFEST.json`

## Live authoritative state

- KiCom runtime: **0.9.14**.
- Existing unknown self-update pending: **0.9.15 / RED / source autonomy:server-build**.
- 0.9.15 remains untouched; no install/discard/finalize has been performed against it.
- External fetch gateway currently allows public/read-lease operations but intermittently denies authenticated mutation URLs before they reach KiCom.
- Gateway denial is therefore treated as transport failure, never as evidence of a KiCom mutation result; no blind mutation retries.

## Remaining release blockers

1. Inspect/audit the existing RED pending 0.9.15 without deleting its package/history and without overwriting `pending.json`.
2. Obtain a working authenticated mutation transport window and apply the already-prepared v5 bundle/hooks to an isolated non-executable server build only.
3. Run server-side verifier/genome checks plus deliberate lost-response smoke tests.
4. Only then prepare/finalize a release. RED/production/kernel installation remains exact-bound fresh current-counter FreeOTP.
