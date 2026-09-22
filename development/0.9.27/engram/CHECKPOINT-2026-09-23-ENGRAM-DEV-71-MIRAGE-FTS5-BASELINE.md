# Mirage DEV-71 — optional FTS5 baseline, deliberately disabled (2026-09-23)

Instance: MIRAGE-NIGHT-20260923-01. Scope: one conflict-free semantic-search investigation slice after DEV-70; production unchanged.

## Coordination / baseline
Read current `work/kicom-engram-dev70-mutation-core` before writing. Head was `9e4f222590c54fa519e21dcccc5fc722aa170aa8`; DEV-70 checkpoint records the real independent read milestone and an offline mutation core, while production mutation operators remain unavailable. Slack D0C2D8CKWDD contained no newer active claim conflicting with this isolated `dev71/` search-index work. No private memory, token, passkey or host configuration was read or written.

## Executable output
Added `development/0.9.27/engram/dev71/KiComEngramFts5Index.php`, synthetic test `test_fts5_index.php`, and dedicated workflow `.github/workflows/test-0927-engram-dev71-fts5.yml`.

The component is an OPTIONAL lexical index only. Canonical truth remains `engram_revisions`; FTS rows are disposable/rebuildable. Migration metadata is versioned (`INDEX_VERSION=1`) and hard-coded `enabled=0`; the component cannot activate itself. No external API/service, embeddings or private-data export exists. Search requires server-supplied owner+namespace and bounds result count. Rebuild indexes only latest ACTIVE revisions; archived/withdrawn history is excluded.

## Reproducible evidence
Exact tested code SHA: `2a30f0d3dea9a390e58fb036ca95b381d06247c8`.
GitHub Actions run `35795437006`, job `106973529969`: **SUCCESS** on PHP 8.2 with `pdo_sqlite,sqlite3`; checkout, runtime capability and test step all green. Synthetic test contains 14 assertions covering FTS5 capability, disabled preparation, rebuild from canonical latest-active rows, owner isolation, namespace isolation, withdrawn exclusion, BM25-ranked bounded OR query, explicit stale-index behavior + deterministic reindex, malformed scope and oversized-limit denial.

Critically, the synthetic paraphrase query `permission` against text containing `OAuth write consent ... read access` is required to return zero. This records the truthful boundary: SQLite FTS5 is a useful private lexical baseline, **not semantic retrieval**. It must not be relabelled semantic merely because ranking works.

## Technical/privacy decision
FTS5 is compatible in CI and requires no external service, so keeping a disabled, rebuildable lexical baseline is technically plausible. This run does NOT establish FTS5 availability/performance on the real All-inkl PHP/SQLite build; no host write or extension installation was attempted. The automation execution environment itself had PDO but no pdo_sqlite, reinforcing that extension availability must be detected rather than assumed.

Embeddings/vector search are NOT prepared or enabled in this slice: no verified local vector/embedding extension or privacy-preserving local embedding runtime on the actual host has been established. Sending private memories to an external embedding service would be a new external data transfer/cost/privacy decision and is outside authorization. Hybrid search therefore remains a future option only after read-only host capability/resource evidence and a local/private embedding design demonstrate benefit on synthetic paraphrases.

## Next conflict-free step
Return to the higher-priority DEV-70 mutation integration: inspect original OAuth/MCP/store sources and implement the separate `engram.write` consent/scope/grant path without upgrading existing `engram.read` tokens, then real-SQLite tests for idempotency, expected revision/hash concurrency, archive-without-delete and sanitized audit records. Keep DEV-71 disabled. Separately, a later read-only host capability probe may check SQLite version/FTS5 compile options and PHP memory/time limits; no installation or production DB mutation is implied.
