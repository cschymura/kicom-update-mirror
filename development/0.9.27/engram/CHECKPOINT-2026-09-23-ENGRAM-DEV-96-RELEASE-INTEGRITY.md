# MIRAGE DEV-96 — Exact-original package lineage + native release-integrity gate
Date: 2026-09-23. Development only. **No live write, DB migration, OAuth grant, package installation or ready-to-install ZIP.**

## Real original source confirmed in current working container
`KiCom-0.9.37-Mirage-MCP-META-FIX-VOLLSTAENDIG.zip` exists as a conversation attachment in the present container, SHA256 exactly `0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7`. Original archive has **82 files**, 81 file SHA records in `MANIFEST.sha256`, 74 `genome/genome.json` declared components, and 52 `genome/modules.json` module declarations. The original manifests/genome match actual ZIP bytes; the seven documented non-genome items are the six memory/*.kcl state documents plus genome/genome.json itself.

## Actual new code, versioned on GitHub
- `dev96/verify_native_release.py`: fail-closed, READ-ONLY exact-parent SHA/ZIP safety/manifest SHA+coverage/genome checks. For a FUTURE full native staged candidate, also verifies new genome parent/generation, untouched original recovery/guardian/Passkey kernel, original OAuth+activation admin and API writer entrypoints, all required flat first-party operator source modules present and genome-tracked. Outputs machine-readable JSON; `candidate_ready` remains FALSE even when these static checks pass because real native PHP/PDO/HTTP/MCP, updater and rollback tests still need to pass.
- `dev96/test_release_gate.py`: nine original-archive positive/negative fixtures, including changed admin with stale MANIFEST, updated manifest but stale genome, missing original OAuth module, unchanged parent posing as a new release and original manifest restoration.

## What was ACTUALLY executed, with limitations
Current local verifier `--parent` returned `ok:true` and exact original SHA, 82 files, original genome ID `kicom-0.9.37-g36-mirage-mcp-meta-protocol-compat`. The local original ZIP fixture's **9/9** synthetic fail-closed test assertions passed after fixing an initial development bug where candidate-tree directories were incorrectly rejected; this fix is included in the committed verifier. Local Python syntax compilation passed. An *incomplete, inspection-only* staged tree containing extra development inspection files was correctly rejected with `MANIFEST_COVERAGE_MISMATCH`; it is NOT a release candidate.

The local tested suite and the committed suite share the same nine checks; the committed test takes the parent via required `--parent` argument rather than using a hard-coded local filename. Its execution on GitHub CI was **not** verified and is not claimed. The exact parent ZIP is NOT in GitHub; do not upload private original archives casually or allow network fallback to a different version. The original source can be materialized from an actually present conversation attachment when available.

## Reconciliation of a suspected problem
A preliminary review suspected that private WRITE consent might be accepted across different tokens of the same owner. Direct re-reading of current DEV89 `KiComEngramPrivateWriteConsentReader.php`, DEV91 `KiComEngramNativeMcpWriteGate.php`, and their positive/negative fixtures demonstrated that the current branch ALREADY binds the consent row using `t.token_hash=c.token_hash` and tests two independent same-owner tokens and rebinding rejection. **No duplicate patch was created and no regression was claimed where the actual code was already correct.**

## Next concrete release work
1. **ONE fresh exact-parent 0.9.37 source tree**: integrate DEV90/91/92/93/94/95 canonical patches in the right order, flatten dev source imports and correctly version/update manifest/genome/modules.json. DEV94 supersedes obsolete conflicting DEV82 exchange patch. Use this verifier as a compulsory static release gate, never as a substitute for real integrated tests.
2. Execute ACTUAL original PHP+pdo_sqlite/HTTP/Passkey/MCP, consent/token-binding, backup/rollback and cross-chat old READ + new WRITE flow against synthetic nonproduction databases. Current isolated DEV95 HTTP tests use FAKE passkey verification and FAKE migration; original live E2E remains unproven.
3. Build ONE complete native KiCom installer preserving original guardian/recovery, run real native verifier/Genome/updater/rollback and require Christoph's **separate explicit production installation approval**. After installation ONE real new-chat/iPhone OAuth trial; if it fails STOP automatic auth redesign and review concrete KiCom-vs-ChatGPT behavior jointly. No need for Christoph to do a technical relay during development.

No productive changes, private tokens or user memory contents were accessed or transferred in DEV96.
