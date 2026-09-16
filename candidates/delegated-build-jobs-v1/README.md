# KiCom Delegated Build Jobs v1

Candidate-only prototype for reducing authentication/stream friction without creating a second general bearer credential.

## Scope

A normal KiCom autonomy request authenticates **once** and may create an immutable delegated job containing only exact patches for an already existing **isolated non-executable Fast Build**. The job is then executable by a local KiCom runner/cron without the chat stream remaining connected.

v1 deliberately supports only `build_patch` steps. It cannot:

- finalize or prepare a release
- install/update KiCom
- deploy to test/staging/production
- mutate canonical memory or goals
- execute shell/SQL/arbitrary filesystem/arbitrary remote fetch
- approve or execute RED/production/kernel actions
- create another delegated job

Every step binds:

- build id
- trusted-source path
- exact current base SHA-256
- unique find/replace patch
- exact expected resulting SHA-256

A tick executes at most one patch. Any base/content/result mismatch stops the job. The complete stored plan is re-hashed on every tick and must still match `plan_sha256`. Jobs are append/history preserving: terminal records are retained, not hard-deleted. At most 16 active/uncertain delegated jobs are accepted.

## Authorization model

Job creation happens only after an ordinary normal-autonomy session has already been authenticated/consumed by the route. No rolling token or FreeOTP code is stored in the job. The protected record may retain the non-secret session id solely because the current Fast Build ownership API binds builds to a session id.

Delegation lifetime is bounded to the earlier of:

- original session absolute expiry
- 60 minutes after job creation

The job is also bound to the KiCom runtime version present at creation. A runtime/version change causes `JOB_RUNTIME_CHANGED` and no patch is executed.

Idle expiry of the chat session is intended not to revoke an already-created immutable job; that is the purpose of delegation. The delegated authority cannot expand beyond the prevalidated patch plan and ends before any release/finalize/trust-boundary operation.

**Promotion blocker:** explicit security revocation semantics are not yet resolved. Before promotion, a deliberate session/delegation revocation must invalidate outstanding jobs even when the runtime version is unchanged, without making ordinary idle expiry defeat delegation.

## Interrupt behavior

The local runner writes a durable checkpoint before and after every step. After a chat/platform interruption, status is reconstructed from the job record; the client does not need to reopen a normal session merely to learn whether a build patch completed.

States: `READY`, `RUNNING`, `UNCERTAIN`, `COMPLETED`, `FAILED`, `EXPIRED`.

A persisted `RUNNING` state observed by a later tick is **never retried**. It is converted to `UNCERTAIN` and requires inspection. This implements D024's rule that an ambiguous mutation is not blindly re-executed.

## Validation

CI regression currently covers:

- ordinary two-step execution, one patch per tick
- terminal retention / no hard delete
- forbidden non-build step rejection
- expected-result SHA mismatch failure
- expiry before execution
- `RUNNING -> UNCERTAIN` without re-execution
- immutable-plan hash verification / tamper rejection
- runtime-version binding
- absence of FreeOTP / rolling-token secret fields from job records and status

## Promotion rule

Do not promote this prototype with Resilience v5 automatically. It is a separate capability candidate. Promotion requires:

1. Resilience v5 installed and stable first.
2. Explicit revocation semantics resolved and tested.
3. Exact live Fast-Build ownership/lifecycle checks reviewed against the target KiCom version.
4. Isolated server validation with deliberate interruption tests.
5. No widening of RED, production, kernel, deploy, update, memory or arbitrary-execution authority.
