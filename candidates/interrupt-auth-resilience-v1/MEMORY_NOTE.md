# Standing operational rule

Chat/stream interruption is a normal operating condition for KiCom work. Platform-side review or transport failure can interrupt delivery at any time, and continuation cannot be assumed.

Therefore:
- KiCom must persist non-secret resumable job/checkpoint state server-side.
- Bounded steps must be idempotent and safely retryable.
- FreeOTP codes are time-sensitive and should be consumed immediately when actually needed, before long analysis.
- Ordinary work should resume without a new TOTP when a still-valid session can be safely recovered.
- RED, production and kernel actions remain exact transaction-bound current-counter FreeOTP gates.
- No TOTP codes, session tokens, passwords, secrets or API keys may be stored in job/checkpoint state.

This candidate note mirrors the human instruction until the canonical KiCom memory write path is reachable again. Canonical server memory remains authoritative once updated.