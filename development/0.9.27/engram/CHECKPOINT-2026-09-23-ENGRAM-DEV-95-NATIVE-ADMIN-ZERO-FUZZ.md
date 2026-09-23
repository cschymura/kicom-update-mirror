# MIRAGE — original 0.9.37 admin route verification after DEV-95 (2026-09-23)

## Actual native source verification, not a simulated replacement class

The ORIGINAL KiCom-0.9.37-Mirage-MCP-META-FIX-VOLLSTAENDIG.zip was confirmed present in the current working container and SHA256 **0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7**.

An initial inspection uncovered a real packaging obstacle: the previous `dev95/NATIVE-original-admin-active-db-passkey-upgrade.patch` only applied to this exact original `admin.php` with GNU patch **fuzz 2**, which is unacceptable for unattended original-file lineage assurance. The route patch was regenerated as an exact original-to-modified unified diff and committed in canonical form: `@@ -87,6 +87,55 @@`, with all three original following context lines. The original user-approved KiCom admin route was NOT changed in production.

The original `admin.php` was extracted fresh from the verified ZIP in an isolated workspace. The **corrected** active-upgrade route patch applied using **`patch --fuzz=0 -p1`**. A separate native admin-menu patch adds a discoverable `Datenbanken vorbereiten` link to `admin.php?engram_active_upgrade=1`; its exact original HTML row also applied with **`--fuzz=0`** on the staged route, with only the expected 49-line offset from prior insertion. `php -l admin.php` returned no syntax errors. Assertions confirmed exactly ONE navigation link, the original OAuthAuthorizeHttp handler remains present exactly once, and the original owner activation admin link remains available.

The regression script `dev95/test_exact_original_admin_entrypoint.sh` is committed for a runner with the exact local original ZIP. It MUST NEVER retrieve it from a random network URL or use another edition. In this turn the equivalent zero-fuzz original-archive staged route+navigation was executed locally; the GitHub workflow currently tests the DEV95 PHP components and synthetic HTTP control-flow, NOT this original-native script.

## Main missing integration

This proves ONLY that the actual original native admin entrypoint is patchable without fuzzy source matching and is reachable from the admin menu. It does NOT test real Passkey signatures, source flattening, the real KiCom OAuthTransactions::prepareWriteSchema/prepareRefreshSchema, actual native current-owner WRITE rights/approval, or HTTP/MCP end-to-end on a live host. There is still no full native release ZIP, no permitted production install, and no proof of a new-iPhone/ChatGPT connection.

Do not publish individual DEV95 files as an update. Next material work: combine DEV90–95 against ONE exact 0.9.37 original source tree, use the canonical DEV93+94 OAuth merge (do not reapply obsolete DEV82 exchange), flatten native module imports, and execute the real original PHP PDO SQLite, first-party HTTP/OAuth/MCP test suites and original MANIFEST/Genome/updater/recovery/rollback before one complete package may be presented for Christoph's separate approval.
