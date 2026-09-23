# Mirage DEV-72 — server-signed write-grant boundary (2026-09-23)

## Conflict/source check
Branch `work/kicom-engram-dev70-mutation-core` was read through DEV-71 before changes. DEV-71 had already completed the optional FTS5 baseline, so it was not repeated. DEV-70 explicitly left genuine separate `engram.write` authorization and a server-owned token/connector-bound write grant as open work. Slack channel `D0C2D8CKWDD` was readable; no newer conflicting reservation was observed in the returned recent project messages. No private memory/token/passkey material was copied.

## Executable change
Added `development/0.9.27/engram/dev72/KiComEngramWriteGrant.php` plus negative/positive tests and dedicated CI. The isolated verifier accepts only a server-signed, short-lived grant and independently verified OAuth resource-server context. It requires an explicit `engram.write` scope; `engram.read` alone cannot write. Grant is bound to owner, namespace, connector id and token fingerprint and an explicit operation allow-list (`engram_write`, `engram_update`, `engram_archive`). Unknown authority fields, unsupported hard delete, malformed/expired/future/overlong grants and signature/binding mismatches fail closed. The implementation is a DEV component only: it neither issues production OAuth tokens nor activates MCP write tools.

## Measured CI evidence
Exact tested SHA: `6ef56dcea0637607f237ff17c7a9b40afd5a993d`.
GitHub Actions run `35800244866`, job `106988705706`: **success**. PHP 8.2 lint passed. Test output: `RESULT passed=17 failed=0 total=17`, including positive write/update/archive and 14 negative/boundary cases. Original R3 package SHA-256 `6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f` and source manifest verification passed unchanged. CI URL: https://github.com/cschymura/kicom-update-mirror/actions/runs/35800244866

## Environment boundary
All DEV-72 tests are synthetic GitHub CI. No production installation, OAuth scope grant, passkey/auth change, SQLite mutation, MCP write activation, host configuration or private-memory transfer occurred. Live KiCom 0.9.37 and the previously proven independent authenticated synthetic `engram_search` read are preserved as the last operator-documented live baseline; this run did not re-prove them.

## Semantic search
DEV-71 remains the current verified semantic baseline: FTS5 lexical search passed on real SQLite in CI with owner/namespace isolation and disabled-by-default behavior. No embedding/vector capability is claimed or added in DEV-72.

## Next conflict-free step
Integrate this verifier with the isolated DEV-70 mutation core and a synthetic OAuth issuer/consent fixture so that a separately granted `engram.write` token plus signed grant reaches actual private SQLite create/revise/archive paths with idempotency/replay protection and sanitized audit, while read-only tokens remain denied. Do not install or enable it on the KiCom host without separate operator authorization.
