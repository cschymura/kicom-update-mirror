# Mirage DEV-75 — canonical revision-schema mutation adapter

Run: MIRAGE-20260923T050244+0200-DEV75. Synthetic DEV only; production unchanged.

## Sources / coordination
Branch head before work was `fa9999e796ddc0604119456039147a115a0d8c77` (DEV-74 report). DEV-74 checkpoint and canonical `KiComEngramStore.php` were read. Slack `D0C2D8CKWDD` was read; retrieved messages contained no newer conflicting DEV-75 reservation. BOOTSTRAP/PROJECT_STATE were attempted read-only but the available web path could not access the host, so no new live claim is made. Live KiCom 0.9.37 remains operator-documented baseline only.

## Executable change
Added `dev75/KiComEngramRevisionAdapter.php`, its synthetic test, and dedicated CI. Unlike DEV-73's isolated `dev73_*` tables, the adapter targets the canonical DEV `engram_revisions` contract already used by `KiComEngramStore`: subject/namespace/id/revision/kind/body/provenance/state/hash-chain. It refuses incompatible schemas before mutation, appends write/update/archive revisions, maps archive to the canonical terminal `withdrawn` state (no hard delete), retains optimistic revision/hash checks, owner+namespace scoping, idempotency receipts and sanitized audit metadata. No OAuth scope, MCP route, live DB or private content is changed.

## Reproducible evidence
Exact tested SHA: `9d2b82794d5b98f23ffb04872ed76d01be785e28`. GitHub Actions run `35812826049`, job `107027910579`: SUCCESS. Original R3 integrity step and PHP lint/test step both succeeded. The synthetic script contains 13 gated assertions covering write, replay-idempotency, idempotency conflict, update, cross-owner denial, cross-namespace denial, stale-revision denial, append-only archive, retained history, committed-only audit, no resurrection after archive, SQLite quick_check and incompatible-schema denial. Any failed assertion exits the job nonzero.

CI: https://github.com/cschymura/kicom-update-mirror/actions/runs/35812826049

## Boundary / next
This proves compatibility with the repository's canonical DEV revision schema, NOT the actual live 0.9.37 database schema: live schema was not read in this run. Next safe slice is to compose DEV-74 OAuth/MCP controller + DEV-72 signed write grant with this canonical adapter, replacing the temporary DEV-73 table backend in a synthetic E2E fixture. Keep read-only OAuth denied and do not migrate/activate production. DEV-71 FTS5 baseline remains disabled; no embedding/vector capability was newly verified.
