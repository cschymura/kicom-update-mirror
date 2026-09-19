# KiCom 0.9.27 – reproduction blueprint and independent daughter identity (DEV checkpoint)

Date: 2026-09-19
Repository: cschymura/kicom-update-mirror
Branch: work/kicom-0.9.27-pam
Prior checkpoint: development/0.9.27/membrane/CHECKPOINT-2026-09-19-OS-PINNED-ATTESTER.md

## User's corrected architecture objective

The independent membrane must protect each KiCom instance while allowing
reproduction. A daughter inherits a reproducible verified system design,
NOT the mother's running cryptographic identity, private keys, access grants,
memory, sessions or remote transport credentials. New independent host
provisioning remains a separately protected, operator-controlled boundary.

## Read-only live baseline

BOOTSTRAP, the six canonical resources, GENOME_STATUS, SQLITE_STATUS and
UPDATE_STATUS were read. Live KiCom remains 0.9.26, genome
kicom-0.9.26-g25r3, healthy/trusted/LKG OK, drift=unknown=0;
SQLite in WAL, quick_check=ok; no pending update. Canonical
PROJECT_STATE/NEXT still carry stale g25r2/old priorities and were not
silently rewritten through an unverified memory write path.
No live code, database, host, credentials, network or communication changed.

## Development implemented

1. development/0.9.27/reproduction/KiComReproductionBlueprint.php:
   canonical deterministic candidate manifest from the explicit public
   g25r3 baseline, original exact R3-package SHA-256
   6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f,
   public parent fingerprint and bounded daughter label. Exact positive
   schema binds transport contract (KCL/DEV/Slack/Mail/Opera/GitHub/
   Chat-Update/Recovery), independent provision requirements and
   explicitly excludes mother memory, private keys, sessions, grants
   and production access. No file/network/credential/keygen/deploy API.
   Even the canonical manifest verifier cannot certify archive bytes
   or authorize child deployment.
2. test-blueprint.php: 22 isolated positive and negative checks,
   including reproducibility, distinct daughter manifest, stale
   g25r2 rejection, package digest and tampered/rehashed inheritance
   rejection, refusal of changed transport contracts and forbidden
   deployment/credential inheritance.
3. test-isolated-identity.sh: on a disposable Linux runner with
   separate unprivileged OS principals, generate separate mother and
   daughter Ed25519 identities; assert mutual private-key read denial,
   daughter denial of mother state, protected policy/recovery denial,
   and unchanged baseline state/policy/recovery file hashes. Eight checks.
   Actual keys are runtime-only test artifacts, NEVER committed.
   NO complete daughter KiCom, live reproduction or remote host
   provisioning was performed.
4. .github/workflows/test-0927-membrane.yml:
   reproductions tests integrated with all prior R3
   KCL/HTTP/Slack/Mail/DEV communication regression, source-package
   SHA/manifest validation and isolated membrane/Linux/principal checks.
   Existing product router/authorization untouched.
5. development/0.9.27/reproduction/REPRODUCTION-PROTOCOL.md:
   staged lifecycle (blueprint, daughter genesis, independently
   approved host, real runtime, independent future development)
   and explicit distinction between inert candidate versus
   actual functioning daughter.

## Verified test results and correction history

- Failed GitHub CI RUN_ID 35453669241: bash syntax error in
  daughter-identity test fixture; no success claimed for this run.
- Failed RUN_ID 35453726630: an unprivileged GitHub-runner process
  could not read independently root-owned policy/recovery test paths.
  The test now uses the runner's controlled sudo read and a root-owned
  public-key output fixture; private daughter key remains separate.
- Final successful complete GitHub CI RUN_ID=35453780764,
  JOB_ID=105925379306, tested trigger/checkout SHA=
  007cf583f96d2e83653bd9c266815c813421b39f, conclusion=success.
  260 previous membrane/communications/OS checks +
  22 reproduction blueprint checks +
  8 independent identity/isolation checks =
  **290/290 successful isolated DEV checks**.
  CI link:
  https://github.com/cschymura/kicom-update-mirror/actions/runs/35453780764
- The present documentation-only commits are not executable
  retests; any later code change needs a new matching CI SHA.

## Still explicitly absent

No born or running daughter KiCom instance; no tested two-generation
reproduction, independent production membrane, non-bypassable network
egress, child-owned persistent SQLite/Memory/Recovery stack, real
user-authorized child host/account, real Slack/Mail/Passkey/Opera/Update
parity, actual production host UID separation, or protected child
release/rollback lifecycle. The tested blueprint references only the
verified 0.9.26-R3 archive: currently developing 0.9.27 membrane code
is not part of an executable daughter release. Copying a public manifest
or generating a keypair does not itself confer authority or complete
reproduction.

## Next safe task

Build a fully disposable, local child runtime candidate from an
independently verified package and a separate daughter-private data
directory, with all original external transports in inert/offline
test mode. Verify KCL health/communication parity against the mother,
isolated child state, preserved protected keys and backup/rollback
boundaries; prove negative attempts to access mother's memory and
protected recovery. No real external provisioning, no production
routing/messaging, no self-propagation or reused mother credentials.
Follow this with independent host and ingress/egress qualification
BEFORE any remotely deployed child can be described as born.
