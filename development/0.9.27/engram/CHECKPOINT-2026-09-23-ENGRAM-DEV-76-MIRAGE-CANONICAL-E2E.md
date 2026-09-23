# Mirage DEV-76 — canonical OAuth/MCP/write-grant/SQLite mutation E2E

Run: MIRAGE-20260923T060234+0200-DEV76. Synthetic DEV only; production unchanged.

## Sources / coordination
Branch head at start: `6be744872238d414ac804da8699d396233979972` (DEV-75 night report). DEV-75 checkpoint, DEV-72 write grant and DEV-74 controller contract were read. Slack `D0C2D8CKWDD` was read; retrieved messages contained no newer conflicting DEV-76 reservation. BOOTSTRAP and PROJECT_STATE were attempted read-only but unavailable through the available web path; no fresh live KiCom claim is made. Operator-documented live baseline remains 0.9.37.

## Executable change
Added `dev76/KiComEngramCanonicalMutationService.php` and a canonical MCP controller. The composition verifies the short-lived server-signed `engram.write` grant against server-side OAuth owner/namespace/connector/token binding before calling the DEV-75 canonical `engram_revisions` adapter. Added synthetic E2E coverage for write/update/archive, read-only denial, injected MCP owner denial, namespace mismatch, stale revision, append-only archive/history, nonce replay and SQLite integrity. No live OAuth scopes, DB, host configuration, passkey, personal memory or production route changed.

## Reproducible evidence
Initial CI run `35816822438` failed because the DEV-74 controller was deliberately typed to its old DEV-73 backend. This was diagnosed from job `107040007083`; no success was claimed. A canonical controller was added and the fixture corrected. Final tested SHA: `b9699779b47d602b272c3e1d6f936fded967aa5a`. GitHub Actions run `35816866684`, job `107040139774`: all executable steps SUCCESS. Original R3 package/source integrity succeeded; PHP lint succeeded; `KICOM_DEV76_ASSERTIONS=10` with 10/10 measured assertions passing.

CI: https://github.com/cschymura/kicom-update-mirror/actions/runs/35816866684

## Boundary / next
This is synthetic repository E2E, not a live 0.9.37 write. The actual live private schema and OAuth mutation exposure were not read or changed. Next safe slice: verify the disabled DEV-71 FTS5 baseline against canonical revision lifecycle (active latest revision only, archived exclusion), migration/rebuild and owner/namespace isolation, with synthetic performance/quality measurements; keep it disabled. Separately, future production mutation activation remains a distinct operator-approved action after live schema/config compatibility is established.
