# KiCom 0.9.15 → Interrupt/Auth Resilience v5 — isolated build patch plan

This plan is bound to the authoritative live 0.9.15 hashes in `LIVE_INTEGRATION_0.9.15.json`. Apply only to an isolated non-executable server build. Never patch live production files directly.

## Preconditions

- `BOOTSTRAP` reports runtime `0.9.15`.
- `LIVING_STATUS`/`GENOME_STATUS` report `kicom-0.9.15-g16`, healthy/trusted/LKG, drift=0, unknown=0.
- Live source hashes match `LIVE_INTEGRATION_0.9.15.json`.
- D024 remains accepted in canonical DECISIONS.
- The installed 0.9.15 workspace-proposal-batch capability is preserved exactly; resilience must not remove or weaken it.
- Canonical memory is known stale for PROJECT_STATE/PROTOCOL/CHANGELOG/NEXT and must be synchronized either before release preparation or as a mandatory post-install checkpoint.
- Every mutating build request uses the current rolling token and records the returned next token immediately.
- Lost/denied mutation response is ambiguous; do not blindly repeat it.

## Live 0.9.15 base hashes

- `index.php` `560e4252d8555210bb834a4a3dbc8cdfacbf67bca867f5f1e84317201fa8bd6f`
- `api.php` `9c55813ea725e8f1e537094c1c9c22be7e870f72bb8217066c96d029b1e65421`
- `living.php` `07d4e95280c0b9c2efecded71671502043f8b9d4d85feb9de09d99ebf4b7febd`
- `lib.php` `9e4616c95839d0bdc133bd0c347244e82c9703f3bb29018b7fa6b61efa997b14`
- `genome/genome.json` `c5191b1b025bde8fa0420319c412222758478b212c96f0290adcd36366dfd3a3`

Next release target: **0.9.16 / genome generation 17**.

## Mandatory 0.9.15 invariant: workspace proposal batch

The following live behavior must survive unchanged:

- `AUTH_APPROVAL_PREPARE&action=workspace_proposal_batch`
- request parameter: `proposal_ids`
- 1..16 proposal IDs
- proposal kind `workspace_write` only
- base `NEW` only
- unique target paths
- proposal/content SHA binding and execution-time recheck
- local exclusive batch lock
- compensating rollback on later write failure
- execution remains through `AUTH_APPROVAL_EXECUTE` with a fresh FreeOTP trust-boundary approval

Regression test this capability after every relevant `living.php` or `index.php` patch.

## Patch group A — `living.php`

Base SHA before first patch: `07d4e95280c0b9c2efecded71671502043f8b9d4d85feb9de09d99ebf4b7febd`.

1. Preserve the existing normal-session consumer under a legacy name.
2. Embed only the explicit promote-core files from `PROMOTION_MANIFEST.json`; do not include superseded recovery-handle implementations.
3. Restore the canonical session-consume name as a compatibility bridge:
   - no deferred `client_request_id` => exact legacy behavior;
   - resilient context => request-bound consumer.
4. Add read-only helpers for:
   - exact-bound approval status,
   - sanitized pending-update inspection,
   - source-manifest hashes.
5. Do not alter the existing 0.9.15 workspace-batch functions or their `AUTH_APPROVAL_EXECUTE` branch.
6. Validate PHP/content after each patch and use each returned target SHA as the next base.

### Living invariants

- TOTP verification semantics unchanged.
- RED/production/kernel execution stays fresh-current-counter only.
- No secondary standing recovery bearer.
- Legacy clients without `client_request_id` retain current behavior.
- Previous-token fallback for resilient requests is bound to the same caller request ID only.
- Workspace proposal batch remains transaction-bound and FreeOTP-gated.

## Patch group B — `index.php`

Base SHA before first patch: `560e4252d8555210bb834a4a3dbc8cdfacbf67bca867f5f1e84317201fa8bd6f`.

1. Hook central `out()` with deferred replay completion while preserving original output even if receipt persistence fails.
2. Rework central `requireAutonomySession()` with backward-compatible resilient preflight:
   - no caller id => legacy path;
   - new id => claim/consume;
   - replay => exact stored status/lines;
   - in-flight/uncertain => never execute.
