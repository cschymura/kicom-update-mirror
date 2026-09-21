# Mirage Engram DEV-38 — privacy-safe Host Evidence Adapter GREEN

Date: 2026-09-21. Branch `work/kicom-0.9.27-pam`. Continuation of DEV-37; no Handwerker work adopted.

## Conflict / Slack check

Read the current Engram directory, DEV-37 and latest commits before writing. DEV-37 was the newest Engram checkpoint. Slack DM `D0C2D8CKWDD` was read: latest relevant status still limits the real claim to installed KiCom 0.9.29, `SYNTHETIC_SERVER_SQLITE_ROUNDTRIP_OK`, existing SQLite DB and saved signed passkey owner assignment; private API remains inactive and independent ChatGPT/MCP retrieval remains unproven. Those operator tests were not repeated or requested again.

## Executable change

Added `KiComEngramHostEvidenceAdapter.php`, `test-engram-host-evidence-adapter.php` and `.github/workflows/test-0927-engram-host-evidence-adapter.yml`.

The adapter is the next boundary after DEV-37: authorized server-side probe/review code may supply boolean reviewed facts, but the adapter rejects unknown fields so absolute paths, PHP UIDs, credentials or other privacy-sensitive host details cannot flow into the portable attestation. It converts only the fixed `mirage-host-facts/v1` schema into the existing `mirage-host-isolation/v1` gate and therefore cannot bypass the DEV-37 fail-closed requirements or activate the private API. It also preserves the required pre-activation facts `synthetic_only=true`, `private_api_inactive=true`, `mcp_connector_connected=false`.

## Exact CI proof

Code/test/workflow SHA: **`a277613a9dae9409d81ad280ef4e5f848b9cf11a`**.

Dedicated workflow `KiCom Engram host evidence adapter`: run **35546301023**, job **106172715087**, conclusion **success**. Immutable R3 package/source baseline verification passed; PHP lint and executable adapter tests passed. The test has **15 assertions**: positive complete-facts evaluation plus negative cases for failed cross-app isolation, missing backup restore, active API, premature MCP, absolute path, PHP UID, credential field, unknown schema, non-boolean fact, invalid binding and invalid timestamp.

Run: https://github.com/cschymura/kicom-update-mirror/actions/runs/35546301023

## Real / synthetic boundary and next step

**Real accepted input (not independently re-run here):** KiCom 0.9.29 server synthetic SQLite roundtrip and signed passkey owner assignment. **Synthetic CI:** privacy-safe evidence adapter and DEV-37 host-isolation gate. **Still not verified:** actual All-inkl complete vhost/alias/default-host inventory, PHP-UID/cross-app isolation, effective open_basedir boundary and real backup/restore/rollback/retention evidence. No host configuration changed, no private API enabled, no private memories read/stored, no connector connected.

Next conflict-free step: implement the separately authenticated activation-readiness component that requires a successful host-isolation evidence result plus verified owner identity and still emits only an inactive/readiness state; then synthetic negative tests for stale/foreign evidence and wrong owner. Actual host fact collection/execution remains blocked on an authorized host-capable path and must not be simulated as real. Actual MCP/ChatGPT connection remains a later separately authorized step.

This checkpoint commit is documentation only and is not a new executable test SHA.
