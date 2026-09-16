# Interrupt/Auth Resilience — current checkpoint

Date: 2026-09-16
State: LIVE_0_9_14_INTEGRATION_MAPPED; authenticated mutation transport intermittently blocked; unknown RED pending 0.9.15 remains release blocker

## Completed

- Standing operating rule is now canonical in KiCom memory as DECISION D024: chat/stream interruption is a normal operating condition; never rely on the live stream as process state.
- Canonical DECISIONS SHA after D024 write: `48bbda6275a0441870e0ce5f1362adcb021e4b4336a55e930acbfccc6e3aa532`.
- Persistent non-secret jobs/checkpoints with CAS revision protection implemented.
- Promote core advanced to v5 with explicit allowlist/denylist.
- Exact AES-256-GCM response replay implemented and tested; no plaintext session id/old token/next token in replay receipts.
- Recursive request canonicalization and nested secret-key stripping implemented.
- Atomic request claims implemented.
- Expired IN_PROGRESS becomes UNCERTAIN and is never automatically re-executed.
- Shared normal-session lock implemented.
- Request-bound previous-token fallback implemented: an old token cannot authorize a different resilient client_request_id.
- Idempotent FreeOTP session-open v2 implemented without a secondary recovery bearer.
- Common guarded normal-action v5 implemented.
- Deferred router guard implemented and locally tested for EXECUTE / exact REPLAY / IN_FLIGHT / UNCERTAIN / exact HTTP+KCL status.
- Read-only approval-status recovery implemented for lost critical-execute responses using approval_id + exact binding_sha256.
- Read-only pending-update inspector implemented: versions/package+manifest+genome hashes/risk reasons/changed paths, no package mutation or internal package path.
- Public read-only trusted-source manifest hash status implemented: path/bytes/SHA only, never source content.
- Local syntax/regression/end-to-end tests passed for all above invariants.
- Live 0.9.14 source manifest was re-read through a nonrotating read lease. Exact live hashes and integration map are stored in `LIVE_INTEGRATION_0.9.14.json`.
- Exact live token-rotation map confirmed:
  - 21 GET actions pass through `index.php::requireAutonomySession()`.
  - special GET rotators: `AUTONOMY_TX_COMMIT`, `AUTONOMY_CB_EXEC`.
  - POST rotators: `AUTONOMY_UPDATE_UPLOAD`, `AUTONOMY_BATCH`.
  - TX begin/append/backoff/status/abort and CB begin/validate/status do not rotate the main token.
- Live approval record/execute semantics were re-read and match the read-only approval-status candidate.
- Live pending.json structure was re-read and already stores risk_reasons + changed_paths, so the new inspector can be tiny/read-only.

## Live authoritative state

- KiCom runtime: 0.9.14.
- Genome baseline remains trusted/LKG from the previously verified 0.9.14 install.
- Existing unknown self-update pending: 0.9.15, RED, source `autonomy:server-build`; it remains untouched.
- Package SHA prefix previously bound for 0.9.15: `64361995...`; no install/discard was performed.
- An isolated non-executable build was created from live 0.9.14: build id `244fea5ac5c1f2141605`. It had no resilience patch committed when recorded and may expire; recreate from live baseline if needed.
- Current external fetch gateway allows public/read-lease access but intermittently denies authenticated mutation URLs before KiCom. These denials are transport failures, not KiCom execution failures.
- No blind retry is allowed for an ambiguous mutating request.

## Exact live source hashes used for integration

- `index.php` `b798947e5601d943108e01592590e87b941ba03b3a065c27ad399a16e1b2bd54`
- `api.php` `9c55813ea725e8f1e537094c1c9c22be7e870f72bb8217066c96d029b1e65421`
- `living.php` `dc6420812118d31076384642ee2d7a8dc334a246885f8be84650ef0c52123391`
- `lib.php` `425a16b74b3c179c5bfa0db3c1896d9568072325a1987a53a6afac59a03be2e8`
- `genome/genome.json` `08e8de68fa35dac2fe1a08e25eb037580aedc0904d0a8266b56d7bf830290776`

## Next safe actions

1. When authenticated mutation transport is reachable, patch only the isolated build, never live files directly.
2. Living patch: preserve legacy consumer under `kicomAutonomySessionConsumeLegacyV5`, insert v5 resilience helpers, and bridge `kicomAutonomySessionConsume()` so resilient request-binding is used only when a deferred `client_request_id` context exists; legacy clients remain unchanged.
3. Index patch: central deferred guard in `requireAutonomySession()` + completion hook in `out()`; small preflight hooks for `AUTONOMY_TX_COMMIT` and `AUTONOMY_CB_EXEC`; idempotent `AUTH_SESSION_OPEN` when `client_request_id` is supplied; add read-only approval/pending/source-manifest status routes.
4. API patch: completion hook in `apiOut()`; resilient preflight for `AUTONOMY_BATCH`; raw upload fingerprint must bind body SHA256 + filename + length before token consume.
5. Audit/resolve unknown RED pending 0.9.15 without deleting its package/history and without overwriting pending.json.
6. Run isolated-build validation, verifier/genome checks and deliberate lost-response smoke tests.
7. Only then prepare/finalize a release. RED/production/kernel install remains exact-bound fresh current-counter FreeOTP.

No authentication material, session tokens, TOTP values, passwords or recovery secrets are stored in this checkpoint.
