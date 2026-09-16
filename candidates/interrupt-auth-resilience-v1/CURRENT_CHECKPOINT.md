# Interrupt/Auth Resilience — current checkpoint

Date: 2026-09-16
State: LIVE_0_9_14_INTEGRATION_MAPPED; pending 0.9.15 identified as intended workspace-proposal-batch release; deterministic resilience v5 bundle persisted

## Completed

- Standing operating rule is canonical in KiCom memory as DECISION D024: chat/stream interruption is a normal operating condition; never rely on the live stream as process state.
- Canonical DECISIONS SHA after D024 write: `48bbda6275a0441870e0ce5f1362adcb021e4b4336a55e930acbfccc6e3aa532`.
- Persistent non-secret jobs/checkpoints with CAS revision protection implemented.
- Promote core advanced to v5 with explicit allowlist/denylist.
- Exact AES-256-GCM response replay implemented and tested; no plaintext session id/old token/next token in replay receipts.
- Recursive request canonicalization and nested secret-key stripping implemented.
- Atomic request claims implemented.
- Expired IN_PROGRESS becomes UNCERTAIN and is never automatically re-executed.
- Shared normal-session lock implemented.
- Request-bound previous-token fallback implemented: an old token cannot authorize a different resilient client_request_id.
- Legacy-compatible session consume bridge implemented: no client_request_id => original session-consume path.
- Idempotent FreeOTP session-open v2 implemented without a secondary recovery bearer.
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
- Exact isolated-build patch sequence is stored in `LIVE_PATCH_PLAN_0.9.14.md`.
- CI regression run #52 passed.
- Deterministic living bundle is generated from `PROMOTION_MANIFEST.json`, PHP-linted and SHA-verified.
- Bundle: 42,750 bytes; SHA-256 `8e4cbc006b02d03f07edd4ea2a6db17d755141d54364bf973f44199a70dfad2d`.
- Superseded recovery-handle symbols are absent from the generated bundle; required promote symbols are present.
- Build bot persisted the generated bundle on the candidate branch under `generated/` (commit `f004c424213332316eff8c21227c92c548199b12`), so progress no longer depends on a temporary Actions artifact.
- Pending 0.9.15 is now identified from the KiCom admin risk-reason display plus PR #12 integration contract as the intended transaction-bound FreeOTP workspace-proposal-batch candidate. Its RED reasons match exactly the expected release footprint: `security-boundary-change:index.php`, `reviewed-code-or-memory-change:living.php`, `reviewed-code-or-memory-change:memory/changelog.kcl`, `reviewed-code-or-memory-change:memory/project_state.kcl`, plus `version-metadata-only`. No kernel/recovery/api boundary reason is shown. PR #12 requires living.php + index.php integration and normal release memory/version metadata updates.

## Live authoritative state

- KiCom runtime: 0.9.14.
- Self-update pending: 0.9.15, RED, source `autonomy:server-build`.
- 0.9.15 is no longer considered unknown; it is the intended workspace-proposal-batch release candidate corresponding to Draft-PR #12 / the earlier server-build work.
- 0.9.15 remains uninstalled until exact RED binding + fresh current-counter FreeOTP.
- Do not overwrite pending.json with a resilience release before 0.9.15 is either installed or otherwise explicitly resolved.
- An isolated non-executable resilience build was created from live 0.9.14: build id `244fea5ac5c1f2141605`; it may expire and can be recreated from the recorded live hashes.
- External Keenable/fetch transport allows public/read-lease operations but intermittently denies authenticated mutation URLs before KiCom.
- These denials are transport failures, not KiCom execution results. No blind retry is allowed for an ambiguous mutation.

## Exact live source hashes used for integration

- `index.php` `b798947e5601d943108e01592590e87b941ba03b3a065c27ad399a16e1b2bd54`
- `api.php` `9c55813ea725e8f1e537094c1c9c22be7e870f72bb8217066c96d029b1e65421`
- `living.php` `dc6420812118d31076384642ee2d7a8dc334a246885f8be84650ef0c52123391`
- `lib.php` `425a16b74b3c179c5bfa0db3c1896d9568072325a1987a53a6afac59a03be2e8`
- `genome/genome.json` `08e8de68fa35dac2fe1a08e25eb037580aedc0904d0a8266b56d7bf830290776`

## Next safe actions

1. Prepare an exact RED approval binding for the already-verified pending 0.9.15; do not install without a fresh current-counter FreeOTP.
2. After 0.9.15 installation, verify BOOTSTRAP/genome/LKG/drift/unknown and smoke-test `workspace_proposal_batch` preparation without applying arbitrary proposals.
3. Rebase/recreate the isolated resilience build from the resulting 0.9.15 baseline, because index.php/living.php hashes will intentionally change.
4. Apply resilience v5 only to an isolated build using the explicit promotion manifest and the newly re-read 0.9.15 hashes.
5. Run verifier/genome/lost-response smoke tests.
6. Finalize resilience as the next release only after pending.json is free and exact live hashes match.
7. RED/production/kernel install remains exact-bound fresh current-counter FreeOTP.

No authentication material, session tokens, TOTP values, passwords or recovery secrets are stored in this checkpoint.
