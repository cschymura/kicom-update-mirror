# KiCom Engram — DEV-12 deterministic interrupted-repair and unanchored artifact inventory

Date: 2026-09-20. Continue from DEV-11. Live BOOTSTRAP and named resources still report KiCom 0.9.26. Read COLLABORATION-PROTOCOL and TASK-ENGRAM-PRIVATE-MEMORY before further work. Public GitHub contains ONLY program code, synthetic fixtures and technical test metadata: NEVER publish private engrams, actual source chats, secret/session/OTP values, actual private DB or backup bytes, user-specific consent artifacts, or private host configuration.

## Implemented code

- `KiComEngramMirrorSet::rebuildNewGeneration(parentManifest, independentDigest, ?callable devFaultHook)` accepts an optional **DEV-only** deterministic interruption hook receiving a constant PHASE LABEL ONLY (no private path, filename or memory content). Explicit phases: before first mirror copy, after first copy, after second copy, before manifest publication, after manifest publication. Without hook the earlier deterministic repair behavior is unchanged. A hook may simulate abort by throwing; no automatic resume or recovery from an unauthenticated artifact.
- New `inventoryUnanchoredArtifacts(independentAnchorInventory[])`: READ-ONLY, bounded inventory (max 256 independently anchored manifest/digest entries and 10,000 directory entries per checked location). An anchored generation is explicitly supplied by its independent trusted digest; `inspect` must verify that manifest. All other mirror files or manifest files remain unanchored review candidates. Returns only COUNTS, independently known generation count, `operator_review_required` and `auto_recovery_permitted=false`; no paths, memory contents, automatic trust promotion, deletion or sweeping. Distinguishes an actual published-but-unanchored manifest from an independently approved generation.
- New `test-engram-fault.php` runs five independent synthetic scenarios, deliberately aborting at each phase. Checks exact orphan file counts, immutable damaged/intact parent generation, missing/locally-published-but-unanchored manifest never automatically trusted, explicit retry yielding completely NEW verified generation, unmodified orphan bytes, and recovery into a new clean directory. The existing original KiCom-R3 verification and all prior Engram synthetic tests remain in the same CI suite.

## Exact verified CI

Tested executable SHA: **`0249acfe14d6a5855392ff89b2d0e7a2d13414e3`**.
GitHub Actions workflow `KiCom private Engram DEV`: run **35506805135**; job **106067887303**; conclusion **success**. Original protected R3 source-manifest and exact release archive preflight passed. PHP source/test driver syntax check passed. Suite markers: main 49 + path 23 + dev-route 23 + endpoint 14 + integrity 16 + ingestion 29 + mirror 46 + interrupted-fault 31 = **231 synthetic checks**.
Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35506805135

## Limits and next steps

1. The interruption tests use deterministic EXCEPTIONS, not actual OS-level SIGKILL, disk power loss, torn write, fsync/durable commit guarantees, concurrent authorized rebuilds, or independent physical/account replicas. Adding actual separate-process kill and concurrency tests is the next independent DEV step. Recovery never chooses unknown orphans by recency, consensus or filename.
2. Inventory requires a TRUSTED, operator-supplied COMPLETE list of independently anchored generations. An attacker who can substitute that list or its external anchor is not stopped by local SHA alone. Inventory output does not disclose paths, but any consumer must enforce its own correct authentication/authorization and avoid logging private artifacts.
3. No live KiCom installation, host-side PHP/webroot/alias or same-UID isolation proof, external manifest anchor service, scheduling, automatic self-heal, backup retention/erasure, or memory import. Existing private folders stay outside public deployment. No private memory is copied to GitHub/Slack/Mail. The production KiCom 0.9.26 protected update, kernel, passkeys and identity remain untouched. Do not equate completed synthetic CI with real physical RAID or host installation.
4. User asked to CONTINUE AUTONOMOUSLY through safe internal DEV cycles and report when a real human action is needed. After true process/fault tests, implement independent-anchor lifecycle and retention/erasure design with synthetic tests; stop only at the actual host/operator authorization boundary, document the exact missing action.

Documentation commit does not alter executable tested SHA above.
