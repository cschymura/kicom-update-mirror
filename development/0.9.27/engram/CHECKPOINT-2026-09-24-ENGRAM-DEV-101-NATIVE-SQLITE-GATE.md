# MIRAGE DEV-101 — native full-tree PDO SQLite gate prepared

Date: 2026-09-24. Branch: `work/kicom-engram-dev100-one-tree-validation`.

## Verified starting point

Branch head at run start was DEV-100 checkpoint `05c921e736801555fefaa63e57cb72f4c45358a6`. DEV-100 documents the independently repeated exact 0.9.37 one-tree staging: all 11 native patches zero-fuzz, 13 added PHP modules plus one asset, 74 PHP lints, original guardian/recovery/PasskeyBridge unchanged, and 6/6 staged protocol assertions. It explicitly remains nonproduction and release-blocked on real PDO SQLite, Passkey/WebAuthn, HTTP/MCP, updater/recovery/rollback gates.

This automation runtime did **not** contain the original KiCom 0.9.37 ZIP under `/mnt/data`; no stale sandbox path was invented. BOOTSTRAP/PROJECT_STATE/GENOME_STATUS were attempted but unavailable through the available web path. Slack `D0C2D8CKWDD` was read; retrieved recent messages were older DEV-65-era handoffs and contained no newer conflicting DEV-100/101 reservation.

## Concrete change

Added `dev100/test_native_sqlite_full_tree_gate.php`. Unlike DEV-95's isolated fixture, this test accepts only a fully staged native tree, loads the staged native `lib.php`, staged native `KiComEngramMutationSchema`, staged native `KiComEngramActiveSchemaUpgrade`, and staged native OAuth transaction class. With real `ext-pdo_sqlite` it verifies on synthetic private databases:

- inactive runtime is denied before DDL and before snapshots;
- both SQLite backups are completed before additive schema upgrade and remain private/healthy;
- upgrade remains non-activating (`write_scope_activated=false`);
- existing `engram.read` bearer and pending old authorization remain read-only;
- canonical Engram data survives migration and mutation schema validates;
- active and backup databases pass `PRAGMA quick_check`;
- generated server signing key remains private and structurally valid.

Added `.github/workflows/kicom-dev100-native-sqlite-gate.yml`. It is intentionally `workflow_dispatch` only and requires an operator-provided **private Actions artifact** containing exactly one original parent ZIP. It verifies SHA256 `0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7`, runs the actual DEV-97 one-tree stager, then runs the new real-PDO SQLite gate on the resulting whole tree. The workflow deletes the private parent and staged runtime afterwards and publishes no artifact. The original ZIP is still not committed to GitHub.

## Honest test state

No native full-tree PDO test result is claimed in this run because this runtime lacks both the original parent ZIP and local `pdo_sqlite`. The new workflow has not been dispatched because no verified private parent artifact run/name was available to this runtime; fabricating one would violate the source/privacy boundary. These are execution prerequisites, not a request to weaken production security.

No production installation, OAuth/scope mutation, live DB change, private memory transfer, token/Passkey publication, or semantic-search activation occurred.

## Next exact step

When a verified private Actions artifact containing the SHA-pinned 0.9.37 parent is actually available, dispatch `KiCom DEV100 native SQLite full-tree gate` against the current branch and inspect its measured assertion count/logs. If green, continue on the same integrated tree with native original Admin + fresh WebAuthn/CSRF and HTTP/MCP token-gated read/write/update/archive/refresh/replay integration. Do not call the candidate release-ready before those gates plus updater/genome/recovery/rollback pass.
