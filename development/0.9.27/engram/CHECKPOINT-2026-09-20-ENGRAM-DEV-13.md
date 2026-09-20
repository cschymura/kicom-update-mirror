# KiCom Engram — DEV-13 actual subprocess SIGKILL mirror-repair tests

Date: 2026-09-20. Follows DEV-12. Re-read live BOOTSTRAP and canonical KiCom resources (live baseline 0.9.26), collaboration protocol and task. User requested autonomous safe internal DEV until real human action required. Public GitHub includes ONLY technical code, synthetic tests and verifiable proof metadata. NO actual personal memories, original chats, private host or backup bytes, OTPs, credentials, user identifiers, operator anchors or private retrieval content.

## New executable test evidence

- `test-engram-kill-child.php` is a CLI-only SYNTHETIC CI fixture with exact argument and fixture-directory checks. It invokes the DEV rebuild operation and emits only a constant phase label when ready; does not reveal a private path, DB content or credential. The child waits for parent termination and fails the test if allowed to finish on its own.
- `test-engram-kill.php` launches an actual separate PHP process for each of five phases: before first mirror copy, after first copy, after second copy, before manifest publication, after manifest publication. The parent receives the deterministic phase marker, sends real Linux SIGKILL (signal 9) to the child, verifies the process was signaled, reopens independently anchored parent generation, checks orphan file counts and all original bytes, and performs explicit new-generation retry and read-only recovery of the synthetic revision history. Unknown orphan bytes/manifests are never selected or deleted.
- CI driver includes this suite after the 231 DEV-12 tests. This is actual **process termination**, not merely a caught exception. It is still NOT electrical power-loss, crash inside a partially written chunk, filesystem durability proof, process-concurrency or actual host-side validation.

## Pinned executable CI

Tested code SHA: **`595ec3f8a2954b22ef6ac2cb32fbcb1a517c9605`**.
GitHub Actions `KiCom private Engram DEV` run **35506904196**, job **106068147724**, conclusion **success**. Original 0.9.26-R3 package/archive and entire original source manifest unchanged and preflight passed; PHP source/test-driver lint and all suites passed.
Markers: main 49 + path 23 + dev-route 23 + endpoint 14 + integrity 16 + ingestion 29 + mirror 46 + interrupted-exception 31 + actual SIGKILL 30 = **261 synthetic checks**.
Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35506904196

## Remaining blockers

- No tested kill INSIDE a partially written mirror chunk yet; next safe internal DEV task is bounded chunk-by-chunk exclusive file copy with a deterministic mid-copy phase hook and actual synthetic SIGKILL; assert a partially written orphan is never treated as a recoverable snapshot, never overwritten on retry and is listed for operator quarantine.
- No actual independent protected digest anchor, process-level concurrent rebuild locking/leader election, off-host replica or physical/account isolation. SQLite data/backup erasure and retention across all old immutable generations, WAL/SHM and external copies remain UNIMPLEMENTED. No automatic self-healing or background schedule.
- No actual server upload or KiCom endpoint activation, no verified PHP UID/open_basedir/default-host alias separation, no real memory import and no operator-controlled real host restore. A 0700 directory or synthetic CI success does NOT establish separation from applications running under the same hosting UID.
- The user may authorize a separate controlled private host probe/release when the required test package, independent manifest trust plan, retention requirements and real hosting constraints are reviewed. Never create a public deployment target for the private engram folder.

Checkpoint commit is documentation-only. Tested SHA and run above are executable proof.
