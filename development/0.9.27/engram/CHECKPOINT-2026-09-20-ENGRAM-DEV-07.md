# KiCom Engram — DEV-07 reviewed multi-webroot preflight

Date: 2026-09-20. Follows DEV-06, collaboration protocol and canonical KiCom BOOTSTRAP (live 0.9.26). PUBLIC GitHub holds code, synthetic tests and technical proof only. NO real personal memories, conversations, passwords, session IDs or tokens, OTPs, actual private host paths/configuration, backups or DB bytes.

## Concrete implementation

- `KiComEngramPrivatePathProbe.php` adds `runAgainstWebRoots(data,backups,webroots[])` for the fixed operator-reviewed list of **all known hosting/vhost/default-host document roots**, not just the KiCom website root. Requires a contiguous nonempty bounded list (1–32), absolute existing directories without symlink path components, no duplicate canonical roots and disallows filesystem root `/`. Validates the private parent/data/backup siblings against EVERY configured root BEFORE creating either synthetic test file; an alternate vhost rooted at the hosting account's parent is denied. Returns only a count of checked roots, private read/write booleans and `public_http_exposure_verified=false`. The previous single-root `run()` is retained for isolated backwards-compatible tests only.
- `KiComEngramDevPathHandler.php` requires the trusted server callback to supply EXACT keys `data`, `backups`, `webroots` (list), replacing single `webroot`. Request-supplied paths remain forbidden. Missing, invalid or contradictory config fails closed with a generic response and no private path disclosure. The first-party endpoint candidate remains inert without a preexisting trusted server callback and explicit independent `engram.path.probe` DEV capability, with POST-only access and no private-memory CRUD.
- Synthetic path tests include two valid independent sibling website roots, all-root preflight before write, missing/duplicate/symlink/invalid/empty/unbounded root inventory, alternate webroot pointed at private parent, hosting account parent or filesystem root and cleanup after all denials. End-to-end first-party endpoint tests additionally confirm two-root success, alternate default-host root denial and empty inventory denial.

## Verified executable evidence

**Tested code SHA: `8b11e56aca0163db1ab10ddb745ebde482720725`**.
Workflow: KiCom private Engram DEV; run **35504839711**; job **106062787167**; conclusion **success**.
Markers: `KICOM_ENGRAM_TESTS_PASSED=49`, `KICOM_ENGRAM_PATH_PROBE_TESTS_PASSED=23`, `KICOM_ENGRAM_DEV_ROUTE_TESTS_PASSED=23`, `KICOM_ENGRAM_ENDPOINT_TESTS_PASSED=14`; **109 synthetic checks**. Original source manifest and unmodified R3 release archive verified by the existing CI preflight; PHP lint and integrated synthetic suite completed successfully.
Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35504839711

## Critical host-side uncertainty — still NOT a real-host pass

An operator-configured list of paths cannot prove it includes every actual HTTP alias, alternate provider hostname, implicit default-host root, nginx/Apache alias or symlink mapping. Prior external 404 for a harmless test file proves ONLY those specific URLs did not serve that test file. To authorize actual private data, independently determine the authoritative hosting account default-host mapping and all relevant document roots/aliases, validate a harmless static canary from an unauthenticated network client for each relevant host/mapping, examine real PHP SAPI UID/permissions/open_basedir, and confirm fail-closed treatment of an omitted/unknown root. A public GitHub or synthetic CI fixture must never be substituted for this evidence. The new probe does NOT perform public HTTP testing.

No candidate installed, no KiCom production/DEV runtime mutation and no host-side PHP or private-memory write. Before any authorized controlled deployment: prepare a server-only reviewed fixed config outside public webroots, normal KiCom verified package/genome/backup/rollback process and independent human approval at existing protected action boundary. Before personal-memory import: complete explicit-consent identity-bound ingestion/retrieval, retention/hard-delete across immutable history and every backup, and independent backup/restore practice. Do not register the private folder as an HTTP deployment target.

This checkpoint documentation commit does not change the pinned tested code SHA.
