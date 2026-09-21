# KiCom Interrupt/Auth Resilience v3 — runtime integration contract

Candidate only. Live KiCom remains authoritative.

## Objective

Treat chat/stream interruption as a normal operating condition. Long work must be checkpointable and normal-autonomy authentication must survive lost responses without creating a second standing bearer credential or weakening critical FreeOTP gates.

## Runtime components to promote

- `interrupt_resilience_v1.php` — persistent non-secret jobs/checkpoints and base receipt storage
- `interrupt_resilience_v2.php` — never-blind-reexecute receipt semantics; expired `IN_PROGRESS` becomes `UNCERTAIN`
- `request_replay_v2.php` — encrypted exact-response replay for an already executed normal-autonomy request
- `request_guard_v4.php` — atomic request claim/replay/in-flight/uncertain guard
- `session_lock_v2.php` — one lock namespace for normal rolling-session state
- `session_consume_resilient_v2.php` — locked token consumer preserving current + 60-second previous-token semantics
- `session_open_resilient_v2.php` — short-lived idempotent FreeOTP session-open without a secondary recovery bearer
- `guarded_action_v3.php` — common normal-autonomy wrapper
- `approval_status_v1.php` — read-only recovery of a lost critical-execute result, bound to approval_id + exact binding_sha256

Superseded development history must not be loaded or promoted: `session_recovery_v1.php`, `session_open_resilient_v1.php`, `session_consume_resilient_v1.php`, `request_guard_v2.php`, `request_guard_v3.php`, `guarded_action_v2.php`.

## Normal request flow

Every normal-autonomy request that can rotate a session token should carry a client-generated `request_id` (16..80 chars).

1. Canonicalize operation + non-secret parameters to a request fingerprint.
2. Before token consumption, atomically claim `(session, request_id, fingerprint, presented-token-hash)` as `IN_PROGRESS`.
3. Existing claim handling:
   - different fingerprint/token => reject
   - `IN_PROGRESS` => return `REQUEST_IN_FLIGHT`; never execute again
   - expired `IN_PROGRESS` => transition to `UNCERTAIN`; never auto-reexecute
   - `UNCERTAIN` => return `REQUEST_UNCERTAIN`, `inspect_required=true`
   - `DONE` => decrypt and return the exact prior response, including the prior `next_token`
4. Fresh claim consumes the rolling token exactly once under the shared session lock.
5. Execute the bounded operation once.
6. Encrypt the exact response at rest with AES-256-GCM using a key derived from the presented old token + request_id.
7. Mark receipt `DONE` and return the response.

A request_id is therefore single-purpose and never silently recycled after uncertainty or replay expiry.

## Session-open flow

`AUTH_SESSION_OPEN` should accept an optional/required client `request_id` for resilient clients.

- First successful open consumes FreeOTP once and creates the normal session.
- For 180 seconds, the exact same request_id + same code may reproduce the original session-open response if the initial action token is still current.
- No plaintext TOTP/token is stored.
- No recovery handle or secondary bearer credential exists.
- Once the first action token has rotated, open replay returns `SESSION_OPEN_REPLAY_SUPERSEDED` rather than rolling the token backward.
- Current + configured recent-past counters remain valid only for normal session establishment.

## Lost critical-execute response

Add read-only `AUTH_APPROVAL_STATUS` with inputs:

- `approval_id`
- exact `binding_sha256`

It returns only safe status metadata: action, risk, effective status, terminal flag, created/expires/used times and result_code. It exposes no approval payload and cannot execute, retry, authorize or alter anything.

This lets the client recover from a lost `AUTH_APPROVAL_EXECUTE` response without asking the human for a second FreeOTP merely to discover whether the already-bound RED action ran. Runtime/installation outcome is then confirmed with BOOTSTRAP/LIVING_STATUS as appropriate.

## Token-consume call sites

Nearest mirrored 0.9.12 core identified direct consumption around:

1. `index.php::requireAutonomySession()`
2. `living.php::kicomAutonomyTxCommit()`
3. `api.php::AUTONOMY_UPDATE_UPLOAD`
4. `api.php::AUTONOMY_BATCH`

Before promotion, enumerate every `kicomAutonomySessionConsume(` occurrence in exact live 0.9.14+ source, including codebook/build paths added later. No runtime consumer may be silently left outside the shared lock/replay strategy where response loss can strand the rolling token.

## Low-patch router strategy

Prefer central interception over duplicating retry logic per endpoint:

- resilient `AUTH_SESSION_OPEN` routing at the existing session-open case
- common normal-session request guard before token-consuming execution
- a common response-finalization hook where feasible so the exact KCL/JSON result is persisted only after the action result is known
- explicit wrappers only for internal consumers that rotate tokens outside the main router (transaction commit, POST batch/upload, codebook paths if applicable)

The exact hook points must be derived from live 0.9.14+ source before promotion.

## Interrupt-safe jobs

Long work creates non-secret checkpoints containing only project-safe state such as build IDs, SHAs, phase, completed step, next step and verifier outcome. Authentication material is forbidden.

States: `RUNNING`, `WAITING_HUMAN_APPROVAL`, `PAUSED`, `COMPLETED`, `FAILED`.

At RED/production/kernel boundaries, checkpoint `WAITING_HUMAN_APPROVAL` and stop. A fresh exact-bound current-counter FreeOTP is still mandatory for execution.

## Security invariants

- No arbitrary shell/SQL/filesystem/remote-fetch authority.
- Replay grants no new action; it only returns a previously produced response.
- Same request_id cannot change operation, parameters or presented token.
- Normal replay cannot create a session or grant RED/production/kernel authority.
- `AUTH_APPROVAL_EXECUTE` semantics remain transaction-bound and current-counter-only.
- Goals, jobs, receipts and memory never grant permissions.
- Superseded designs remain development history, not runtime components.

## Promotion checklist

1. Re-read live BOOTSTRAP + canonical memory.
2. Re-read exact live auth/session/router source and enumerate all token consumers.
3. Record the interruption/auth-resilience decision canonically.
4. Audit existing unknown RED pending 0.9.15 before any finalize that could replace `pending.json`.
5. Apply only to an isolated non-executable server build.
6. PHP/hosting validation and complete regression suite.
7. Simulate lost normal response: same request_id + old token returns prior response/next token without re-execution.
8. Simulate crash after claim: retry returns IN_FLIGHT/UNCERTAIN and never re-executes.
9. Simulate lost session-open response: same request_id/code returns original session only while initial token is current.
10. Simulate lost RED execute response: approval status + BOOTSTRAP identifies outcome without second execute/code.
11. Confirm critical approval code semantics unchanged.
12. Verify genome/manifest and drift/unknown=0.
13. Finalize only through normal self-update verifier and only after unknown 0.9.15 is resolved.
14. Install only after exact RED binding + fresh current-counter FreeOTP.
15. Immediately verify BOOTSTRAP, genome/LKG, replay paths and critical gates.
