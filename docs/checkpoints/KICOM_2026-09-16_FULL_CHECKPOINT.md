# KiCom full checkpoint — 2026-09-16

This checkpoint documents the complete working state after the DEV-zone/passkey work and the user's explicit request to back up, document, and retain the results. It intentionally contains no OTP values, session IDs, bearer tokens, passkey secrets, private keys, or other live credentials.

## Canonical live baseline

- Live KiCom runtime: 0.9.15.
- Genome: kicom-0.9.15-g16.
- Live baseline was previously verified healthy/trusted/LKG with drift=0 and unknown=0.
- The live KiCom server remains the authority for production state. GitHub is the development/backup channel, not the live authority.

## Current development operating model

The agreed development model is now:

1. Normal development happens through GitHub + CI.
2. KiCom is treated as build/target/control system, not as the editor transport.
3. Do not return to repeated FreeOTP/session/rolling-token/browser relay chains for ordinary development.
4. DEV sessions are separate from production authority.
5. Production/RED/kernel/recovery boundaries remain separate and require their own explicit promotion/approval path.
6. Results are archived/documented rather than deleted.

## Installed DEV-zone state

The DEV zone has been manually installed under the live KiCom document root at `dev/`.

Observed working behavior in the browser:

- `https://kicom.rurtalbahn.info/dev/` loads.
- Passkey enrollment completed successfully.
- Passkey login creates an active DEV session.
- DEV session status returned HTTP 200 and `DEV_SESSION_STATUS` in the first-party browser flow.
- Session policy: 7-day absolute TTL, 24-hour idle TTL, reusable non-rotating DEV bearer, server-side token hash only.
- First-party browser transport uses DEV headers; query-string credentials are not used for the primary browser API.
- The installed DEV session is deliberately incapable of production deploy, self-update installation, kernel/recovery mutation, auth administration, secret-store access, or arbitrary log paths.

No live credential values are persisted in this checkpoint.

## Agent bridge result

A GET-only DEV agent bridge was added for tools that cannot send custom headers, with an extensionless directory entry under `/dev/agent/`.

Result:

- The DEV agent link is generated correctly and points at `/dev/agent/?...`.
- Browser-side DEV functionality is working.
- The current ChatGPT web-fetch transport still cannot reliably consume the credentialed dynamic agent endpoint and returns an upstream/internal transport error instead of KiCom JSON.
- This is treated as an external transport limitation, not a reason to keep modifying KiCom authentication.
- Decision: use GitHub/CI for development now; keep the DEV agent bridge installed for a future direct connector/transport.

## Important runtime fixes already incorporated

- Corrected the browser client from root-absolute `/dev-api.php` to relative `dev-api.php`, keeping calls inside the `/dev/` install root.
- Added a cache-buster to the DEV UI after the path fix.
- Added `dev/agent/index.php` as an extensionless/directory bridge entry point.
- Added session-locking so authenticate/revoke cannot race by overwriting a completed revoke with stale active state.
- Passkey challenge verification + DEV session issuance is serialized per challenge to prevent double-minting.
- DEV candidate finalization stays isolated and does not create production pending updates or install anything.
- Diagnostics are bounded to DEV audit/living-experience summaries; arbitrary log paths are not exposed.

## DEV capability set

- source.snapshot.read
- workspace.read
- workspace.write
- workspace.delete
- workspace.history
- build.begin
- build.patch
- build.status
- build.test
- build.finalize_candidate
- candidate.read
- candidate.discard
- logs.read

No wildcard capability exists.

## Current candidate PR stack

### PR #17 — practical KiCom DEV zone
- Branch: `candidate/dev-zone-v1`
- Parent/base: `candidate/passkey-auth-resilience-v1`
- Head before this checkpoint document: `4b3240fb5139307b7b9026608dfed9b669a65aad`
- State: open draft candidate.
- Contains practical DEV authority plane, passkey entry, first-party browser UI/API, GET-only DEV agent bridge, bounded diagnostics, workspace/build bindings, candidate export, CI and packaging checks.

