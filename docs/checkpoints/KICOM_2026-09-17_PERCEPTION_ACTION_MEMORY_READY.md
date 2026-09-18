# KiCom checkpoint — Perception / Action Memory ready

Date: 2026-09-17
Branch: `candidate/expansion-cell-v1`
Implementation head before this checkpoint: `02fd7e1a7eea08bc5e6d64dd22526403277f3f13`

## Canonical direction

The parent KiCom `NEXT` memory was updated live before implementation:

- Perception + Action Memory is the current primary cell-architecture priority.
- Complete daughter-cell birth is recorded as completed.
- Practical autonomous executable evolution promotion is intentionally deferred until Perception/Action Memory is live-verified.
- Internal autonomy remains bounded; protected external systems/credentials remain external authorization boundaries.
- Human interruption should be minimized and related protected-boundary requests bundled.
- No hard delete of retained project history or cell experience.

## Implemented cell perception

Each daughter cell now has an intrinsic persistent perception subsystem answering, from evidence rather than assumption:

- Where am I?
- What local environment/territory am I in?
- What can I access?
- What capabilities are actually evidenced?
- What neighbors/peers do I know?
- What is unknown rather than unavailable or forbidden?

Explicit perception states:

- `UNKNOWN`
- `AVAILABLE`
- `UNAVAILABLE`
- `FORBIDDEN`
- `DEGRADED`
- `STALE`

Persistent files include:

- `living/perception/current.json`
- `living/perception/neighbors.json`
- `living/perception/history.jsonl`
- `living/perception/changes.jsonl`

The history files are append-only. Current views may be updated; retained observations are not hard-deleted.

## Implemented cell action memory

Each cell derives an action model from current perception and stores outcomes rather than merely declaring capabilities.

Persistent files include:

- `living/action/model.json`
- `living/action/boundaries.json`
- `living/action/expansion-opportunities.json`
- `living/action/history.jsonl`

The action model distinguishes internal authority, signed-parent-only actions, protected external boundaries, hard forbidden boundaries, and safely extendable internal capability gaps.

A boundary is information, not merely `false`. It records whether it is extendable and how, for example:

- missing internal software -> build/test an inert candidate;
- unknown environment -> observe rather than assume;
- temporary unavailability -> observe/retry later;
- protected external system/credential -> preserve the human authorization boundary;
- arbitrary filesystem or arbitrary remote fetch -> forbidden, not silently broadened.

## Perception–Action–Memory loop

The cell now implements the persistent loop:

`observe -> remember -> derive capabilities/boundaries -> act -> observe result -> update perception/action memory`

Birth, activation and signed federation ticks are integrated with the loop. A federation tick can refresh local/peer evidence and record the resulting action without granting new authority.

## Intrinsic architecture

`perception` and `action` are now required intrinsic Living subsystems alongside:

- identity
- canonical memory
- workspace
- observer
- genome/LKG
- immune
- evolution candidate substrate

A newly generated cell is fail-closed if perception/action memory cannot be materialized.

`living-schema.json` explicitly declares `perception_action_memory=true` and contains the allowed perception states and loop definition.

## Evolution pause

Candidate creation and deterministic static fitness remain available, but executable autonomous promotion is deliberately marked `DEFERRED` until this perception/action layer is live-verified. This preserves the previously requested pause rather than silently continuing autonomous promotion work.

## Existing-cell migration

`ExpansionManagedCellUpdater` now supports a managed Living v1 -> Living v2 Perception/Action upgrade.

Properties:

- cell ID preserved;
- signing identity preserved;
- root/parent lineage preserved;
- existing mutable `var/` state preserved;
- prior Living tree snapshotted under `var/living_snapshots/` before rebaseline;
- previous genome/LKG archived and predecessor-linked;
- rollback restores the prior active Living snapshot;
- the rolled-back new Living tree is archived under `var/living_failed/`, not deleted.

This follows the project rule that retained history may be archived/superseded but not hard-deleted.

## Validation

Green CI on implementation head `02fd7e1a7eea08bc5e6d64dd22526403277f3f13`:

- KiCom Expansion Cell v1 run `35222488887`: SUCCESS
- KiCom DEV zone checks run `35222488698`: SUCCESS
- KiCom DEV Observer v1 run `35222488717`: SUCCESS

The Expansion tests cover birth, perception/action readiness, explicit state vocabulary, signed federation tick integration, immutable boundaries, history persistence, Living snapshot/rebaseline and rollback.

## Install artifact

Direct-document-root DEV restore artifact generated from the green CI bundle:

- filename: `KiCom-DEV-Full-Restore-Perception-Action-2026-09-17.zip`
- files: 41
- SHA-256: `0a779892d39693d163ba176a4a399a66915bed4b76df70220a23ad076fac9d75`

The archive has `dev/` as its top-level directory.

## Live state / next exact step

The live sandbox daughter remains on the previously verified Living v1 state until the new DEV restore is installed and its existing managed `Living-Unterbau aktualisieren` action is executed.

Do not claim Perception/Action Memory is live on the sandbox before verification.

Next:

1. Extract the direct DEV restore archive into the KiCom document root (result: `dev/...`, never `dev/dev/...`).
2. Use the existing authenticated DEV action `Living-Unterbau aktualisieren` for the fixed allowlisted `sandbox` resource.
3. Verify live: `living_ready`, `perception_action.ready`, nine required intrinsic subsystems, explicit perception-state vocabulary, perception/action history, LKG/drift, identity/lineage preservation, and successful signed federation tick.
4. Only after live verification, align canonical ARCHITECTURE / PROTOCOL / DECISIONS / CHANGELOG / PROJECT_STATE with the verified implementation.
