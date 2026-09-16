# PR #13 candidate status

Current candidate implements:

- persistent non-secret jobs/checkpoints with CAS revision protection
- pre-execution `IN_PROGRESS` request claims preventing blind double execution after transport loss
- exact request replay v2: same normal-autonomy session + same request_id + same request fingerprint + same presented old token may retrieve only the already executed response
- replay response encrypted at rest with AES-256-GCM using a key derived from the presented old token + request_id; neither old nor next token is stored in plaintext
- explicit `IN_FLIGHT` result for the crash window between claim and durable completion; caller must inspect operation state rather than re-execute
- strict secret filtering for resumable job/checkpoint state
- existing 60-second previous-token recovery retained
- idempotent FreeOTP session-open candidate for the same short-lived request ID, pending exact live-core review
- locked rolling-token consumer preserving current session semantics

Superseded candidate idea:

- `session_recovery_v1.php` is retained only as development history and MUST NOT be integrated or promoted. A long-lived recovery handle would create a second bearer credential and is superseded by exact request replay v2.

Local validation completed:

- PHP syntax: request replay v2 + request guard v2 PASS
- exact response replay PASS
- no plaintext old/next token in replay receipt PASS
- different action/fingerprint rejection PASS
- different presented token rejection PASS
- duplicate while first request is running => IN_FLIGHT PASS
- completed duplicate => exact replay without re-execution PASS

Not yet live. Promotion is intentionally blocked until:

1. exact live 0.9.14+ auth source is re-read and every token-consume callsite is enumerated;
2. existing unknown RED pending 0.9.15 is audited so a new finalize cannot overwrite `pending.json`;
3. canonical KiCom memory receives the standing interruption/auth-resilience decision;
4. isolated server build passes verifier/genome checks;
5. normal-session replay smoke tests pass, including a deliberately lost-response simulation;
6. RED/production/kernel critical approval semantics are confirmed unchanged.
