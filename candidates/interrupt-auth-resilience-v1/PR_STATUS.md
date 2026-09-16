# PR #13 candidate status

Current candidate implements:

- persistent non-secret jobs/checkpoints with CAS revision protection
- atomic pre-execution request claims
- exact encrypted response replay for the same session + request_id + request fingerprint + presented old token
- `IN_PROGRESS` handling that prevents duplicate execution
- `UNCERTAIN` handling: expired in-flight work is never automatically re-executed
- request-id/fingerprint/token conflict rejection
- shared normal-session lock
- locked rolling-token consumer preserving KiCom current-token + one-use 60-second previous-token recovery semantics
- idempotent FreeOTP normal-session open v2 with 180-second exact replay and no secondary recovery bearer
- common guarded normal-action wrapper v3
- read-only `approval_id + binding_sha256` status recovery for lost critical-execute responses
- strict secret filtering for resumable jobs/checkpoints

Superseded development history retained but forbidden from promotion:

- `session_recovery_v1.php` — long-lived recovery handle rejected as unnecessary second bearer credential
- v1 session-open/consume and older request-guard/wrapper iterations

Local validation completed:

- PHP syntax PASS for session lock/consume/open v2, request guards, wrappers and approval status
- FreeOTP session open consumes TOTP exactly once PASS
- same open request replays same initial token PASS
- open replay cannot roll a later token backward PASS
- existing previous-token recovery remains one-use PASS
- exact normal-response replay returns same next_token without re-execution PASS
- different request fingerprint rejected PASS
- `IN_PROGRESS` duplicate does not execute PASS
- expired `IN_PROGRESS` becomes `UNCERTAIN` and remains non-executable PASS
- approval-status wrong binding rejected PASS
- approval payload not exposed PASS

Live state remains unchanged: KiCom 0.9.14 is authoritative. Existing unknown RED pending 0.9.15 remains untouched.

Promotion is intentionally blocked until:

1. exact live 0.9.14+ auth/router source is re-read and every token-consume callsite is enumerated;
2. unknown RED pending 0.9.15 is audited/resolved so no finalize overwrites `pending.json`;
3. canonical KiCom memory receives the standing interruption/auth-resilience decision;
4. an isolated server build integrates only the v2/v3/v4 approved components and passes verifier/genome checks;
5. live lost-response smoke tests pass;
6. RED/production/kernel critical approval semantics are confirmed unchanged.
