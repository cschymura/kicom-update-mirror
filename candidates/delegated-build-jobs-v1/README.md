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

Every step binds build id, trusted-source path, exact current base SHA-256, unique find/replace patch, and exact expected resulting SHA-256. A tick executes at most one patch. Any base/content/result mismatch stops the job. The complete stored plan is re-hashed on every tick and must still match `plan_sha256`. Terminal records are retained rather than hard-deleted. At most 16 active/uncertain delegated jobs are accepted.

## Authorization model

Job creation happens only after an ordinary normal-autonomy session has already been authenticated/consumed by the route. No rolling token or FreeOTP code is stored in the job. The protected record may retain the non-secret session id solely because the current Fast Build ownership API binds builds to a session id.

Delegation lifetime is bounded to the earlier of the original session absolute expiry or 60 minutes after job creation. The job is also bound to the KiCom runtime version present at creation. A runtime/version change causes `JOB_RUNTIME_CHANGED` and no patch is executed.

Ordinary chat/session idle is intentionally separate from deliberate security revocation. Delegation may survive ordinary idle within its own bounded lifetime, but a deliberate KiCom session revocation must terminate it.

### Explicit revocation epoch

The candidate reuses KiCom's existing `kicomAutonomyRevokeSessions()` security event rather than adding another credential or revocation authority:

- a protected local delegation epoch starts at 1;
- job creation stores the current epoch;
- normal idle does not rotate the epoch;
- explicit session revocation integrates a call to `kicomDelegatedBuildRotateRevocationEpochV1()`;
- every later tick compares the stored and current epochs;
- mismatch moves the job to terminal `REVOKED` / `JOB_REVOKED` before any patch is attempted.

No token, TOTP, recovery bearer, or reusable authorization secret is introduced by this mechanism.

## Interrupt behavior

The local runner writes a durable checkpoint before and after every step. After a chat/platform interruption, status is reconstructed from the job record; the client does not need to reopen a normal session merely to learn whether a build patch completed.

States: `READY`, `RUNNING`, `UNCERTAIN`, `REVOKED`, `COMPLETED`, `FAILED`, `EXPIRED`.

A persisted `RUNNING` state observed by a later tick is **never retried**. It is converted to `UNCERTAIN` and requires inspection. This implements D024's rule that an ambiguous mutation is not blindly re-executed.

## Validation

CI regression is green for:

- ordinary two-step execution, one patch per tick
- terminal retention / no hard delete
- forbidden non-build step rejection
- expected-result SHA mismatch failure
- expiry before execution
- `RUNNING -> UNCERTAIN` without re-execution
- immutable-plan hash verification / tamper rejection
- runtime-version binding
- explicit revocation-epoch rotation causing `JOB_REVOKED` before a patch
- absence of FreeOTP / rolling-token secret fields from job records and status

## Promotion rule

Do not promote this prototype with Resilience v5 automatically. It remains a separate capability candidate. Promotion requires:

1. Resilience v5 installed and stable first.
2. Exact live Fast-Build ownership/lifecycle checks reviewed against the target KiCom version.
3. The live `kicomAutonomyRevokeSessions()` path explicitly integrated with epoch rotation and regression-tested server-side.
4. Isolated server validation with deliberate interruption and revocation tests.
5. No widening of RED, production, kernel, deploy, update, memory or arbitrary-execution authority.
