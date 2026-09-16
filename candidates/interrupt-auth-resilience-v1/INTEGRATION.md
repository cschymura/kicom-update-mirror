# KiCom Interrupt/Auth Resilience v1 — runtime integration contract

Candidate only. Live KiCom remains authoritative.

## Load order

Add a trusted resilience component immediately after `living.php` is loaded by `lib.php`.

Recommended runtime split:

- `interrupt_resilience_v1.php` — jobs/checkpoints + idempotent non-secret request receipts
- `session_recovery_v1.php` — recovery-handle provisioning/recovery/status
- `session_consume_resilient_v1.php` — locked rolling-token consumer
- `session_open_resilient_v1.php` — idempotent FreeOTP session-open

All four must be included before any request path invokes them. The release verifier/genome must include every added runtime component.

## Existing call sites that must use the locked consumer

Nearest mirrored 0.9.12 core shows these direct consumers:

1. `index.php::requireAutonomySession()`
2. `living.php::kicomAutonomyTxCommit()`
3. `api.php::AUTONOMY_UPDATE_UPLOAD`
4. `api.php::AUTONOMY_BATCH`

Before promotion, search the exact live 0.9.14+ source again for every `kicomAutonomySessionConsume(` occurrence, including codebook paths added after 0.9.12. Every runtime consumer must either call `kicomAutonomySessionConsumeResilient()` or use the same per-session lock.

## KCL routing

### AUTH_SESSION_OPEN

Backward-compatible behavior:

- with `request_id`: call `kicomSessionOpenIdempotent(code, request_id)`
- without `request_id`: retain legacy session-open or wrap it with recovery provisioning

New successful facts may include:

- session_id
- token / next normal action token
- recovery_handle (sensitive; returned only to the authenticated client, never logged to canonical memory)
- recovery_limit
- replayed=true|false

### AUTH_SESSION_RECOVER

Inputs:

- session_id
- recovery_handle
- request_id (16..80 chars, stable for retry)

Output on success:

- next_token
- absolute/idle expiry
- replayed=true|false

No FreeOTP is consumed because this operation cannot create a session or cross a trust boundary. The original normal session must still be active.

### AUTH_SESSION_RECOVERY_STATUS

Optional read-only endpoint. Returns only configured/count/limit/expiries. Never returns recovery_handle or tokens.

## Critical boundary unchanged

`AUTH_APPROVAL_PREPARE` remains a normal-session operation.

`AUTH_APPROVAL_EXECUTE` remains independent and requires a fresh current-counter FreeOTP code bound to the exact action/version/target/package hash. A recovery handle or recovered normal token never substitutes for this.

## Session-open replay storage

`var/auth/session_open_resilience/master.key`

- 32 random bytes
- mode 0600
- server-only, never returned
- used only to HMAC-bind short-lived open receipts and deterministically reproduce the original already-authorized response

Open receipts expire after 180 seconds and contain no plaintext TOTP/session token/recovery handle.

## Concurrency

Use one lock namespace per normal session: `<session>.recovery.lock`.

Normal rolling-token consumption, recovery and open replay must all serialize on this lock. This prevents concurrent responses from silently overwriting newer token state.

## Interrupt-safe jobs

Long multi-step development should create/update a non-secret job checkpoint after meaningful phases. Job state may include build IDs, SHAs, phases and next action, but never authentication material.

Recommended states:

- RUNNING
- WAITING_HUMAN_APPROVAL
- PAUSED
- COMPLETED
- FAILED

At RED/production/kernel boundaries, checkpoint `WAITING_HUMAN_APPROVAL` and stop until a fresh exact-bound FreeOTP approval is supplied.

## Promotion checklist

1. Re-read live BOOTSTRAP and canonical memory.
2. Re-read exact live auth/session source and enumerate all token-consume call sites.
3. Apply candidate only to an isolated non-executable server build.
4. PHP lint / hosting-tolerant validation.
5. Regression tests for normal consume, previous-token recovery, recovery-handle retry, open-response retry, expiry/revocation and concurrent token progression.
6. Verify critical approval code is byte-for-byte semantically unchanged except normal-session prepare transport.
7. Verify genome/manifest contains new components and no unknown/drift files.
8. Do not finalize while an unknown existing self-update pending package would be overwritten.
9. Finalize through the normal self-update verifier only.
10. Install only after exact RED binding + fresh current-counter FreeOTP.
11. Immediately verify BOOTSTRAP, genome/LKG, drift, unknown files, session open/recover smoke tests and critical gate behavior.
