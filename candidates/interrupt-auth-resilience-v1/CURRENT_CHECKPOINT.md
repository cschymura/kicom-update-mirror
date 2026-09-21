# Interrupt/Auth Resilience — current checkpoint

Date: 2026-09-16
State: LIVE_0_9_15_G16_HEALTHY; canonical memory sync prepared and CI-verified; resilience v5 candidate remains non-authoritative

## Live authoritative state

- KiCom BOOTSTRAP reports runtime `0.9.15`; server-state-is-authoritative remains the memory policy.
- `GENOME_STATUS` / `LIVING_STATUS`: genome `kicom-0.9.15-g16`, healthy=true, trusted=true, LKG=true, components=14, drift=0, unknown=0.
- `UPDATE_STATUS`: no pending update (`pending_version`, `pending_risk`, `pending_source` empty); pull and push enabled; primary feed configured at `feed.rurtalbahn.info`, mirror configured at `raw.githubusercontent.com`.
- Canonical DECISIONS is current and includes D024: chat/stream interruption is normal; persist non-secret checkpoints, make authenticated retries idempotent, and keep FreeOTP at trust boundaries.
- Four canonical memory resources are stale relative to the live runtime and are intentionally scheduled for synchronization:
  - PROJECT_STATE SHA `fc910f18d32d2689b45f005e9d6d657a71d2303e926cefdce543c172b76d0d55` still identifies 0.9.14/g15.
  - PROTOCOL SHA `cdb3d35583034776ccab9fa1156acddc6ec501a4808c163705f1b9c9c26d3516` still labels workspace batch as candidate-only.
  - CHANGELOG SHA `bd8e71818f006adef543e32418b016c64aaeeb7f2df62d2dcfe3b650ede8d572` ends at 0.9.14.
  - NEXT SHA `25c899e99b232171da6dd7bdd0d1f72f4fbf50563e57dc7d9f5e2fd50abc37db` still treats 0.9.14 as baseline and contains the obsolete unknown-0.9.15 pending instruction.
- ARCHITECTURE SHA `20e14e1898814f912b34c80687672512bf9fab856cd978eb31d3aadaee0231be` and DECISIONS SHA `48bbda6275a0441870e0ce5f1362adcb021e4b4336a55e930acbfccc6e3aa532` require no change for this synchronization.

## Resilience v5 candidate

- Target release remains KiCom `0.9.16` / genome generation `g17`.
- Candidate implements persistent non-secret checkpoints, exact idempotent response replay, IN_PROGRESS/UNCERTAIN never-blind-reexecute semantics, request-bound previous-token fallback, idempotent session opening, read-only exact-bound approval status, pending-update inspection, public trusted-source hash status and read-only release-consistency diagnostics.
- Promotion manifest is rebased on exact live 0.9.15 integration facts and preserves the installed `workspace_proposal_batch` trust boundary.
- Deterministic runtime bundle remains 48,549 bytes with SHA-256 `e2c1a2e88cc406929f136072909f36cc12cd80fb0000170f3d10c9c414d61e6d`.
- CI run 35070368850 / job 104710225374 passed all syntax, resilience regression, release-consistency, memory-sync and transport tests and reproduced the same bundle SHA.
- No resilience code is live yet.

## Canonical memory synchronization

- Exact mutation plan: `MEMORY_SYNC_0.9.15.json`, schema 2.
- Operations: 5 ordered memory patches over four resources: PROJECT_STATE, PROTOCOL, CHANGELOG, NEXT phase 1, NEXT phase 2.
- Full compact representation: 4,038 bytes; SHA-256 `2f1c3c138e27bd4ab270766fc55e037bccb6899a9dedfa21fa6d0eabd73ee6c1`.
- NEXT SHA chain is explicitly pinned:
  - current/live base `25c899e99b232171da6dd7bdd0d1f72f4fbf50563e57dc7d9f5e2fd50abc37db`
  - intermediate after first NEXT patch `b7596f525a855432294e64f6822adf8e89e77f71114c72a29188549c587a4063`
  - expected final `0aab01dd07b29f3fd5372b08bae69ae5cf9150033e91f4c29030e6b6a22d06e9`
