# Mirage DEV-73 — signed write grant → SQLite mutation integration

Run: MIRAGE-20260923T025838+0200-DEV73. Scope: isolated DEV only; production unchanged.

## Coordination
Read latest branch head/night report, DEV-71/72 evidence and morning agreement; Slack D0C2D8CKWDD showed no newer active conflicting claim in the retrieved messages. Live KiCom 0.9.37 and independent authenticated synthetic MCP read remain operator-documented achieved baselines, not re-proved here. No private memory, passkey, token or host configuration was read or written.

## Executable change
Added `dev73/KiComEngramMutationService.php`, `dev73/test_mutation_integration.php` and dedicated CI. The service composes DEV-72 server-signed `engram.write` grants with SQLite write/update/archive transactions. It enforces owner+namespace binding inherited from verified OAuth context, exact mutation shapes, expected revision/hash concurrency, request idempotency, one-time grant nonce use, append-only archive revisions, and sanitized audit rows. Existing `engram.read` tokens remain denied mutation rights. No HTTP/MCP production route or activation was added.

## Reproducible evidence
Initial SHA `765896d2d7639ebe74c65553464be1c03768c261`, run 35804442947, correctly failed after 3 passing assertions: raw `BEGIN IMMEDIATE` is not reflected by PDO `inTransaction()`, so the error path left the SQLite transaction open. This was fixed without weakening assertions by tracking the explicit BEGIN and always issuing ROLLBACK on exceptions.

Exact corrected/tested SHA: `9c2678fa72e6d74ea3721929f6b700dde0fec410`. GitHub Actions run `35804487486`, job `107002096769`: **15/15 assertions PASS**, PHP lints pass, SQLite `quick_check=ok`, and unchanged original R3 package SHA/source manifest verification passes. Assertions include create revision 1, identical idempotent replay, conflicting idempotency denial, update revision 2, stale revision denial, archive revision 3 with all history retained, post-archive update denial, read-only OAuth denial, owner/namespace mismatch denial, grant nonce replay denial, committed-only audit count, audit schema exclusion of body/token fingerprint/nonce, and DB integrity.

## Boundary / next
This proves an isolated synthetic composition, not live OAuth/MCP mutation capability. Production KiCom, OAuth scopes, DB schema and rights are unchanged. Next conflict-free slice: bind this mutation service behind the existing synthetic MCP/OAuth controller fixture, proving `engram_write`, `engram_update`, and `engram_archive` tool requests map to the service only after separate write consent/scope, while read tokens and MCP-supplied owner/namespace claims cannot escalate. Then evaluate migration compatibility with the actual private Engram schema before any release candidate. DEV-71 FTS5 remains disabled; no vector/embedding claim.