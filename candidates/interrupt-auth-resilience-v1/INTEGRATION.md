# KiCom Interrupt/Auth Resilience v2 — runtime integration contract

Candidate only. Live KiCom remains authoritative.

## Objective

Treat chat/stream interruption as a normal operating condition. Long work must be checkpointable and normal-autonomy authentication must survive lost responses without creating a second standing bearer credential.

## Runtime components

Promote only these concepts/components:

- `interrupt_resilience_v1.php` — persistent non-secret jobs/checkpoints and pre-execution request claims
- `request_replay_v2.php` — encrypted exact-response replay for an already executed normal-autonomy request
- `session_consume_resilient_v1.php` — per-session locked rolling-token consumer preserving current/previous-token semantics
- `session_open_resilient_v1.php` — short-lived idempotent FreeOTP session-open replay, only after exact live-core review

`session_recovery_v1.php` is superseded development history and MUST NOT be loaded, routed or promoted. A long-lived recovery handle would create a second bearer credential.

## Exact request replay flow

For every normal-autonomy mutation/read that rotates the rolling token and carries a client-generated `request_id`:

1. Canonicalize operation + non-secret parameters into a request fingerprint.
2. Before consuming the rolling token, acquire the per-session/request lock and create an `IN_PROGRESS` receipt bound to:
   - session
   - request_id
   - operation/fingerprint
   - SHA-256 of the presented token
3. If an unexpired receipt already exists:
   - fingerprint/token mismatch => reject
   - completed replay receipt => decrypt with a key derived from the same presented token + request_id and return exactly the prior response
   - `IN_PROGRESS` => do not execute again; return an explicit indeterminate/in-flight status so the caller can inspect operation state
4. On a fresh claim, call the normal locked rolling-token consumer once.
5. Execute the requested bounded operation once.
6. Build the exact KCL/result payload, including `next_token` where applicable.
7. Encrypt the replayable response at rest using AES-256-GCM with a key derived from the presented old token + request_id. Never persist old or next token in plaintext.
8. Mark the receipt `DONE` and return the response.

Recommended replay TTL: 30 minutes, maximum 60 minutes. Replay never extends the underlying session absolute or idle TTL.

## Security properties

- Same request_id cannot authorize a different operation or parameter fingerprint.
- Same request_id with another presented token is rejected.
- The old token is not made valid for a new request; it can only decrypt/retrieve the exact prior response.
- Replay never creates a new autonomy session.
- Replay does not grant RED/production/kernel authority.
- Critical approval execution still requires a fresh current-counter FreeOTP bound to the exact transaction.
- Existing 60-second previous-token recovery remains as a short compatibility safety net.

## Existing token-consume call sites

Nearest mirrored 0.9.12 core shows direct consumers around:

1. `index.php::requireAutonomySession()`
2. `living.php::kicomAutonomyTxCommit()`
3. `api.php::AUTONOMY_UPDATE_UPLOAD`
4. `api.php::AUTONOMY_BATCH`

Before promotion, enumerate every `kicomAutonomySessionConsume(` occurrence in the exact live 0.9.14+ source, including codebook/build paths added after 0.9.12. Every runtime consumer must use the same session lock and request-replay wrapper where response loss can strand a token.

## Session-open handling

FreeOTP session opening is itself vulnerable to response loss. A separate short-lived open-receipt design may reproduce only the exact already-authorized session-open response for the same request_id. It must:

- expire quickly (target 180 seconds)
- store no plaintext TOTP or session token
- never accept the receipt as authority for any other operation
- retain current+configured-past-counter rules for normal session open only
- leave critical current-counter rules unchanged

## Interrupt-safe jobs

Long multi-step development creates a non-secret job checkpoint after meaningful phases. Allowed state includes build IDs, SHAs, phase, completed step, next step and verifier status. Authentication material is forbidden.

States:

- RUNNING
- WAITING_HUMAN_APPROVAL
- PAUSED
- COMPLETED
- FAILED

At RED/production/kernel boundaries, checkpoint `WAITING_HUMAN_APPROVAL` and stop until a fresh exact-bound FreeOTP approval is supplied.

## Promotion checklist

1. Re-read live BOOTSTRAP and canonical memory.
2. Re-read exact live auth/session source and enumerate all token-consume call sites.
3. Canonical memory records interruption/auth-resilience as an accepted operating decision.
4. Audit the existing unknown RED pending 0.9.15 before any finalize that could replace `pending.json`.
5. Apply only to an isolated non-executable server build.
6. PHP lint / hosting-tolerant validation.
7. Regression tests: normal consume, previous-token recovery, exact response replay, fingerprint mismatch, token mismatch, `IN_PROGRESS` crash window, expiry/revocation and concurrent progression.
8. Simulate: server executes request, client loses response, same request_id/old token retrieves prior response and next token without executing again.
9. Confirm critical approval code and semantics remain unchanged.
10. Verify genome/manifest contains new trusted components and no unknown/drift files.
11. Finalize only through the normal self-update verifier.
12. Install only after exact RED binding + fresh current-counter FreeOTP.
13. Immediately verify BOOTSTRAP, genome/LKG, drift, unknown files and replay smoke tests.