### PR #16 — passkey auth bridge v1 after resilience
- Branch: `candidate/passkey-auth-resilience-v1`
- Head: `bcc032f4f14d138b7fef3fc99d5f8c65dd279dab`
- Base: `candidate/interrupt-auth-resilience-v1`
- State: open draft candidate.
- Provides composer-free WebAuthn ES256/P-256 verification, browser codec, runtime adapter, sealed handoff design, live 0.9.15 binding and promotion plan.

### PR #15 — original passkey prototype
- Branch: `feature/passkey-auth-bridge-v1`
- Head: `7bb83db0d3cdf7a8083558128a61ca620640a607`
- Base: `main`
- State: open draft prototype.
- Historical prototype; PR #16/#17 supersede it for current development.

### PR #14 — delegated isolated-build jobs v1
- Branch: `candidate/delegated-build-jobs-v1`
- Head: `f4c973693d2f2bd223846f68bc779bc4438c0e4f`
- Base: `candidate/interrupt-auth-resilience-v1`
- State: open draft candidate.

### PR #13 — interrupt/auth resilience v5
- Branch: `candidate/interrupt-auth-resilience-v1`
- Head: `2cf9cb0e075dec45ab3c98446565a0a0f8444af3`
- Base: `main`
- State: open draft candidate.
- Holds the prepared canonical-memory repair for stale 0.9.14-era resource text and the resilience work for 0.9.16/g17.

### PR #12 — transaction-bound FreeOTP workspace proposal batch
- Branch: `candidate/proposal-freeotp-batch-v1`
- Head: `2dfcd303662fa89ed5ab358fa2374a9d8d8dcdae`
- Base: `main`
- State: open draft candidate.

## CI / artifact checkpoints

Known green CI milestones include:

- PR #13 memory-sync/resilience checks: run 35070368850.
- PR #16 passkey candidate checks: run 35077346848.
- PR #17 DEV-zone checks: run 35095493725 after the `/dev-api.php` path correction.
- A later PR #17 CI run after agent-bridge updates also completed successfully.

Known PR #17 install-oriented candidate ZIP after the path correction had SHA-256:

`62b51f5d849302cf8bdfe24a4acbcfa2e2f0204a9c2e39e080adec01bde7189b`

Treat branch/commit content as the durable source of truth for candidate code; CI artifacts may expire.

## Security / practicality decision

For KiCom as a development system, security belongs primarily at the boundary, not between every edit/build/test step.

DEV is therefore intentionally practical:

- long-lived reusable DEV session,
- no per-request token rotation,
- passkey for entry,
- no repeated OTP during normal DEV work,
- fixed DEV capabilities,
- revocable session,
- no production authority.

Critical production/self-update/kernel/recovery changes remain outside DEV and keep their separate approval semantics.

## Secrets handling rule

Do not persist or copy into documentation, Git, memory summaries, issues, PR comments, or release notes:

- FreeOTP/TOTP codes,
- TOTP seed/secret,
- DEV session IDs or bearer tokens,
- ordinary autonomy session IDs/tokens,
- passkey private material,
- encryption private keys,
- other live credentials.

If any such value appears in a chat, treat it as transient only.

## Recovery / continuity rule

If a later conversation loses context, start from this checkpoint plus the canonical live BOOTSTRAP/PROJECT_STATE/ARCHITECTURE/PROTOCOL/DECISIONS/CHANGELOG/NEXT resources. For development, prefer the current PR stack and GitHub branch history. Do not reconstruct state from vague chat recollection when the documented branch/commit or live canonical state is available.

## User intent to retain

The user explicitly requested that the results be backed up, documented, and remembered. Treat this checkpoint as a durable project rule: preserve history, archive superseded states, and avoid destructive deletion of project knowledge unless the user explicitly instructs otherwise.
