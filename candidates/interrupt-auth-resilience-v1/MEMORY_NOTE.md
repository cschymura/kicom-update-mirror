# Standing KiCom operational rule — interruption-safe work

Human instruction, 2026-09-16:

Chat/stream output can be interrupted unpredictably by platform-side review. KiCom and ChatGPT work must therefore treat interruption as a normal operating condition, never assume a streamed response will complete, and persist non-secret progress/checkpoints so work can resume safely.

Authentication latency materially limits development throughput. Normal-session authentication should therefore minimize repeated FreeOTP prompts without weakening trust boundaries:

- FreeOTP session-open gets priority when a code is supplied.
- Session-open should be idempotently replayable for the same short-lived request ID after a lost response.
- An already-valid normal autonomy session should be recoverable with a dedicated recovery handle if the rolling token response is lost.
- Recovery must never create/revive an expired session or grant new scope.
- RED, production and recovery-kernel execution remain exact-transaction-bound and require a fresh current-counter FreeOTP code.
- No TOTP, session token, recovery handle, password or equivalent secret belongs in resumable jobs/checkpoints or canonical project memory.
- Project experience is superseded/archived, not hard-deleted.

This file is a non-authoritative mirror candidate until the same rule is written into KiCom canonical memory.