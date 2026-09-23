# Mirage DEV-87 — explicit private write-schema preparation, no MCP-side DDL

Date: 2026-09-23. Development-only source. **No production install, no active private DB migration, no new OAuth consent and no ready-to-install package.** Parent live KiCom last confirmed 0.9.37; independent synthetic Engram READ previously proven.

## Concrete defect corrected

DEV-86's new `KiComEngramRevisionAdapter::__construct` ran `CREATE TABLE IF NOT EXISTS` for receipts and audit. This could mutate Christoph's private SQLite simply because a bearer-authenticated MCP write arrived, bypassing the original operator-controlled admin preparation. A table pre-existing without the new grant_nonce uniqueness constraint could also pass `CREATE IF NOT EXISTS` but break the promised durable replay protection.

In branch `work/kicom-engram-dev87-explicit-write-schema` (based on DEV-86 checkpoint), new `dev87/KiComEngramMutationSchema.php` supplies:
- `assertReady(PDO)`: read-only column/nonce UNIQUE-index/canonical engram_revisions schema check. The adapter constructor now ONLY calls this check and never executes DDL.
- `prepareNew(PDO)`: separate explicitly operator-invoked, BEGIN IMMEDIATE, fail-closed initializer for BOTH *absent* private receipt/audit tables. Repeated calls on an already-correct pair are idempotent. A partial or incompatible pre-existing pair is rejected without implicit migration. This class does not provide administration authentication: future wiring MUST use KiCom's existing first-party session, CSRF, passkey/consent, private path, backup and owner checks, not MCP or OAuth token HTTP.
- Original DEV-86 durable UNIQUE(subject,namespace,grant_nonce), atomic revision+receipt+audit, cross-connection idempotent retry, deny changed same nonce remain intact.
- DEV-76 and DEV-86 fixtures explicitly call the new setup as a synthetic stand-in for the original approved admin route.

## ACTUAL independent test

GitHub Actions run **35829989828**, job **107080110355**, completed SUCCESS on latest test-triggering commit `b0a3bc28a7e4002dbd41f3aede7ab4daf2aaa87e` with PHP 8.2/ext-pdo_sqlite enabled:

- Five PHP syntax checks passed.
- New explicit provisioning/negative schema tests: `DEV87_EXPLICIT_SCHEMA_ASSERTIONS=13`.
- Older DEV-76 synthetic canonical MCP/SQLite E2E regression: `KICOM_DEV76_ASSERTIONS=10`.
- DEV-86 durable cross-PDO-connection replay, write/update/archive and audit regression: `DEV86_DURABLE_GRANT_ASSERTIONS=16`.
- **39/39 real PHP PDO SQLite synthetic assertions passed, 0 failed.**

CI: https://github.com/cschymura/kicom-update-mirror/actions/runs/35829989828

## Strict outstanding release gates

1. Code is DEVELOPMENT implementation, not integrated into the original native KiCom 0.9.37 HTTP/MCP/administrative paths. The explicit `prepareNew` function must be wired through existing authenticated admin+backup route and tested on synthetic replicas of the original private store; if a legacy DEV table already exists, implement a SEPARATELY auditable additive migration instead of dropping it.
2. DEV-75 adapter STILL hardcodes `source_kind=synthetic_test` and `source_ref=dev75://mutation`; never claim that real privately approved memories were stored correctly before server-provenance integration and true engram.write passkey/OAuth consent. Existing engram.read tokens must never upgrade silently.
3. Finish DEV-82/83/84/85 original native OAuth read refresh, actual PHP PDO SQLite/HTTP integration and original old-read fallback, and three native MCP write/update/archive tools with owner/namespace/revision/nonce/audit enforcement. Preserve live engram_search.
4. ONLY after full native package genome/manifest/installer/recovery/rollback and integrated positive+negative tests provide ONE installable comprehensive candidate for Christoph's separate production approval. ONE approved real new-chat/iPhone auth test; if failure persists STOP automated auth redesign and jointly review observed KiCom vs ChatGPT platform failure with Christoph.
5. Semantic FTS5 remains optional and OFF unless tested; no external embeddings or personal data transfer without appropriate separate approval.

No additional manual tasks for Christoph at this development stage.
