# Interrupt/Auth Resilience — current checkpoint

Date: 2026-09-16
State: PAUSED_ON_LIVE_AUTH_TRANSPORT, candidate development continues safely

## Completed

- Standing operating rule fixed in candidate: chat/stream interruption is a normal condition; never rely on a live stream as process state.
- Persistent non-secret jobs/checkpoints with CAS revision protection implemented.
- Exact encrypted response replay implemented and tested.
- Atomic request claim implemented.
- Expired IN_PROGRESS becomes UNCERTAIN and is never automatically re-executed.
- Recursive request canonicalization and nested secret-key stripping implemented.
- Shared normal-session lock implemented.
- Request-bound previous-token fallback implemented: previous token cannot authorize a different resilient request_id.
- Idempotent FreeOTP session-open v2 implemented without a secondary recovery bearer.
- Common guarded normal-action v5 implemented.
- Read-only approval-status recovery implemented for lost critical-execute responses using approval_id + exact binding_sha256.
- PROMOTION_MANIFEST.json explicitly allowlists the promote-ready runtime set and deny-lists superseded experiments.
- Local syntax/regression/end-to-end tests passed for all above invariants.

## Live authoritative state

- KiCom runtime: 0.9.14.
- Existing unknown self-update pending: 0.9.15, RED, source autonomy:server-build.
- 0.9.15 remains untouched; do not finalize another update while it would replace pending.json.
- Public UPDATE_STATUS exposes no changed_paths/package inspection.
- Mirror source snapshots currently stop at 0.9.12.
- Authenticated live source reads are currently blocked by the external fetch/gateway before KiCom, even when URL encoding is varied.

## Next safe actions

1. Obtain a working authenticated transport window and re-read exact live 0.9.14 auth/router source.
2. Enumerate every rolling-token consumer and compare exact live SHA/source against the 0.9.12 reference assumptions.
3. Audit/resolve unknown RED pending 0.9.15 without deleting its package/history.
4. Write the interruption/auth-resilience decision into canonical KiCom memory when the mutation transport is reachable.
5. Create an isolated non-executable server build using only PROMOTION_MANIFEST runtime_include.
6. Run verifier/genome/lost-response smoke tests.
7. Only then prepare a release; critical install remains exact-bound current-counter FreeOTP.

No authentication material, session tokens, TOTP values, passwords or recovery secrets are stored in this checkpoint.
