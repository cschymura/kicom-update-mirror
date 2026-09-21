# KiCom 0.9.15 post-install smoke + resilience rebase plan

Purpose: after installing the already-verified RED pending 0.9.15 (identified as the intended transaction-bound FreeOTP workspace-proposal-batch release), verify the release quickly and then rebase Interrupt/Auth Resilience v5 from the new live baseline without repeating exploratory analysis.

## Preconditions

- 0.9.15 install is performed only through the existing exact-bound RED approval path.
- Fresh current-counter FreeOTP is used only for the critical execute step.
- No new self-update is finalized while 0.9.15 is still pending.
- No arbitrary workspace proposal is approved during smoke testing.

## Immediate post-install checks

1. `BOOTSTRAP`
   - version must be `0.9.15`
   - canonical=true
2. `GENOME_STATUS` / `LIVING_STATUS`
   - healthy=true
   - trusted=true
   - lkg_ok=true
   - drift_count=0
   - unknown_count=0
   - expected next genome generation only
3. `UPDATE_STATUS`
   - 0.9.15 must no longer remain as pending after successful install
4. `PROJECT_STATE`, `CHANGELOG`, `PROTOCOL`, `NEXT`
   - runtime and canonical memory must not disagree on version/generation
5. trusted source list
   - record exact 0.9.15 hashes for index.php, api.php, living.php, lib.php, genome/genome.json

## Workspace-proposal-batch capability smoke

Goal: prove the feature is present without applying a real batch.

1. Inspect `AUTH_APPROVAL_PREPARE` source/routing or DESCRIBE for `workspace_proposal_batch`.
2. Call prepare with an intentionally nonexistent, syntactically valid proposal id + SHA.
3. Expected result: a proposal-specific validation error such as `PROPOSAL_NOT_FOUND`, NOT `APPROVAL_ACTION_UNSUPPORTED`.
4. Do not submit a FreeOTP execute code for this smoke request.
5. Verify no workspace file was created and no proposal was consumed.

This proves routing/helper integration while staying non-mutating at the workspace level.

## Resilience v5 rebase

After the 0.9.15 checks are green:

1. Open a nonrotating read lease from the active normal session.
2. Re-read exact trusted source manifest for 0.9.15.
3. Re-read these byte-level integration anchors:
   - index.php `out()`
   - request-id initialization
   - `requireAutonomySession()`
   - `AUTH_SESSION_OPEN`
   - `AUTH_APPROVAL_PREPARE` / `AUTH_APPROVAL_EXECUTE`
   - `AUTONOMY_TX_COMMIT`
   - `AUTONOMY_CB_EXEC`
   - api.php `apiOut()`
   - api raw update upload
   - api `AUTONOMY_BATCH`
   - living.php `kicomAutonomySessionOpen`
   - living.php `kicomAutonomySessionConsume`
   - living.php `kicomAutonomyTxCommit`
   - approval-record helpers
4. Compare 0.9.15 anchors against `LIVE_INTEGRATION_0.9.14.json`.
5. Expect intentional changes in index.php/living.php from the workspace-batch release; do not assume 0.9.14 byte anchors remain valid.
6. Create a fresh isolated non-executable build from live 0.9.15.
7. Apply only runtime components allowlisted by `PROMOTION_MANIFEST.json`.
8. Run PHP/hosting validation and the existing v5 regression suite.
9. Deliberately simulate a lost normal-auth response:
   - first request executes once
   - same `client_request_id` + same presented old token returns exact stored response and same next token
   - different operation/parameters with same request id are rejected
   - IN_PROGRESS/expired claim becomes UNCERTAIN and is never blindly re-executed
10. Deliberately simulate a lost critical execute response:
   - no second FreeOTP is consumed merely to discover outcome
   - read-only `AUTH_APPROVAL_STATUS` with approval id + binding sha reports terminal state
11. Verify public pending inspector and source-manifest hash status disclose no secrets/source content.
12. Verify RED/production/kernel approval semantics are byte-for-byte or behaviorally unchanged except read-only status support.

## Release sequencing

- 0.9.14 live -> install identified 0.9.15 workspace-batch release.
- Rebase resilience v5 on live 0.9.15.
- Next resilience release should therefore be prepared as the next version after 0.9.15, not by overwriting/reusing the current pending slot.

## Abort conditions

Stop and do not promote resilience if any of the following occur:

- 0.9.15 genome/LKG/drift checks are not clean
- `workspace_proposal_batch` is still unsupported after install
- live 0.9.15 source hashes cannot be re-read
- unexpected recovery.php/guardian.php/kernel changes appear
- request replay stores plaintext token/session secret material
- old token can authorize a different request id
- UNCERTAIN request auto-reexecutes
- critical approval can be executed without a fresh exact-bound current-counter FreeOTP

No authentication material belongs in this document or any persisted checkpoint.
