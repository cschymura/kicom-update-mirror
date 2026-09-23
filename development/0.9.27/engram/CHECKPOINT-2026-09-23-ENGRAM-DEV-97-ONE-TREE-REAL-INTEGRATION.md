# MIRAGE DEV-97 — native ONE-TREE integration: first real source conflict repaired

2026-09-23, 21:12 Europe/Berlin. DEVELOPMENT ONLY; no live KiCom change and no installable ZIP.

The exact conversation parent **KiCom 0.9.37** is present in this runtime at `/mnt/data/KiCom-0.9.37-Mirage-MCP-META-FIX-VOLLSTAENDIG.zip` and its SHA256 was re-verified as `0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7`. Extracted original source was inspected, including real `admin.php`, `api.php`, original `KiComEngramOAuthMcpHostBridge.php`, runtime config, genuine `KiComEngramStore.php` and `KiComEngramPrivateOwnerRegistry.php`. This proves availability **in this runtime**, not in another chat.

## Real blocker discovered by attempted integration

The old `dev91/native-0937-host-gated-write-dispatch.patch` proved MALFORMED when passed to real GNU patch: its four unified-diff hunk counts disagreed with actual hunk contents. After locally correcting counts, **all four hunks FAILED against original 0.9.37** because their context was not taken from the original host. Previously reported local syntax checks of separately staged code were NOT evidence this historical GitHub patch could integrate from exact original.

**Actual fix:** regenerate from the *actual untouched 0.9.37 host source* using exact anchored replacements and Python unified_diff. Commit `dev97/NATIVE-exact-original-host-gated-write-dispatch.patch`; local SHA256 `125700ce7d5fc7d912b32c0dfb5f6246e0291e6d2684b0ddc106a7c57c37711c`. It passed BOTH `patch --fuzz=0 --dry-run -p1` and actual `patch --fuzz=0 -p1` on a separate extracted original file; `php -l` of original-native edited host passed. Legacy `engram.read` path remains before new combined-scope branch.

A full patch-input scan identified another three malformed hunk counts, separately corrected on THIS branch only: DEV93 original OAuthTransactions `@@ -256,10 +345,16 @@`, DEV94 OAuthHttp `@@ -142,28 +154,5 @@`, DEV92 original api.php `@@ -84,9 +84,36 @@`. Do not reintroduce their historical malformed versions in the release.

## Actual integration tools committed

- `dev97/stage_native_one_tree.py`: requires exact original parent SHA and a real checkout of THIS GitHub branch, attempts ALL DEV90/93/94/91/97/92/95 native patches with `patch --batch --fuzz=0`, flattens specified dev module imports into native `modules/engram/`, adds client JS, checks unchanged original guardian/recovery/passkey kernel, PHP-lints entire staged source; FAILS CLOSED on the first original-hunk conflict and never emits a release ZIP or modifies production. A future actual run needs BOTH verified parent and locally accessible real repository source; no fabricated checkout/sandbox path.
- `dev97/test_integration_inputs.py` and `.github/workflows/test-kicom-dev97-one-tree.yml` provide reproducible hunk-count/source-presence checks for ALL 11 expected native diffs and their 13 PHP helper modules plus client asset. GitHub Actions run **35907620078**, job **107339140775**, independently checked **SUCCESS** on the branch. This test checks unified diff *syntax and source inventory*, not successful application of all 11 diffs to original source.

## Next real work and release blockers

Run the one-tree stager against a verified on-disk branch checkout + exact parent, inspect/fix any remaining **content/context** mismatch without fuzzing, then genuine synthetic PHP 8.2/ext-pdo_sqlite original HTTP/Passkey/OAuth/MCP end-to-end, original native Store schema, additive active private backup+owner right administration, original Manifest/Genome/modules.json/updater/recovery/rollback, ONE complete tested package. Do not claim ready from patch syntax CI. The verified original package is an old standalone ZIP; no private database, memories, bearer tokens, live OAuth/write scopes, or production website were changed. Previously proven actual independent live read retrieval is still the baseline; combined write and durable ChatGPT refresh are **not** live-proven.

Night task was updated to this branch and these specific integration blockers. User needs no new installation or manual relay at this stage.