- CI-verified short transport fallback: `MEMORY_SYNC_TRANSPORT_0.9.15.json`.
- Safe three-phase layout, preserving operation order and keeping the two dependent NEXT mutations in different phases:
  1. PROJECT_STATE only — raw 1,487 bytes; Base64URL 1,983; payload SHA `8c7ca89d1058ef3c1684053c83298e8238adbc6facef8286cc9bfcb5a7a83379`.
  2. PROTOCOL + CHANGELOG + first NEXT patch — raw 1,919 bytes; Base64URL 2,559; payload SHA `694def1a45e3782c416e1438d26142512b9e25e5c36b6c638e61cc00ced2f8be`.
  3. second NEXT patch — raw 634 bytes; Base64URL 846; payload SHA `b82d0493a0d355596c98ed07d9ff0121b5a91ba1441de00912add73cde86fd25`.
- Maximum authenticated Base64URL payload is therefore 2,559 characters, down from the earlier ~8 KiB route.
- Do not guess or reconstruct the historical codebook z/TLV encoder; the short-phase fallback is explicit and tested.
- Re-read BOOTSTRAP and exact resource SHAs immediately before execution. Abort if any base has changed.
- A transport denial is not an execution result; never blindly retry an ambiguous mutation.

## Delegated isolated-build candidate (PR #14)

- Remains separate from and behind Resilience v5.
- Candidate hardening includes: RUNNING after interruption becomes UNCERTAIN rather than automatic retry; stored plan SHA is reverified each tick; job is runtime-bound; active delegation count is bounded.
- Explicit session revocation uses an autonomy revocation epoch: normal idle does not revoke jobs, while deliberate `kicomAutonomyRevokeSessions()` epoch change makes open delegated jobs REVOKED.
- Candidate CI for these invariants is green; PR stays Draft until Resilience v5 is completed and live behavior is re-evaluated.

## Trust-boundary invariants

- Production, RED and recovery-kernel execution remains exact transaction-bound fresh current-counter FreeOTP.
- Normal bounded memory/workspace/test/staging operations may use the normal rolling autonomy session only within existing policy.
- Memory and goals never grant permissions.
- No arbitrary filesystem, shell, SQL or arbitrary remote-fetch authority.
- GitHub remains mirror/fallback and candidate workspace, never KiCom authority.
- No hard delete of project experience; supersede or archive.
- No authentication material, rolling tokens, TOTP values, passwords or recovery secrets are stored in this checkpoint.

## Next safe actions

1. Re-read BOOTSTRAP plus PROJECT_STATE/PROTOCOL/CHANGELOG/NEXT SHA values immediately before mutation.
2. When a valid normal autonomy session is available, execute the CI-verified three-phase memory sync strictly in order, verifying state after every phase. No critical FreeOTP approval is required merely because canonical memory is being synchronized inside the existing autonomy envelope.
3. Confirm the four resources now describe 0.9.15/g16 and that the read-only release-consistency diagnostic reports CONSISTENT once Resilience v5 is present in an isolated build/live candidate context.
4. Perform a harmless `workspace_proposal_batch` prepare smoke test without executing arbitrary proposals.
5. Recreate a fresh isolated 0.9.16/g17 build from exact current 0.9.15 source hashes; apply the explicit Resilience v5 integration only there.
6. Run verifier, genome, request-replay/lost-response, token-rotation and rollback tests. Ambiguous execution state must resolve via status/replay, never blind re-execution.
7. Only after all tests are green prepare the exact 0.9.16 critical install binding; production/RED/kernel install still requires a fresh current-counter FreeOTP.
