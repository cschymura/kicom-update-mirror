# KiCom Reproduction – Three validated development cycles

Date: 2026-09-19. Repository cschymura/kicom-update-mirror, DEV branch work/kicom-0.9.27-pam.
Previous checkpoint: development/0.9.27/reproduction/CHECKPOINT-2026-09-19-LOCAL-DAUGHTER-RUNTIME.md.

## Canonical baseline and scope

Before these changes BOOTSTRAP, PROJECT_STATE, ARCHITECTURE, PROTOCOL, DECISIONS,
CHANGELOG, NEXT, GENOME_STATUS, SQLITE_STATUS and UPDATE_STATUS were read.
Productive KiCom remains 0.9.26 / kicom-0.9.26-g25r3, healthy, trusted, LKG
OK, genome drift=0/unknown=0; production SQLite WAL quick_check=ok; no pending
update. Canonical PROJECT_STATE/NEXT have some old g25r2/installation text,
NOT used as current product identity and not silently rewritten.

Everything added below is under development/0.9.27/reproduction/ on GitHub
and tested on a disposable Linux Actions runner. Original R3 package digest
6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f
and R3 MANIFEST.sha256 are checked by the existing CI. No change to the
live KiCom mother, authentication, secrets, Slack/Mail/Opera/GitHub/DEV,
production SQLite, KCL routing, recovery, or update feeds. No actual external
messages sent or daughter host provisioned.

## Cycle 1: independent daughter key-possession and lineage

- KiComChildLineage.php: fixed-format key-possession challenge binds a fresh
  nonce, PUBLIC parent key, distinct PUBLIC daughter key and the pinned original
  R3-package digest. candidateRecord returns only a public fingerprint-based
  LAB_LINEAGE_CANDIDATE_ONLY with native_genome_bound=false,
  recovery_bound=false, independent_host_qualified=false and no deployment
  or external action grant. It contains no parent private key, memory or
  credentials, and it does not mutate the native R3 genome.
- test-child-lineage.php: 13 positive/negative unit checks including nonce,
  parent/child/other-key substitution, source change, stale g25r2 and absence
  of authority-issuing or spawning methods.
- test-child-host-lineage.sh: the separate kicom_daughter_ci principal
  demonstrates possession of its original daughter private signing key by
  signing a fresh host-owned challenge. A host-root verifier compares the
  signed byte string and stores an immutable candidate fingerprint record.
  Neither the daughter nor the mother can overwrite it. Six isolated OS
  checks; NO certified native R3 identity or protected birth authority issued.
- GitHub Actions RUN_ID 35459137123, JOB_ID 105939701103,
  exact tested checkout SHA 7d86fba8b124db5c21c93d8344ac86560d6fec6f,
  conclusion=success, 317/317 isolated tests.

## Cycle 2: separate daughter SQLite genesis and non-destructive recovery

- test-daughter-recovery.sh initializes a NEW private daughter-owned SQLite
  genesis.sqlite. Its singleton genesis row binds public daughter and parent
  fingerprints, source package digest, and the SHA-256 of the host-owned
  candidate record. This is a STAGING-SPECIFIC genesis DB, distinct from the
  original R3 native operational SQLite schema and not copied from production.
- WAL and quick_check are verified. A root-only recovery principal creates
  a consistent standalone VACUUM INTO snapshot inside its independent
  recovery directory. Both mother and daughter are denied raw access to that
  recovery snapshot.
- A separate disposable copy is deliberately corrupted and rejected;
  recovery from the protected snapshot is validated into another separate
  daughter-owned file. Identity/package hashes match; original daughter
  DB, mother state and root recovery anchor remain unchanged.
- GitHub Actions RUN_ID 35459219113, JOB_ID 105939927032,
  tested SHA 56fdec3d720b007ab3a571957bad6e909bdb3b80,
  conclusion=success, 327/327 isolated tests (10 new).
