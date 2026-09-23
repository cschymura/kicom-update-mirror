# Mirage DEV-77 — single OAuth continuity correction, local/synthetic only

Date: 2026-09-23. Production unchanged. This is the ONE targeted auth-correction design requested for the integrated release candidate; it is not a claim of ChatGPT-platform behavior or live success.

## Verified starting evidence

Branch `work/kicom-engram-dev70-mutation-core` was read at night-report head `894bc6a6fcfd42dafb7e0a895c5629f919a9acee`; DEV-76 canonical OAuth/MCP/write-grant/private-SQLite mutation E2E was already green on code SHA `b9699779b47d602b272c3e1d6f936fded967aa5a`. Slack D0C2D8CKWDD was read; no newer conflicting reservation was present in retrieved messages. Live KiCom remains operator-documented 0.9.37; no fresh live host mutation or private-data read was performed here.

The current repository OAuth transaction core issues `engram.read` access tokens with 3600-second TTL and advertises/implements only `authorization_code`; it has no refresh-token continuity. That is a concrete server-side path capable of causing a later/new client instance to receive 401 and re-enter authorization. This does NOT prove that it is the only cause of the observed iPhone/new-chat reconnection failure. Current OpenAI connector documentation also requires the exact callback URL shown by ChatGPT for app-template OAuth connections; therefore no claim is made that KiCom can bypass or change ChatGPT platform connection controls.

## Executable correction prepared

Added `dev77/KiComEngramOAuthContinuity.php`: rotating, single-use, seven-day refresh continuity derived only from an already-valid `engram.read` access-token row. It preserves client, connector, host-evidence, owner-binding and credential fingerprint, creates only `engram.read`, denies replay/expired/mismatched bindings, and never upgrades read into `engram.write`. Added `dev77/test-oauth-continuity.php` and dedicated CI. This is intentionally isolated from the production/native router until the single integrated release candidate is assembled and its full regression suite passes.

GitHub Actions run `35820679292`, job `107051644163`, exact tested SHA `3eaf8b7498d2b3b48990aa26230c37ea00600a10`: completed SUCCESS. Original immutable R3 package/source integrity step succeeded; PHP setup/lint/test step succeeded. The test fixture contains 13 fail-fast assertions covering read-only mint/rotation, owner+credential preservation, old-refresh replay, connector/client/host mismatch, expired access, write-scope exclusion, expired refresh and SQLite quick_check.

CI: https://github.com/cschymura/kicom-update-mirror/actions/runs/35820679292

## Release boundary

NOT READY FOR RELEASE. DEV-77 is a tested local/synthetic correction component, not yet integrated into the one native KiCom installation candidate, OAuth HTTP token endpoint metadata/form handling, full search/write/update/archive regression, recovery/genome/rollback package chain, or a real ChatGPT new-chat/new-device flow. No live OAuth rights, DB, passkey, host config, plugin connection or personal memory changed.

Next: integrate DEV-77 exactly once into the consolidated native candidate while retaining the already-proven read path and DEV-76 mutation path; update OAuth metadata/token endpoint for `refresh_token` without granting write; run package/genome/recovery/rollback plus search/write/update/archive positive/negative regression. Only then request the separate operator approval for ONE live new-chat/new-device test. If that one live test fails, stop automated auth/security redesign and prepare a joint developer review with concrete platform-vs-KiCom failure provenance and minimal reversible alternatives.
