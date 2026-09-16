# KiCom Interrupt/Auth Resilience v1 — candidate

Status: **candidate only / non-authoritative / non-executable by itself**.

## Purpose

Make KiCom work safe under chat-stream interruption, platform-side review, lost responses and transport retries without weakening the existing trust boundary.

## v1 implemented core

1. **Persistent jobs/checkpoints**
   - server-side non-secret state only
   - states: RUNNING, WAITING_HUMAN_APPROVAL, PAUSED, COMPLETED, FAILED
   - revision/CAS protection rejects stale continuation
   - explicit phase, last-completed and next-step fields

2. **Idempotent request receipts**
   - caller supplies stable request ID + request SHA-256
   - repeated identical request returns the existing receipt instead of executing twice
   - changed request under the same ID is rejected
   - receipt results are sanitized before persistence

3. **Secret exclusion**
   - metadata keys containing token, TOTP, FreeOTP, password, secret, authorization, cookie, session_key or api_key are not persisted
   - no session token or human code is part of a resumable job/checkpoint

4. **Bounded normal-session recovery**
   - `AUTH_SESSION_OPEN` integration provisions one 256-bit recovery handle and returns it once with the normal session
   - only SHA-256(handle) is stored in the session row; plaintext handle is never persisted by this module
   - recovery works only while the original normal autonomy session remains inside both absolute and idle TTL
   - each new recovery rotates to a fresh normal action token and clears the previous rolling-token grace
   - identical retry of the same recovery request ID deterministically returns the same token for 180 seconds
   - stale/older request IDs cannot roll a newer token backwards
   - maximum 16 recoveries per normal session by default
   - expired, revoked or missing sessions are never recreated or revived

## Integration contract

Candidate endpoint shape:

- `AUTH_SESSION_OPEN&code=<FreeOTP>` -> existing fields plus `recovery_handle`, `recovery_expires_at`, `recovery_limit`
- `AUTH_SESSION_RECOVER&session_id=<id>&recovery_handle=<handle>&request_id=<stable-id>` -> `next_token`, normal session expiries, `replayed=true|false`
- optional read-only `AUTH_SESSION_RECOVERY_STATUS&session_id=<id>` -> counters/expiry only, never the handle

The recovery endpoint is **not** a critical approval endpoint. It cannot create a new session, change scope, approve proposals, install updates, write production, mutate the recovery kernel or execute RED actions. It only replaces the rolling action token of an already-valid normal autonomy session.

Before runtime promotion, the exact live `kicomAutonomySessionOpen` / `kicomAutonomySessionConsume` implementation must be re-read and the same per-session lock must be used by both normal token consumption and recovery, so concurrent requests cannot clobber each other. The nearest mirrored 0.9.12 core already has 60-second previous-token recovery; this candidate complements it rather than removing it.

## Critical-auth invariant

Critical behavior is unchanged:

`prepare exact binding -> fresh current-counter FreeOTP -> execute exact RED/production/kernel action`

A recovery handle can at most restore a normal session token so that a prepare/read/workspace operation can continue. It never substitutes for the second/current FreeOTP at the trust boundary.

## Invariants

- Server state remains authoritative.
- No arbitrary filesystem, shell, SQL or remote-fetch authority.
- No trust expansion from memory, jobs, receipts or recovery handles.
- No change to current-counter-only RED/production/kernel execution.
- No hard deletion of project experience; corrections supersede/archive.
- Candidate must pass KiCom verifier and genome promotion before runtime use.

## Regression evidence

Standalone PHP job/receipt harness passes:
- job create
- secret metadata filtering
- checkpoint CAS
- stale checkpoint rejection
- request claim
- replay before commit
- commit
- response secret filtering
- completed replay
- request-ID tamper rejection

Standalone PHP session-recovery harness passes:
- open with recovery
- handle plaintext absent from session file
- first recovery rotates token
- identical recovery retry returns same token
- new recovery rotates again
- old request cannot roll token backwards
- wrong handle rejected
- replay after later normal use is superseded
- fresh recovery after normal use works
- status never returns handle
- recovery limit enforced
- expired session not revived

Local result: **ALL TESTS PASSED**.
