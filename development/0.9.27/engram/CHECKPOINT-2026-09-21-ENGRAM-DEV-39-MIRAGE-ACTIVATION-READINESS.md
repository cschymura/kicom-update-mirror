# Mirage Engram DEV-39 — authenticated activation readiness gate GREEN

Date: 2026-09-21. Branch `work/kicom-0.9.27-pam`. Single Mirage instance; Engram prioritized; no Handwerker work adopted.

## Conflict and Slack check

Before writing, branch HEAD was `edc1e81d66e19be066f4d4179cfa529f3065eb11` (DEV-38) and Slack DM `D0C2D8CKWDD` was read. No newer Engram implementation claim was present. The accepted real/operator boundary remains: KiCom 0.9.29 installed, `SYNTHETIC_SERVER_SQLITE_ROUNDTRIP_OK`, SQLite present and signed passkey owner assignment stored; private API remains inactive and independent ChatGPT/MCP retrieval is unproven. None of those operator tests was repeated or requested.

## Executable change

Added `KiComEngramActivationReadinessGate.php`, `test-engram-activation-readiness.php` and `.github/workflows/test-0927-engram-activation-readiness.yml`.

This is deliberately a readiness-only gate after DEV-38. It accepts only an already successful privacy-safe host-isolation evidence record and separately verified owner evidence. It binds owner evidence to the exact host evidence ID, requires the expected opaque owner binding, rejects stale/future evidence, unknown fields, inactive/foreign/unverified owner evidence, premature MCP connection and any state in which the private API is already active. It returns `AUTHENTICATED_ACTIVATION_READY_API_STILL_INACTIVE`; it contains no activation method, endpoint, passkey material, path, UID or private memory content.

## Exact CI proof

Code/test/workflow SHA: **`363122ca49b8fda69b1caa4eee024578b89b03d4`**.

Workflow `KiCom Engram activation readiness`: run **35549552745**, job **106181628024**, conclusion **success**. Exact SHA checkout verified. Immutable original R3 ZIP SHA-256 `6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f` and original source manifest verification passed. Both PHP files lint clean. Executable test result: **15/15 assertions passed**, including wrong owner, foreign host evidence, stale host/owner evidence, future evidence, already-active API, premature MCP, unverified owner, wrong host state, secret/path extra fields, invalid owner binding and unsafe age-policy negatives.

Run: https://github.com/cschymura/kicom-update-mirror/actions/runs/35549552745

## Real versus synthetic boundary / next step

**Real accepted operator evidence, not re-run in this cycle:** 0.9.29 server synthetic SQLite roundtrip and signed passkey owner assignment. **Synthetic CI in this cycle:** authenticated activation-readiness policy only. Actual All-inkl vhost/alias/default-host/PHP-UID/open_basedir/cross-app isolation and real backup/restore/rollback/retention facts remain unverified because this instance has no authorized host-capable read path. DEV-38 adapter is ready to consume privacy-safe reviewed facts when such a path exists. No host config, permissions, secrets, production API, private memories or MCP/plugin connection changed.

Next conflict-free code section if host evidence is still unavailable: build the server-side activation transaction/controller as a **disabled synthetic component** that requires DEV-39 readiness, authenticated operator authorization and atomic state transition, with rollback and replay/foreign-owner negatives; it must not expose a public diagnostic or enable anything by itself. Actual activation still requires separate concrete operator authorization after real host evidence is complete. Independent ChatGPT/MCP retrieval remains a later, separately tested milestone.

This checkpoint commit is documentation only and is not a new executable test SHA.
