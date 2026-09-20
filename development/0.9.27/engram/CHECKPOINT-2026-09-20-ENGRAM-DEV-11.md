# KiCom Engram — DEV-11 controlled immutable mirror generation rebuild

Date: 2026-09-20. Continuation of DEV-10. Read \`development/0.9.27/COLLABORATION-PROTOCOL.md\` and \`TASK-ENGRAM-PRIVATE-MEMORY.md\`, live KiCom BOOTSTRAP and canonical resources at each restart. PUBLIC repository contains ONLY synthetic DEV code, technical proof and documentation; NEVER real personal engrams, original conversations, credentials, OTP, consent receipts, private backup/database bytes, independent operator anchors or private host paths.

## Verified baseline

Live canonical KiCom BOOTSTRAP/PROJECT_STATE still report production 0.9.26. DEV-10 predecessor: code SHA \`eb3a9b998641bf7884c6b658da13912563a5358d\`; CI run 35505739252/job 106065123998, 184 synthetic checks. The protected original 0.9.26-R3 source/package integrity verifier still passes in the dedicated workflow. No KiCom live DEV endpoint, production application, private WebFTP folder, real memories, private manifest anchor or hosting server filesystem was changed in this cycle.

## Implemented executable work

- \`KiComEngramMirrorSet::rebuildNewGeneration(parentManifest, trustedParentDigest)\`: EXPLICIT OFFLINE operation requiring a caller-supplied, *independently trusted* hash of an existing complete v1/v2 parent manifest. Only a DEGRADED parent with exactly ONE SHA-matching, private, single-link intact snapshot may be used. Denies intact/unrecoverable generations and wrong/missing parent anchor. Never infer authenticity from majority, newest file, filename, or live database contents.
- The method creates two new mode-0600, no-clobber copies of THE SAME verified parent snapshot in the pre-provisioned private mirror peer directories, each with a random NEW snapshot filename. Copy streams are size-bounded to 512 MiB for this DEV implementation. Source hash is checked before and after; both destination hashes must match before a fresh, no-clobber v2 manifest is published.
- The v2 manifest records the new generation/snapshot digest/revision count and its parent manifest filename, exact parent manifest SHA-256 and source mirror index (0 or 1). It contains neither absolute filesystem paths nor engram bodies. \`inspect()\` now validates strict independent v1/v2 schemas, source provenance-field types and format. A new v2 manifest is not trusted by the old parent anchor: its OWN digest must be independently retained by an operator before inspect/recover.
- Each generation is immutable; old damaged and good snapshots/manifests are never overwritten or deleted. A partial failure can leave orphan files, but without a fully produced manifest and independently stored digest they remain ineligible for restoration. No automatic live-memory promotion, no overwrite, no background scheduler, no crash-safe multi-directory atomic transaction or real automatic repair is claimed.
- New synthetic-only \`test-engram-rebuild.php\`, included inside existing mirror test fixture, covers wrong/untrusted anchor, intact/zero-survivor refusal, one-survivor rebuilding, preserved parent contents, new copied bytes and source lineage, independent NEW-manifest approval, separate restore with complete revision history, repeated v2->v2 repair, and crash-like uncommitted orphan refusal. No actual process-kill/fault-injection of mid-copy was performed; this remains next work.

## Exact verified evidence

Tested EXECUTABLE HEAD: **\`bbf7fb5c45b7b7ee7ce2384c656198cd82ff46bd\`**.
GitHub Actions \`KiCom private Engram DEV\`: run **35505946862**; job **106065672237**; conclusion **success**. Existing exact original R3 package/manifest preflight: PASSED; PHP source/test driver syntax check: PASSED; complete synthetic suite: PASSED.
Log suite markers:
- \`KICOM_ENGRAM_TESTS_PASSED=49\`
- \`KICOM_ENGRAM_PATH_PROBE_TESTS_PASSED=23\`
- \`KICOM_ENGRAM_DEV_ROUTE_TESTS_PASSED=23\`
- \`KICOM_ENGRAM_ENDPOINT_TESTS_PASSED=14\`
- \`KICOM_ENGRAM_INTEGRITY_TESTS_PASSED=16\`
- \`KICOM_ENGRAM_INGEST_TESTS_PASSED=29\`
- \`KICOM_ENGRAM_MIRROR_TESTS_PASSED=46\`
Total: **200 synthetic checks**. Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35505946862

## Remaining safety/functional gates

1. This remains an isolated synthetic SQLite snapshot-mirroring candidate, NOT RAID across physically/administratively independent storage. A single same-UID PHP app, malicious privileged operator or whole-account hardware failure can damage both copies and their local manifests. 0700 permissions and earlier 404 canary responses do NOT establish cross-app/real-host isolation. Need independently secured storage and independently protected manifest anchors, real server identity/open_basedir/default-host/HTTP alias tests.
2. No actual human-approved deployment, KiCom live endpoint hookup, off-host copy, automated scheduler, running filesystem scrub or unattended self-heal. Operator-reviewed system configuration and protected verifier/backup/health/rollback remain prerequisites to any live probe/installation. Real personal memory remains UNIMPORTED.
3. No complete retention/erasure across ALL live revisions, WAL/temp, each immutable snapshot generation, independent digest anchors, remote copies and old archives. **Do not ingest personal engrams** until tested user/legal-required deletion and retention controls are in place. Explicit consent and scope currently remain synthetic callback contracts, not real deployed authorization.
4. New v2 manifest links provenance but inspect does NOT reverify its ancestor against an independently pinned ancestor anchor; a single independently pinned current manifest authenticates current bytes only under its own trust model. More complete signed/externally pinned generation lineage, bounded retention and actual crash/fault-injection tests are future work. Manifest publication is per-file exclusive creation, not a cross-directory atomic commit or tamper-proof HSM signature.
5. Next internal DEV: deterministic crash/fault-injection scenarios before and after first/second mirror copies and before manifest publish, orphan/quarantine inventory and safe resume that NEVER guesses a valid generation; separately trusted lineage manifest policy; retention-and-erasure protocol with no real user data in public CI. Update checkpoint with exact tested SHA and action logs.

This documentation-only commit does NOT represent a new executable test; the code SHA/run above are the verifiable evidence.
