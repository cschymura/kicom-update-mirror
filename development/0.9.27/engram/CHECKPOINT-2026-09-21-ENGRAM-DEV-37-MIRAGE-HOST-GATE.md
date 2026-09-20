# Mirage Engram DEV-37 — fail-closed Host-Isolation Evidence Gate GREEN

Date: 2026-09-21. Branch `work/kicom-0.9.27-pam`. Continuation of DEV-36 after the operator-reported real KiCom 0.9.29 milestones `SYNTHETIC_SERVER_SQLITE_ROUNDTRIP_OK`, existing SQLite DB, and signed passkey owner assignment. Those real server milestones were accepted as current input and were **not repeated**.

## Conflict check / source state

Before writing, the Engram directory and DEV-36 were read from the current branch and Slack DM `D0C2D8CKWDD` was checked. Latest Slack status confirms SQLite roundtrip + passkey owner assignment but explicitly limits the claim: private API remains inactive and no independent ChatGPT retrieval is proven. No newer Engram checkpoint than DEV-36 existed in the directory at start of this cycle. No parallel Handwerker job was adopted.

## Executable change

Added `KiComEngramHostIsolationGate.php` plus `test-engram-host-isolation-gate.php` and dedicated workflow `.github/workflows/test-0927-engram-host-isolation.yml`.

The new gate is deliberately **not** a host probe and **not** an activator. It provides the missing fail-closed boundary between host evidence and a future separately authorized activation. It accepts no paths, UIDs, credentials, passkeys, memories or private configuration values. Instead it requires a SHA-256 host/config binding and a strict `mirage-host-isolation/v1` attestation containing positive evidence for the complete reviewed vhost/alias/default-host inventory, private path/mode boundaries, PHP identity, cross-app read/write denial, open_basedir boundary, backup snapshot + restore, rollback and retention/delete behavior. Unknown fields fail closed. Evidence must explicitly state synthetic-only, private API inactive, and MCP connector not yet connected. Success is named `HOST_ISOLATION_EVIDENCE_COMPLETE_API_STILL_INACTIVE` and cannot itself activate Engram.

This separates three claims that must not be conflated: (1) real 0.9.29 synthetic SQLite/passkey milestone already reported by the operator; (2) host isolation evidence, still not collected on All-inkl; (3) later private API/MCP activation, still unauthorized/unconnected.

## Exact CI proof

Code/test/workflow exact SHA: **`b18f58137cf2bdbf9bd68a3edd5c4ea9b4f2bafa`**.

Dedicated GitHub Actions workflow `KiCom Engram host isolation gate`: run **35543424913**, job **106165063153**, conclusion **success**. Immutable R3 package SHA-256 `6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f` and original source manifest were verified before tests. PHP lint and the host-isolation test completed successfully. The test contains **25 positive/negative assertions**, including each material missing-evidence condition, foreign host binding, invalid binding, premature MCP connection, already-active API, non-synthetic evidence, impossible timestamp, unknown/secret-bearing field and unsupported schema.

Run: https://github.com/cschymura/kicom-update-mirror/actions/runs/35543424913

## Real vs synthetic boundary / next step

**Real:** operator-reported KiCom 0.9.29 server synthetic SQLite roundtrip and passkey owner assignment. **Synthetic CI:** this new evidence gate and its 25 assertions. **Still unverified:** actual All-inkl vhost/alias/default-host inventory, PHP UID separation or same-UID sibling access, effective open_basedir isolation, and real private backup/restore/rollback/retention evidence. No host configuration was changed, no API enabled, no private memory stored/read, no connector connected.

Next conflict-free DEV step: build a non-secret, server-side evidence collector/adapter that can feed this gate from reviewed host facts without exposing absolute paths or UIDs, and exercise backup/restore evidence with synthetic data. Actual host execution requires an authorized host-capable path; if unavailable, continue with the activation/MCP components synthetically rather than asking the operator to relay intermediate data. A real independent ChatGPT-memory retrieval must not be claimed until an actual authorized connector exists and an E2E test succeeds.