- This is NOT a proven native KiCom SQLite schema restore, machine-power-loss
  test, or production recovery controller.

## Cycle 3: read the ACTUAL native Genome and fail closed on false birth

- test-daughter-runtime.sh now invokes the real isolated R3
  localhost index.php?q=GENOME_STATUS in addition to previously proven PING
  and HELLO. A healthy native-genome HTTP response or a legitimate 503 is
  captured as read-only KCL evidence. Actual R3 still reports the original
  kicom-0.9.26-g25r3 release genome, NOT a cryptographically bound new child.
- KiComChildBirthReadiness.php is a PURE read-only diagnostic. It reports
  NATIVE_GENOME_STILL_RELEASE_PARENT_UNBOUND for the historical R3 identity
  and refuses birth, genome, recovery, egress, communication and deployment
  qualification. It likewise refuses fake child-bound flags, missing KCL,
  malformed identifiers and mere textual genome-ID changes. It never
  changes the original R3 verifier or issues an approval.
- test-child-birth-gate.php: 11 synthetic fail-closed checks.
  test-real-native-birth.php: 2 checks cross-correlating the actual localhost
  KCL status and independent host lineage candidate. The runtime script
  adds 1 actual native-genome inspection check.
- GitHub Actions RUN_ID 35459386023, JOB_ID 105940373336,
  exact tested checkout SHA 8de0185d0093df1f802a926753554a8e5134ec64,
  conclusion=success. Complete existing membrane/communication/OS tests
  plus three cycles above: **341/341 isolated tests passed**.
  https://github.com/cschymura/kicom-update-mirror/actions/runs/35459386023

## What the three cycles DO and DO NOT show

There is a running disposable localhost R3 software candidate under a distinct
Linux UID with separated keys and private var/. There is a host-owned public
lineage receipt, separate daughter genesis SQLite and independent laboratory
backup/restore. Original KCL PING and HELLO work in that local candidate.
The real original R3 GENOME_STATUS STILL identifies the unmodified R3 release
genome. The candidate is not an independently born, cryptographically
genome-bound, multi-generation self-reproducing KiCom. A valid local challenge
signature is not an independent host birth certificate. The separate genesis
DB is NOT the native R3 operational state, and this Linux Actions runner is
not evidence that production ALL-INKL offers distinct PHP workers, separate
trusted controllers, or non-bypassable network ingress/egress. Production
0.9.26 remains untouched.

## Next explicit technical milestones

1. Develop an isolated derivative/genesis adapter which uses a distinct
   daughter-native genome identifier *without violating the original
   package/Genome verifier*. Bind child public key and package/manifest
   through a separately protected trust root and ensure that the canonical
   KCL GENOME_STATUS reflects the new independent identity rather than the
   old parent seed. An independent host-owned birth attestation must
   distinguish verified startup from arbitrary user-provided candidate data.
2. Exercise the ACTUAL native child SQLite operational schema, protected
   backups/LKG and restore after process restart/crash/unknown outcome;
   do not mistake the standalone genesis.sqlite for native SQLite.
3. Add tests for complete authenticated and bidirectional KCL/DEV/Passkey,
   Slack inbound/outbound, Mail IMAP/SMTP, Opera, GitHub, Chat/Update,
   binary streams and session/token rotation with preserved permissions.
   Actual external sends only to expressly authorized test destinations.
4. Only after fully independent host trust/Recovery/Policy/egress and
   native-genome integration, repeat the procedure from the first independent
   daughter to a second generation and prove the daughter's own
   reproducibility. No automatic external provisioning, private-memory
   copying, privileged action promotion, or FreeOTP request for internal DEV.
5. For every executable change rerun exact R3 package/source manifest and
   full CI, verify trigger/tested SHA and leave an honest, permanent checkpoint.

The current milestone is a *locally running, OS-isolated daughter CANDIDATE*,
not an autonomously born instance or an activated production membrane.
