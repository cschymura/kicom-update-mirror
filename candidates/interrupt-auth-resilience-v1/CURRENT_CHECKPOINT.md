# Interrupt/Auth Resilience — current checkpoint

Date: 2026-09-16
State: WAITING_FOR_LIVE_0_9_15_IDENTIFICATION, candidate development continues safely

## Completed

- Standing operating rule fixed in candidate: chat/stream interruption is a normal condition; never rely on a live stream as process state.
- Persistent non-secret jobs/checkpoints with CAS revision protection implemented.
- Promote core advanced to v5 with explicit allowlist/denylist.
- Exact AES-256-GCM response replay implemented and tested; no plaintext session id/old token/next token in replay receipts.
- Recursive request canonicalization and nested secret-key stripping implemented.
- Atomic request claims implemented.
- Expired IN_PROGRESS becomes UNCERTAIN and is never automatically re-executed.
- Shared normal-session lock implemented.
- Request-bound previous-token fallback implemented: an old token cannot authorize a different resilient request_id.
- Idempotent FreeOTP session-open v2 implemented without a secondary recovery bearer.
- Common guarded normal-action v5 implemented.
- Read-only approval-status recovery implemented for lost critical-execute responses using approval_id + exact binding_sha256.
- Read-only pending-update inspector implemented: versions/package+manifest+genome hashes/risk reasons/changed paths, no package mutation or internal package path.
- Public read-only trusted-source manifest hash status implemented: path/bytes/SHA only, never source content.
- Local syntax/regression/end-to-end tests passed for all above invariants.

## Live authoritative state

- KiCom runtime: 0.9.14.
- Existing unknown self-update pending: 0.9.15, RED, source autonomy:server-build.
- 0.9.15 remains untouched; do not finalize another update while it would replace pending.json.
- Public UPDATE_STATUS currently exposes version/risk/source only.
- Mirror source snapshots currently stop at 0.9.12.
- Authenticated live source reads are currently blocked by the external fetch/gateway before KiCom, including URL-encoded token attempts.
- 0.9.12 risk-class code confirms the admin UI risk reasons can identify RED causes such as security-boundary paths, genome policy changes or kernel changes.

## Next safe actions

1. Identify 0.9.15. Lowest-burden current path: one screenshot of the KiCom admin self-update pending section showing version/source/risk reasons/kernel flag. No action/discard/install.
2. If/when authenticated transport works, re-read exact live 0.9.14 auth/router source and enumerate every rolling-token consumer.
3. Audit/resolve 0.9.15 without deleting its package/history.
4. Write the interruption/auth-resilience decision into canonical KiCom memory when mutation transport is reachable.
5. Create an isolated non-executable server build using only PROMOTION_MANIFEST runtime_include.
6. Run verifier/genome/lost-response smoke tests.
7. Only then prepare a release; critical install remains exact-bound current-counter FreeOTP.

No authentication material, session tokens, TOTP values, passwords or recovery secrets are stored in this checkpoint.
