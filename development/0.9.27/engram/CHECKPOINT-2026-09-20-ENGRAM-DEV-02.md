# KiCom Engram — DEV-02 verified private backup/restore handoff

Date: 2026-09-20. Continuation of `CHECKPOINT-2026-09-20-ENGRAM-DEV-01.md`. Source of development truth: branch `work/kicom-0.9.27-pam`; live KiCom BOOTSTRAP/canonical resources remain the separate operational authority.

## Verified executable changes

- `KiComEngramStore.php`: DEV-only, caller-initiated `backup(backupDirectory, publicDocumentRoot)` makes a **consistent** SQLite `VACUUM INTO` snapshot of committed rows, including records still in the source WAL. Destination directory must already exist, have private permissions and be outside the resolved webroot; disallow symlink directory components. Backup files use a random filename, mode 0600, single-link check, `quick_check`, revision count and SHA-256 verification.
- `KiComEngramStore::restore(backupPath, destinationDirectory, publicDocumentRoot, expectedSha256)`: independent operator-supplied digest is mandatory; source directory/file and destination must be private and outside webroot; reject symlinks, excessive permissions, digest mismatch or existing destination .sqlite/-wal/-shm. Copy into exclusive mode-0600 temp file, re-hash; atomic no-clobber hardlink establishes the destination; reopen and run SQLite health check.
- `test-engram.php`: synthetic-only roundtrip, committed-record consistency and denials of webroot backup, checksum mismatch, existing-database overwrite, symlink backup source/dir and overpermissive backup source/dir.
- Unmodified source/package preflight is still enforced by the original dedicated workflow. No live credentials, conversations, actual memories, private backups or database contents were committed.

## Pinned CI evidence (not a documentation-only test)

Tested source SHA: `b06a4fd03772940852b94ea4665cab3ec8dd05c4`
GitHub Actions workflow: `KiCom private Engram DEV`; run **35501258674**, job **106053307166**, conclusion **success**.
Job logs confirm PHP lint on both PHP files, unchanged source + package digest preflight, and `KICOM_ENGRAM_TESTS_PASSED=41` with no failed synthetic checks.
Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35501258674

## Explicit unimplemented boundaries / next work

- Milestone DEV-2 is **not** complete: independently administered backups, rotation, retention, restore drills on the actual authorized private host, journal/-wal/-shm permissions and failure-path/concurrent-restoration hardening remain to verify. A digest is not an authenticity signature; the trusted expected SHA must be held separately from the copied backup.
- `backup()` preserves **all** revision bodies including withdrawn entries. Withdrawal is not erasure. No real personal data may be ingested before policy and tests cover selective hard deletion when required, WAL/temp remnants, every retained backup, remote copies and operator-controlled deletion, alongside default historical archiving.
- No authorization is provided by an arbitrary subject/namespace argument or directory mode. Engram has no KiCom identity-bound DEV API or public HTTP endpoint; no private memory ingestion, chat roundtrip, production installation or private host filesystem provisioning occurred.
- Next DEV actions: add bounded deletion/retention design and negative tests; validate private WAL/-shm handling and restore atomicity; integrate only behind established identity/capability checks after an independently verified KiCom DEV route. Keep public GitHub free of actual memory and keep existing daughter/membrane/production authority boundaries intact.

This checkpoint is technical and synthetic only. Its documentation commit does **not** alter the pinned tested-code SHA.
