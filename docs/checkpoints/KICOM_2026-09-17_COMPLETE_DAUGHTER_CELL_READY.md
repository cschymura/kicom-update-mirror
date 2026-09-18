# KiCom complete daughter-cell checkpoint — 2026-09-17

State: COMPLETE_DAUGHTER_IMPLEMENTED_AND_CI_GREEN; DEV full-restore artifact ready; live `/dev/` still partial and not yet restored.

## Canonical KiCom baseline

- Live KiCom runtime: `0.9.15`, genome `kicom-0.9.15-g16`.
- Live genome status verified healthy=true, trusted=true, LKG=true, drift=0, unknown=0.
- Canonical memory cleanup is complete: PROJECT_STATE, ARCHITECTURE, PROTOCOL, NEXT and CHANGELOG consistently describe 0.9.15/g16 and internal self-authoring autonomy.
- Historical internal TOTP/RED wording is retained only as implementation/history context; current canonical policy reserves human authorization for protected external boundaries when required.
- No hard-delete policy remains active; prior canonical revisions retain the superseded text.

## Complete daughter-cell requirement now implemented

PR #19 branch: `candidate/expansion-cell-v1`.
Head at this checkpoint: `418a0bb5d52f79dc701bd765d530251eff8fd8bd`.

A daughter is no longer a thin federation bootstrap. The package carries the full intrinsic runtime before deployment and `CellNode::initialize()` fails closed unless the daughter can materialize and verify its own living substrate.

New intrinsic subsystem implementation:

- `experimental/expansion-cell/CellLiving.php`
- `experimental/expansion-cell/cell-runtime/living-schema.json`
- `experimental/expansion-cell/cell-runtime/doctor.php`

Intrinsic birth now provides:

- child-local Ed25519 identity (created locally; never inherited);
- child-local canonical memory resources: PROJECT_STATE, ARCHITECTURE, PROTOCOL, DECISIONS, CHANGELOG and NEXT;
- isolated local workspace;
- append-oriented observer/diagnostic trace;
- managed-runtime genome plus local LKG copies;
- immune scan and exact LKG repair for managed runtime drift;
- inert evolution candidate area and deterministic static fitness checks;
- autonomous-internal evolution policy after deterministic fitness with local LKG rollback;
- separate protected-external authorization policy;
- self-description proving which intrinsic subsystems were present at birth.

Enrollment/activation establishes lineage and trust only. It does not add basic architecture or capabilities after birth.

## Atomic/fail-closed birth

`CellLiving` builds the intrinsic tree under a private staging directory and commits it by directory rename only after validation. `CellNode::initialize()` then commits the local signing secret, public node state and temporary enrollment material. If any required stage fails, the uncommitted birth state is rolled back.

`living_ready=true` requires all of:

- identity;
- canonical_memory;
- workspace;
- observer;
- genome_lkg;
- immune;
- evolution;
- zero detected managed-runtime drift.

The public child `status.php` exposes only safe readiness/health facts. `doctor.php` is read-only over HTTP; local immune healing is driven by the signed federation tick path.

## Package completeness guard

`ExpansionCellPackageBuilder` now physically requires and packages:

- `CellLiving.php`;
- child doctor endpoint;
- living schema;
- existing protocol/node/runtime endpoints;
- generated access-control files and bootstrap config.

`package-completeness-selftest.php` deliberately removes:

1. the intrinsic `CellLiving.php` runtime, and
2. the `immune` subsystem declaration,

and requires package generation to fail closed in both cases.

## Validation

On head `418a0bb5d52f79dc701bd765d530251eff8fd8bd`:

- KiCom Expansion Cell v1 run `35210732870`: SUCCESS.
- KiCom DEV Observer v1 run `35210732891`: SUCCESS.
- KiCom DEV zone checks run `35210732877`: SUCCESS.

The DEV workflow was corrected after the first green expansion run so that the generated full-restore bundle also contains `CellLiving.php`, `cell-runtime/doctor.php` and `cell-runtime/living-schema.json`.

## Verified DEV full-restore artifact

GitHub Actions artifact:

- run: `35210732877`
- artifact id: `10491194166`
- artifact name: `kicom-dev-zone-v1`
- head: `418a0bb5d52f79dc701bd765d530251eff8fd8bd`

Inner install ZIP:

- file: `kicom-dev-zone-v1.zip`
- SHA-256: `0efe121d05ed27bb81d57f54ec5139688da0141dabfcff1f2895c2bf4b2bcfac`

The artifact contains the complete code-only `dev/` tree and must preserve runtime state stored outside the public DEV code directory.

## Live state at checkpoint

- `https://kicom.rurtalbahn.info/dev/` is reachable and shows the static KiCom DEV page.
- `https://kicom.rurtalbahn.info/dev/dev-auth.php` returns 404.
- `https://kicom.rurtalbahn.info/dev/doctor.php` returns 404.

This exactly matches the documented partial-restore failure mode: static HTML survived while required PHP endpoints disappeared.

Live KiCom deploy targets currently exposed:

- `testportal` (test)
- `sandbox` (test)
- `kicomarchive` (staging)
- `updatefeed` (production)

There is no target explicitly identified as KiCom's own `/dev/` directory. `testportal` has prior deployment history for handoff files and must NOT be assumed to map to `/dev/` without authoritative configuration evidence.

No FTP/SFTP/All-Inkl connector is currently available in chat. Therefore the full DEV restore must not be written through a guessed target or an arbitrary filesystem workaround.

## Next exact step

Install the verified full code-only DEV restore ZIP into the existing KiCom webspace using a channel that authoritatively targets KiCom's `/dev/` code directory (hosting file manager, or a future explicit internal `dev` deployment target).

Then, in order:

1. verify `/dev/dev-auth.php`, `/dev/dev-api.php`, `/dev/dev-expansion.php` and `/dev/doctor.php` are live;
2. open a fresh passkey DEV session;
3. run the fixed sandbox preflight (`Sandbox prüfen`);
4. execute the fixed sandbox expansion (`Sandbox-Zelle starten`);
5. verify `https://sandbox.rurtalbahn.info/kicom/status.php` exists and reports `living_ready=true` with all intrinsic subsystems true;
6. verify existing sandbox content outside `/kicom/` is unchanged;
7. only then update the PR/checkpoint from "candidate" to live sandbox evidence.

## Security / history invariants

- Do not store OTPs, passkey material, DEV bearer/session values, FTP credentials, private signing keys or absolute hosting roots in GitHub/checkpoints.
- Child identity/secrets/mutable memory remain local to the child.
- Parent enrollment verifier is one-time and discarded after enrollment.
- GitHub remains development mirror/evidence, never KiCom authority.
- No hard delete of project experience; archive or supersede.
