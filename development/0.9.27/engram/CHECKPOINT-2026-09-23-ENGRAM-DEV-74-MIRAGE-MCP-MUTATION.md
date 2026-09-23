# Mirage DEV-74 — MCP/OAuth mutation controller → private SQLite

Run: MIRAGE-20260923T035936+0200-DEV74. Scope: isolated synthetic DEV only; production unchanged.

## Coordination and sources
Read current branch history and DEV-73 checkpoint before changing code. Slack D0C2D8CKWDD was read; no newer conflicting Engram work reservation was found in the retrieved messages. KiCom BOOTSTRAP/PROJECT_STATE were requested read-only; no productive mutation was attempted. Live KiCom 0.9.37 and the prior authenticated synthetic MCP read remain documented baseline, not newly re-proved here.

## Executable change
Added `dev74/KiComEngramMcpMutationController.php`, synthetic `test_mcp_mutation_controller.php`, and dedicated CI. The controller accepts only `engram_write`, `engram_update`, `engram_archive`; requires an independently supplied authenticated OAuth context with explicit `engram.write`; rejects MCP-supplied owner/namespace/OAuth/scope claims; and passes only the server-bound context, signed write grant, bounded mutation envelope and idempotency key into DEV-73 SQLite mutation service. It does not create a public endpoint, issue OAuth tokens, activate writes, or alter production schema/configuration.

## Reproducible evidence
Exact tested SHA: `18a8ca786c8c3f62a4d354088f31663a8059eca5`. GitHub Actions run `35808659625`, job `107015006789`: SUCCESS. PHP lint passed. Original R3 package digest `6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f` and source manifest verified unchanged. Test log: `KICOM_DEV74_TESTS_PASSED=12`, zero failed assertions. Positive path proves synthetic MCP write revision 1, update revision 2 and append-only archive revision 3 in SQLite. Negative path proves read-only OAuth cannot mutate, MCP owner/namespace claims cannot override server identity, unauthenticated context fails, stale update fails and non-mutation MCP tool is rejected. SQLite current state remains archived, only 3 committed mutations are audited, and `PRAGMA quick_check=ok`.

CI: https://github.com/cschymura/kicom-update-mirror/actions/runs/35808659625

## Boundary / next
This is a synthetic controller composition, NOT live OAuth/MCP write activation. No private memories, secrets, passkeys, complete tokens, live DB or rights were changed. Next conflict-free slice: compare DEV-73 mutation tables/fields and controller expectations with the actual private Engram schema/migration source used by current 0.9.37, and build a backwards-compatible migration/adapter test using synthetic fixtures. Do not migrate live SQLite or change OAuth scopes without separate operator authorization. DEV-71 FTS5 remains the verified disabled lexical baseline; no vector/embedding capability is claimed.
