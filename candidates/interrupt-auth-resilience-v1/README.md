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
   - no session token or human code is part of a resumable checkpoint

## Next integration step

Add narrow core adapters after reading the exact live KiCom 0.9.14/next baseline:

- `JOB_CREATE`, `JOB_STATUS`, `JOB_CHECKPOINT`, `JOB_RESUME`
- stable client `request_id` handling for authenticated mutations
- a **session-recovery handle** that may only resynchronize an already-valid normal autonomy session; it must not create a session, grant permissions, approve a proposal, or cross RED/production/kernel boundaries
- critical actions continue to require a freshly prepared exact transaction binding plus a current-counter FreeOTP code

## Recovery-handle security contract

A recovery handle must be random, revocable, short-lived, stored hashed at rest, bound to one normal autonomy session and unusable as an action token. Recovery rotates/returns a normal session action token only after rechecking that the original session is still active and within absolute/idle policy. It never revives an expired/revoked session.

## Invariants

- Server state remains authoritative.
- No arbitrary filesystem, shell, SQL or remote-fetch authority.
- No trust expansion from memory, jobs or receipts.
- No change to current-counter-only RED/production/kernel execution.
- No hard deletion of project experience; corrections supersede/archive.
- Candidate must pass KiCom verifier and genome promotion before runtime use.

## Regression evidence

Standalone PHP harness passes:
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

Local result: **ALL TESTS PASSED**.
