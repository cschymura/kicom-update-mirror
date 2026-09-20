# KiCom 0.9.27 DEV — derivative runtime quarantine CI GREEN

Date: 2026-09-20. Branch: `work/kicom-0.9.27-pam`.
Scope: **GitHub isolated DEV CI only**. This checkpoint records a completed run on an exact code SHA; the checkpoint commit itself is documentation, not proof that a different code SHA was tested.

## Canonical production baseline observed in this cycle

Read KiCom `BOOTSTRAP`, then `PROJECT_STATE`, `ARCHITECTURE`, `PROTOCOL`, `DECISIONS`, `CHANGELOG` and `NEXT` and the four public statuses before GitHub work. Live `GENOME_STATUS`: version `0.9.26`, genome `kicom-0.9.26-g25r3`, healthy/trusted/LKG true, drift=0, unknown=0. Live `SQLITE_STATUS`: primary WAL, quick_check=ok. `UPDATE_STATUS`: no pending version. `SLACK_STATUS`: not configured (bot and signing credentials absent). `PROJECT_STATE` still contains historical `g25r2`; it is not accepted as current runtime identity or silently overwritten. No live production mutation was performed.

## Failure isolated and DEV fix already committed

Prior dedicated derivative-genome workflow run `35477784951` on `f39b97dc525b0f1470298f91d78222345d62da27` failed at reproduction quarantine despite the derivative runtime step passing. Commit `5c166ef7c1cfe6ba9e84c733764d48cb96badd31` (`ci: restore OS fixture ordering for reproduction quarantine`) changes only `.github/workflows/test-0927-derivative-genome.yml`: explicitly load PHP SQLite extensions and execute prerequisite OS fixture, isolated identity and daughter runtime tests **before** host-lineage/recovery/native birth gate. This restores the stateful fixture ordering instead of bypassing or weakening an assertion.

## Verified GitHub Actions result

- Workflow: `KiCom derivative genome integration`
- Run: `35490476558`; job: `106024433862`
- Exact checked-out and tested **code SHA**: `5c166ef7c1cfe6ba9e84c733764d48cb96badd31`
- Status: completed; conclusion: **success**; all steps successful
- Original unchanged `releases/0.9.26/KiCom-0.9.26-R3.zip` SHA-256 `6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f` verified; original R3 source MANIFEST verified.
- `test-derivative-genome.php`: **18 checks**; no syntax errors.
- `test-derivative-runtime.sh`: actual isolated localhost KCL `GENOME_STATUS` emitted new derivative child ID `kicom-child-g26-82e927b30f28b711443f7b57`, trusted/healthy/LKG, zero drift/unknown; independent host check validated signed genome and denied authority/deployment; original R3 source remained manifest-clean.
- Existing quarantine and OS-backed reproduction fixtures, run in their required stateful order, passed: child lineage 13; child birth gate 11; membrane OS boundary 6; isolated identity 8; base daughter runtime 9; host lineage 6; standalone daughter genesis SQLite recovery 10; legacy real-native birth gate 2. The legacy R3 *base candidate* is still correctly rejected as native-unbound. These are **83 reported individual checks in the dedicated workflow** (18 + 13 + 11 + 6 + 8 + 9 + 6 + 10 + 2); the derivative localhost runtime test is additionally successful but not represented by a numbered check count.

The passing job has a Node.js 20 deprecation warning for checkout tooling, not a test failure. No assertion is made here that the earlier separate 341-check **entire membrane workflow** was rerun on this SHA; this checkpoint documents the dedicated derivative-genome workflow only.

## Explicit boundaries / next step

**GREEN means the dedicated DEV CI workflow is fixed, not that a child is fully born or production-ready.** The tested derivative runtime is an ephemeral localhost copy. Its host-side signing key in the fixture is not yet demonstrated as an independently operated daughter native Genome/Recovery/Auth key across reboots. The separate unchanged R3 base-candidate birth gate correctly remains closed. Native child operational SQLite (distinct from private genesis.sqlite), child-owned LKG/recovery, non-bypassable egress, complete independent bidirectional KCL/DEV/Passkey/Slack/Mail/Opera/GitHub/Chat/Update parity and independent external hosting remain separate unverified stages. Do not deploy, inherit parent secrets/privileges or treat a green derivative status as external authority.

Next DEV cycle: integrate the derivative identity into the actual native child verifier and lifecycle *without modifying or weakening the original R3 release/genome verifier*, prove durable independent protected child key and child-native operational SQLite/recovery, and run **both** the complete unchanged membrane suite and the dedicated derivative suite on the exact tested SHA before considering any stronger milestone. Preserve all backup/healthcheck/rollback/LKG and human authorization boundaries.
