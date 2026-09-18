# KiCom checkpoint — Expansion/Federation implementation start

Date: 2026-09-16
Status: implementation started

## Durable project memory

The active KiCom goal is now Expansion Cells / Federation: given an operator-authorized target domain and temporary FTP/FTPS access, KiCom shall bootstrap a child installation into `kicom/`, enroll it into a signed parent/child trust relationship, discard bootstrap credentials/material, and drive the child through signed HTTPS federation ticks relayed by the parent cron.

This goal is documented canonically for development in:

- `docs/goals/KICOM_EXPANSION_FEDERATION.md`
- `docs/goals/KICOM_EXPANSION_FEDERATION.json`

Preserve this objective and its history. Superseded implementation approaches should be archived instead of destructively deleted unless the operator explicitly asks otherwise.

## Implementation started

Branch: `candidate/expansion-cell-v1`
Base: `candidate/dev-zone-v1`

Initial code:

- `experimental/expansion-cell/ExpansionProtocol.php`
  - Ed25519 identities
  - canonical signed federation envelopes
  - one-time enrollment token verifier/proof
  - freshness, sender, receiver and payload-hash verification
- `experimental/expansion-cell/ExpansionRegistry.php`
  - parent-side expansion transaction registry
  - prepare/deployed/reachable/enroll/active lifecycle
  - one-time enrollment verifier removal
  - parent/root/generation lineage
  - child registry and revocation
- `experimental/expansion-cell/ExpansionFtpDeployer.php`
  - transient bootstrap upload
  - FTPS preferred
  - plaintext FTP only with explicit opt-in
  - stage-directory upload followed by rename to `kicom`
  - no credential persistence/logging
- `experimental/expansion-cell/CellNode.php`
  - child-local signing identity generation
  - enrollment hello/proof
  - signed activation by the expected parent
  - bootstrap enrollment material removal after activation
  - signed `FEDERATION_TICK` / status request handling
- `experimental/expansion-cell/expansion-selftest.php`
  - end-to-end parent -> child -> enroll -> activate -> cron tick -> signed reply test
  - invalid proof, replay and stale-message rejection
- `.github/workflows/expansion-cell-v1.yml`
  - syntax, protocol selftest, secret-persistence guard and authority boundary checks

## Not yet live

This is candidate code only. It is not installed into live KiCom and has not yet received real FTP credentials or deployed to a real target host.

Next implementation stages:

1. child-cell runtime HTTP endpoints (`bootstrap/status/federation`);
2. package builder producing the minimal deployable `kicom/` tree;
3. parent-side expansion orchestrator connecting prepare -> FTPS deploy -> probe -> enroll -> activate;
4. parent cron federation scheduler;
5. first controlled deployment to an operator-provided test domain/webspace;
6. only after that, integration into live KiCom's DEV/control plane.
