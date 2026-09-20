# KiCom Engram — DEV-10 immutable RAID-1-inspired mirror/recovery prototype

Date: 2026-09-20. Resume after DEV-09 under `development/0.9.27/COLLABORATION-PROTOCOL.md` and `TASK-ENGRAM-PRIVATE-MEMORY.md`. USER REQUEST: adapt disk RAID principles to databases for integrity and fault recovery. This is a SYNTHETIC, isolated DEV implementation, NOT physical RAID, NOT production, NOT complete deletion/retention, NOT an assertion of independent host availability. PUBLIC GitHub holds only program code, synthetic fixtures and CI/proof metadata; no actual engrams, private database/backup bytes, source conversations, credentials, host configuration, consent records, runtime secrets, or private retrieval results.

## Live baseline and predecessor

Re-read live KiCom BOOTSTRAP/PROJECT_STATE/ARCHITECTURE/PROTOCOL/DECISIONS/CHANGELOG/NEXT: reported production version remains 0.9.26. Previous verified Engram milestone DEV-09: executable SHA `3adc626a2d084be98f6ebc2fde9241e8c738d729`, CI 35505457069, 154 synthetic checks. Existing R3 source/package preflight remains unchanged and passed again in this cycle. No KiCom DEV/production service, user WebFTP directory, real private DB or operator-host runtime was modified.

## New executable code

- `KiComEngramMirrorSet.php` provides an isolated, caller-invoked RAID-1-inspired **offline immutable snapshot set**. A single consistent, revision-audited `KiComEngramStore::backup()` result (already uses SQLite VACUUM INTO to include committed WAL changes) is byte-copied to a second independently provisioned, 0700 private directory. The two 0600 single-link snapshot files must have identical SHA-256. This mirrors the exact same transaction-level snapshot; two separately timed SQLite backups are NOT mistakenly treated as one generation.
- A third 0700 directory contains a unique no-clobber JSON manifest (0600) with format, random generation ID, snapshot filename, SHA-256 and revision count. No memory body, source reference or absolute host path is included. Manifest **digest must be independently preserved by a trusted operator/channel OUTSIDE the three mirror directories**; the class returns it but intentionally reports `independent_manifest_anchor_stored=false`. Files and manifests left by a failed partial generation are NOT eligible for recovery without an independently anchored, fully verified manifest.
- `inspect(manifestName, trustedDigest)` rejects malformed/traversal names, unanchored/wrong/tampered manifest, unexpected schema and unsafe snapshot links/permissions. It reports `mirrored` (2 matching copies), `degraded` (1 matching copy), or `unrecoverable` (0). Two equal files are NOT considered authentic merely because they agree: the independently trusted digest is mandatory. This is failure detection, not majority voting or an online quorum.
- `recover()` selects only a manifest-verified copy and uses the existing private SHA-checked/full-revision-audited SQLite `restore()` to create a NEW database inside a PRE-EXISTING, EMPTY 0700 directory. It never replaces live DB, either mirror, damaged copies or earlier generations. It fails closed if no verified copy or an existing destination file/DB is present. No automatic overwrite or deletion of questionable bytes.
- Constructor and every mirror operation revalidate peer/manifest directory isolation, permissions and symlink path components; rejects identical/ancestor/overpermissive/webroot locations, post-initialization mode downgrade and nonempty restore target. No public HTTP handler, arbitrary request path or new KiCom capability was added.

## Verified tests and proof

New `test-engram-mirror.php` is invoked by existing `test-engram.php` through the unmodified dedicated Engram workflow. Synthetic-only tests cover exact mirrored consistent snapshot, private manifests with NO bodies/paths, independent manifest digest, traversal, normal restore preserving historic/latest revisions, no-clobber, one-copy degradation and restore from surviving copy, both-copy loss fail-closed, tampered manifest rejection even with intact DB copies, immutable generations, permission/symlink/ancestor/webroot denials, post-init permission downgrade, and all-preexisting-file preservation.

**Exact tested executable SHA: `eb3a9b998641bf7884c6b658da13912563a5358d`**.
GitHub Actions `KiCom private Engram DEV`, run **35505739252**, job **106065123998**: conclusion **success**. Original 0.9.26-R3 source manifest/package preflight PASSED; PHP source/test-driver lint PASSED; complete synthetic regression PASSED.
Suite markers:
- `KICOM_ENGRAM_TESTS_PASSED=49`
- `KICOM_ENGRAM_PATH_PROBE_TESTS_PASSED=23`
- `KICOM_ENGRAM_DEV_ROUTE_TESTS_PASSED=23`
- `KICOM_ENGRAM_ENDPOINT_TESTS_PASSED=14`
- `KICOM_ENGRAM_INTEGRITY_TESTS_PASSED=16`
- `KICOM_ENGRAM_INGEST_TESTS_PASSED=29`
- `KICOM_ENGRAM_MIRROR_TESTS_PASSED=30`
= **184 synthetic checks**.
Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35505739252

## Explicit limits and next safe development steps

1. This is application-level, immutable snapshot mirroring on potentially **the same filesystem/account**; it neither survives a full hosting-account compromise/disk loss nor isolates from other PHP apps sharing its effective UID. File mode 0700 is insufficient against same-UID applications. Separate physical/administrative/off-host stores and a separately protected manifest trust anchor are prerequisites for high availability and attack-resistant authenticity. Their provisioning, privacy/security review, cost/permission and live transfer remain UNAPPROVED/UNIMPLEMENTED.
2. Initial mirror set is NOT automatic background RAID, continuous write replication, distributed consensus, parity/RAID-5, version-retention scheduler, complete scrub or in-place auto-heal. A damaged mirror can currently be recovered into a separate empty store. Next isolated DEV: implement bounded, explicit `rebuildNewGeneration()` from a single anchored intact snapshot to NEW filenames/manifests without touching damaged copies; preserve source-generation provenance and test crash/partial-write scenarios. Do not silently promote a copy merely because it is newest.
3. Existing snapshot files include older and withdrawn revision bodies. Required erasure/retention across originals, ALL mirrors, old immutable generations, WAL/tmp, remote copies and independent manifests has NOT been implemented. No personal data may be ingested until user/legal-required deletion paths and operator policies are verified. An independent manifest digest is not an authenticity signature when both digest and files can be overwritten by the same attacker.
4. No real-host PHP-identity/open_basedir/default-host/alias/same-UID isolation check, no verified first-party fixed config provider, no operator-controlled actual install, no real personal memory, no signed external anchor/real-host restore drill. Keep `engram-private` OUT of public deployment targets; do not treat synthetic CI success as a host deployment or physical redundancy.
5. Next chat: first re-read live BOOTSTRAP/canonical resources, collaboration protocol and latest Engram checkpoint/CI; continue the next allowed internal DEV step; document tested SHA separately from future documentation-only commits.

This CHECKPOINT is a documentation commit, NOT a new executable CI proof. Pinned code SHA/run above remain its evidence.
