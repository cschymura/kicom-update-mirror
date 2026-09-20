# KiCom Engram — DEV-14 real mid-copy SIGKILL and partial-snapshot quarantine

Date: 2026-09-20. Continue from DEV-13 under COLLABORATION-PROTOCOL.md and TASK-ENGRAM-PRIVATE-MEMORY.md. User requests autonomous isolated DEV until human action genuinely required. Public GitHub contains ONLY code, synthetic test fixtures and technical evidence. No actual engrams, personal conversation, private backup/DB bytes, OTP, secrets, live user IDs, consent, host config, or private operator anchors.

## Executable changes

- `KiComEngramMirrorSet::copyPrivateSnapshot()` now uses bounded incremental exact-length exclusive copy instead of an indivisible stream_copy_to_stream call. First 512 bytes are flushed and a DEV-only constant-phase callback may interrupt while a destination is PARTIALLY WRITTEN; maximum snapshot size remains 512 MiB. Further chunks must be complete; length and hash are checked before a generation manifest can be published. No new public HTTP route or capability.
- Fault phases `during-first-copy` and `during-second-copy` added to BOTH exception-based and actual Linux subprocess SIGKILL fixtures. Neither callback receives private paths, filenames, memory contents or source snapshots.
- The controlled tests demonstrate that an interrupted copy leaves one non-matching, exactly 512-byte orphan file. The existing independently pinned parent remains intact/degraded; the orphan is only counted by the read-only inventory and cannot be selected for restore. Explicit retry creates a separate new generation and restores complete synthetic revision history. No old copy, partial orphan, manifest or original source is overwritten/deleted.

## Exact CI evidence

Tested executable SHA **`37d140bf8b8ab5b68d80e9ef3fa2d0be59d5701c`**.
GitHub Actions run **35507005386**, job **106068409010**, conclusion **success**. Original protected KiCom 0.9.26-R3 package archive/source manifest preflight passed; PHP lint and all synthetic tests passed.
Suite markers: main 49 + path 23 + DEV route 23 + endpoint 14 + integrity 16 + ingestion 29 + mirror 46 + exception-fault 45 + real SIGKILL 44 = **289 synthetic checks**.
Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35507005386

## Unresolved issues and next safe DEV

- Concurrent rebuilds may both produce valid independent NEW generations. That is not data corruption, but without an independently managed promotion/anchor authority there must be NO automatic declaration of which generation is current. A future dev-only explicit exclusive generation lease (with bounded timeout, stale-lease operator review and no cross-UID promises) and concurrent subprocess tests should block ambiguous publication. No background scheduler or real unattended self-heal installed.
- A flushed 512-byte prefix surviving SIGKILL on the CI filesystem is NOT a proof of ACID durability under real power-loss, fsync, sudden disk corruption or off-host failover. Actual host constraints remain unverified.
- No external manifest signing/anchor service, physical/admin independent mirror, private host deployment, user-scoped full ingestion/retention/erasure, all-backup deletion, PHP same-UID isolation, actual HTTP alias testing or real-host restore drill. Live KiCom continues 0.9.26 and no personal memories were imported. Private directory is NOT a deployment target.

Documentation commit is not a new code-test run. Use pinned tested SHA/run.
