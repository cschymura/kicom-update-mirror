# PR #13 candidate status

Current candidate implements:

- persistent non-secret jobs/checkpoints with CAS revision protection
- idempotent request receipts with request-ID tamper rejection
- strict secret filtering for resumable state
- exact request replay v2: same normal-autonomy session + same request_id + same request fingerprint + same presented old token may retrieve only the already executed response
- replay response is encrypted at rest with a key derived from the presented old token and request_id; neither old nor next token is stored in plaintext
- existing 60-second previous-token recovery remains intact
- idempotent FreeOTP session-open replay for the same short-lived request ID
- locked rolling-token consumer preserving current session semantics
- integration contract covering GET, POST, transaction and batch token consumers

Superseded candidate idea:

- the standalone long-lived recovery-handle design in session_recovery_v1.php is retained only as development history and MUST NOT be integrated or promoted. It creates a second bearer credential and is superseded by exact request replay v2.

All local PHP syntax checks and standalone regression harnesses for request replay v2 pass.

Not yet live. Promotion is intentionally blocked until:

1. exact live 0.9.14+ auth source is re-read and every token-consume callsite is enumerated;
2. existing unknown RED pending 0.9.15 is audited so a new finalize cannot overwrite `pending.json`;
3. canonical KiCom memory receives the standing interruption/auth-resilience decision;
4. isolated server build passes verifier/genome checks;
5. normal-session replay smoke tests pass, including a deliberately lost-response simulation;
6. RED/production/kernel critical approval semantics are confirmed unchanged.
