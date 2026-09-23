# Mirage DEV-86 — durable one-time write grant across PHP worker processes

Date: 2026-09-23. Development only. NO live KiCom changes, no new OAuth grants, and NO installable native package. Existing KiCom 0.9.37 read-only MCP retrieval remains the previously proven production baseline.

## Actual defect and fix

In DEV-76, `KiComEngramCanonicalMutationService::$usedNonces` lived only in a PHP object's memory, so a caller could reuse the same server-signed write grant in a later request handled by a new PHP process, supplying a different idempotency key. The in-memory replay guard provided no durable single-use property. A separate idempotency receipt alone did not prevent that reuse.

On branch `work/kicom-engram-dev86-durable-grant-nonce`:
- `dev75/KiComEngramRevisionAdapter.php`: now persists `grant_nonce` in the PRIVATE `engram_mutation_receipts` table with UNIQUE(subject,namespace,grant_nonce). The adapter's existing BEGIN IMMEDIATE SQLite transaction checks both the exact request idempotency key and the signed grant nonce, writes the revision+nonce receipt+audit atomically, rejects a nonce used by a *different* request, and returns the original receipt for an exact same-grant/same-request retry. No hard-delete.
- `dev76/KiComEngramCanonicalMutationService.php`: removed object-local nonce cache; passes ONLY the nonce from the verified server-signed grant into the SQLite mutation adapter.
- `dev86/test_durable_grant_nonce.php`: synthetic fixture with TWO INDEPENDENT native PDO SQLite connections to the SAME private-mode temporary SQLite file, two fresh controller instances, same grant/idem retry, different idem/content/grant denial, owner/namespace/scope isolation, versioned update/archive, audit/receipt counts and quick_check.
- Existing DEV-76 synthetic E2E positive/negative tests remain included.
- CI workflow `.github/workflows/test-kicom-dev86-durable-grant.yml` uses PHP 8.2 with real `ext-pdo_sqlite`.

## VERIFIED reproducible test result, not inferred
GitHub Actions run **35829079801**, job **107077272134**, completed `success`, tested commit `e75b01e60318c6df684f43ee355cce1cf6a86777` (workflow commit was `a61b68b1683b681daf56e8e359ea0aca49d3c68d`).
URL: https://github.com/cschymura/kicom-update-mirror/actions/runs/35829079801

GitHub job logs explicitly show `pdo_sqlite Enabled`, all five PHP syntax checks successful, `KICOM_DEV76_ASSERTIONS=10` and `DEV86_DURABLE_GRANT_ASSERTIONS=16`: **26/26 synthetic native PDO SQLite assertions passed; 0 failed.** A prior run 35829007603 on the initial one-connection fixture also passed, but this checkpoint cites the stronger final TWO-connection run.

This is a real CI engine/driver check of the DEVELOPMENT write mutation classes, NOT a live KiCom OAuth/Passkey consent or end-to-end ChatGPT write invocation.

## Outstanding release blockers (do not mark READY_FOR_RELEASE)
1. The current DEV-75 development adapter still performs `CREATE TABLE IF NOT EXISTS engram_mutation_receipts/audit` in its constructor. Production must provision or migrate these private write tables through explicit first-party operator administration/backup, never as a side effect of a bearer-authenticated MCP call. Schema compatibility against any existing DEV tables must be handled explicitly before a production release.
2. `source_kind='synthetic_test'` and `source_ref='dev75://mutation'` are hard-coded in this isolated adapter. Genuine memories require **server-verified user-approved provenance**, not false synthetic labels. No live personal memories should be written using this development placeholder.
3. Add genuine, separate OAuth + Passkey consent for `engram.write` and trust-boundary validation to the native KiCom controller. Existing engram.read grants must not auto-upgrade.
4. Merge DEV-82/83/84/85 native OAuth continuity, DEV-86 durable nonces, approved provenance and canonical store with existing native read, then real PHP-PDO/HTTP/MCP migrations/tests; version/manifest/genome/original updater/backup/recovery/rollback. ONE fully verified package ONLY.
5. After operator's separate installation approval, ONE new-chat/iPhone OAuth test. On failure, stop automated auth rewrites and jointly review server vs platform path with Christoph. Optional FTS5 remains disabled; no live semantic search or embeddings proven.

No production installation, private DB migration, actual token issuance, or extra access authorization occurred in this DEV-86 work.
