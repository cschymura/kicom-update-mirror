# KiCom Engram — DEV-03 filesystem/WAL/SHM hardening checkpoint

Date: 2026-09-20. Continuation of the verified DEV-02 checkpoint. This public document contains technical code/test proof only; no personal engrams, user conversations, server session IDs, access paths to private data, passwords, backup bytes or credentials.

## Tested implementation

`KiComEngramStore.php` on branch `work/kicom-0.9.27-pam` now applies a restrictive creation umask during SQLite initialization and checks that database, WAL and SHM files are regular single-link files with private permissions. Preexisting symlink sidecars are denied before opening SQLite. Existing live store operations fail closed if the private directory becomes too permissive, the database disappears, or present SQLite storage files become overpermissive or symlinks. Checks are run on store initialization, create/revise, search, backup and health paths.

`test-engram.php` adds synthetic negative coverage for preexisting WAL and SHM symlinks, readable WAL/SHM files, a downgraded private directory before open and while an already opened store is active. The original private backup/restore and all previous synthetic tests remain.

## Pinned executable CI

Code SHA: `da697beffcb2ec856bf216f2668a33c3138ca060`
Workflow: KiCom private Engram DEV
GitHub Actions run: **35502777477**
Job: **106057394757**
Conclusion: **success**.
PHP lint both source and test: passed. Existing R3 original package digest and source manifest preflight: passed. Exact test log marker: `KICOM_ENGRAM_TESTS_PASSED=49`.
Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35502777477

## Isolation, server-readiness and next steps

The private hosting directory created by the operator is not an Engram deployment target and has **not** been approved for real personal data. A 404 for an empty directory is not a positive proof that a concrete static PHP/SQLite/canary file could not be served, especially through alternative host mappings; a controlled harmless canary test, a full webroot/alias/realpath check and actual PHP effective-identity/filesystem checks are still required, using authorized local/staging channels. Do not write real private memories or mark the location safe from the observed 404 alone.

No original KiCom core, daughter/membrane or production files were modified. No personal memories, live chat content, SQLite private backup, runtime credentials or private host contents were transmitted to GitHub. Storage is still a standalone local DEV prototype, not an authenticated Engram service.

Next: validate a non-web reachable actual private destination and secure read/write from verified KiCom PHP identity; implement deletion/retention across live revisions, SQLite journal and all backup copies with synthetic negative tests; then enforce authorized subject/namespace identity-bound facade. Real memory import and production installation remain separately gated.

This checkpoint's documentation commit is not a new executable code test; the SHA and CI run above pin the tested implementation.