3. Keep the existing 0.9.15 `AUTH_APPROVAL_PREPARE` router branch for `workspace_proposal_batch` and parameter `proposal_ids` intact.
4. Add resilient preflight to the special rotating GETs `AUTONOMY_TX_COMMIT` and `AUTONOMY_CB_EXEC`.
5. Make `AUTH_SESSION_OPEN` idempotent only when caller supplies `client_request_id`; legacy open remains unchanged otherwise.
6. Add read-only diagnostic routes:
   - `AUTH_APPROVAL_STATUS` with exact `approval_id + binding_sha256`,
   - `UPDATE_PENDING_INSPECT` using nonrotating session peek,
   - `SOURCE_MANIFEST_STATUS` with hashes only.
7. Keep server `request_id` separate from caller `client_request_id`.

## Patch group C — `api.php`

Base SHA before first patch: `9c55813ea725e8f1e537094c1c9c22be7e870f72bb8217066c96d029b1e65421`.

1. Hook `apiOut()` to complete deferred replay receipts.
2. `AUTONOMY_BATCH`: accept caller request ID, fingerprint canonical operations excluding auth material, use bridged consume, replay exact response.
3. `AUTONOMY_UPDATE_UPLOAD`: bind request fingerprint to raw body SHA-256, normalized filename and byte length before token consume.
4. Do not expand package-size, target, URL, deployment or update authorization boundaries.

## Canonical memory synchronization

Current runtime is 0.9.15/g16 but persistent canonical memory still describes 0.9.14/g15. Before 0.9.16 promotion, synchronize with optimistic concurrency against these observed SHAs:

- PROJECT_STATE `fc910f18d32d2689b45f005e9d6d657a71d2303e926cefdce543c172b76d0d55`
- PROTOCOL `cdb3d35583034776ccab9fa1156acddc6ec501a4808c163705f1b9c9c26d3516`
- CHANGELOG `bd8e71818f006adef543e32418b016c64aaeeb7f2df62d2dcfe3b650ede8d572`
- NEXT `25c899e99b232171da6dd7bdd0d1f72f4fbf50563e57dc7d9f5e2fd50abc37db`
- DECISIONS is current and contains D024; do not rewrite it unnecessarily.

Required semantic updates:

- PROJECT_STATE: 0.9.15 / `kicom-0.9.15-g16` / generation 16 + verified installation evidence + workspace-batch-live fact.
- PROTOCOL: replace obsolete workspace-batch candidate note with the live 0.9.15 contract using `proposal_ids` and fresh FreeOTP execution.
- CHANGELOG: append 0.9.15 release/install evidence; preserve all prior history.
- NEXT: baseline 0.9.15; remove obsolete unknown-pending wording; target isolated 0.9.16/g17 resilience build; keep first-party feed repair as an active infrastructure item.

Prefer a single compact/codebook batch so memory synchronization consumes one rolling token.

## Validation before release preparation

1. PHP/content validation after every patch.
2. Only expected files differ from the 0.9.15 baseline.
3. Workspace proposal batch still prepares the harmless NEW test proposal correctly and remains FreeOTP-gated for execution.
4. Normal-action lost-response retry returns the exact prior response and next token without re-execution.
5. Same caller id with changed fingerprint is rejected.
6. Expired in-progress request becomes `UNCERTAIN`, never auto re-executes.
7. Idempotent session-open does not consume FreeOTP twice or roll back a newer action token.
8. Lost critical-execute response can be checked through read-only exact-bound approval status without another FreeOTP.
9. Pending inspector is read-only and hides internal package path.
10. Source manifest status exposes only path/bytes/SHA and aggregate hash.
11. Genome/verifier checks pass; post-install drift and unknown stay zero.
12. Canonical memory is synchronized to the installed runtime after release.
13. Critical RED/production/kernel rules are unchanged.

## Feed/infrastructure follow-up

The configured primary RTB feed currently reports HTTP 403 to KiCom while the GitHub mirror succeeds. Keep the mirror as fallback, but repair the RTB-controlled primary before treating redundancy as healthy. Do not weaken KiCom URL allowlists or TLS validation to make the feed work.

## Finalization rule

Finalize only after the isolated 0.9.16/g17 build passes all tests and there is no conflicting pending update. Production installation remains an exact-bound RED action requiring a fresh current-counter FreeOTP code.
