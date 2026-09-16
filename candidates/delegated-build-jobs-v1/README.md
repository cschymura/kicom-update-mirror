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

A tick executes at most one patch. Any base/content/result mismatch stops the job. Jobs are append/history preserving: terminal records are retained, not hard-deleted.

## Authorization model

Job creation happens only after an ordinary normal-autonomy session has already been authenticated/consumed by the route. No rolling token or FreeOTP code is stored in the job. The protected record may retain the non-secret session id solely because the current Fast Build ownership API binds builds to a session id.

Delegation lifetime is bounded to the earlier of:

- original session absolute expiry
- 60 minutes after job creation

Idle expiry of the chat session does not revoke an already-created immutable job; that is the explicit purpose of delegation. The delegated authority cannot expand beyond the prevalidated patch plan and ends before any release/finalize/trust-boundary operation.

## Interrupt behavior

The local runner writes a durable checkpoint after every step. After a chat/platform interruption, status is reconstructed from the job record; the client does not need to reopen a normal session merely to learn whether a build patch completed.

States: `READY`, `RUNNING`, `COMPLETED`, `FAILED`, `EXPIRED`.

## Promotion rule

Do not promote this prototype with Resilience v5 automatically. It is a separate capability candidate and requires isolated server validation plus explicit architectural review because it delegates bounded authority beyond ordinary session idle.
