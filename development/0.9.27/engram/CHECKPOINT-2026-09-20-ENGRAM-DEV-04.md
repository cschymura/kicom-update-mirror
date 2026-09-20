# KiCom Engram — DEV-04 authenticated-path probe preparation

Date: 2026-09-20. Continues DEV-03. PUBLIC CODE/PROOF ONLY; NO private engram or actual conversation data, tokens, private backups, host-side file contents or secrets included.

## DEV implementation and verified executable proof

- Added `KiComEngramPrivatePathProbe.php`: isolated, non-network, non-route PHP component. It validates existing private sibling data/backup directories, their parent permissions, symlink-free path components and a supplied real document root. It uses random synthetic bytes in exclusive private test files (0600), reads them back, removes them and returns only booleans. No absolute path or private file content is returned.
- Added `test-private-path-probe.php`: synthetic positive case, complete cleanup, and denial of webroot/nested-webroot data, private directory permission downgrade, backup permission downgrade, parent permission downgrade, symlink ancestor, unrelated backup path, missing webroot; verifies return value explicitly does NOT imply HTTP exposure verification.
- Existing Engram CI workflow was not modified. The separate synthetic probe suite is invoked through existing `test-engram.php` and compiled/executed in existing PHP CI. No runtime KiCom core/DEV router changes have been made.
- Tested code SHA `58f69aad08c2be70a199a88eea4229b32da9a57a`, workflow `KiCom private Engram DEV`, run **35503916542**, job **106060379122**, conclusion **success**. Job log confirms `KICOM_ENGRAM_TESTS_PASSED=49`, `KICOM_ENGRAM_PATH_PROBE_TESTS_PASSED=11`, PHP lint, unchanged original R3 package and source manifest preflight. Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35503916542

## Verified integration boundary

Read original R3 `modules/dev/DevRouter.php`, `DevSession.php`, `DevEndpoint.php` and `DevKiComBindings.php`. The current production DEV router has an exact fixed operation-to-capability map; the current DEV session capability registry does NOT contain `engram.path.probe`. Thus the probe is NOT callable through an authorized existing DEV operation and **must not be attached to an existing unrelated capability**, a public/unauthenticated PHP script, a generic deployment target or an unbounded arbitrary filesystem endpoint. A future reviewed candidate requires coordinated changes to the DEV session capability map and router/handlers with fixed server-provisioned paths (never request-supplied), plus regression tests for no unauthenticated invocation and no privilege escalation. This would itself need the normal protected KiCom installation authorization; internal DEV proof alone does not grant installation permission.

## Host inspection and remaining gates

The operator's private folder, data and backups exist with private directory mode 0700; harmless canary file mode 0600. Initial external URLs did not return the canary. These observations do NOT certify HTTP isolation across alternative mappings or host permissions. No PHP code was uploaded or executed on the hosting account; no server-side PHP identity, open_basedir, effective writable access, realpath of all web roots, database persistence or restore drill was demonstrated. No personal memory import or installation occurred.

Next safe step: implement a fixed, separately scoped and identity-bound DEV route in an isolated candidate, test negative authorization and full webroot mapping offline, and only then request controlled authorization to install a minimal probe. Before real memory: complete deletion/retention including revision history, journal and all independent backup copies, and verify operator-controlled private backups/restores. Continue reading latest BOOTSTRAP and collaboration protocol at future handoff.

Documentation commit is not a new executable test; pinned proof refers to the code SHA above.
