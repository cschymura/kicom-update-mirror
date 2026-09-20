# KiCom Engram — DEV-06 isolated first-party DEV endpoint wiring

Date: 2026-09-20. Follows DEV-05 and `development/0.9.27/COLLABORATION-PROTOCOL.md`. Public GitHub: synthetic code, tests and proof metadata only. No private engrams, personal conversations, actual private DB, backup bytes, private host files, session IDs/tokens, OTP or live trusted-path configuration.

## Scope and verified source

Live KiCom BOOTSTRAP / PROJECT_STATE report 0.9.26. This cycle reads the canonical KiCom resources and inspects the exact original 0.9.26-R3 DEV source. All original `source/0.9.26-r3` files and the R3 release ZIP remain unmodified; CI's existing full original source manifest and pinned release-package preflight both passed.

## Executable candidate, not a host installation

- `KiComEngramDevCandidatePatcher.php` now hash-checks ORIGINAL source `DevSession.php`, `DevRouter.php`, `DevHttpAdapter.php` and `DevEndpoint.php` against the pinned R3 source SHA-256 manifest **before** writing into a new empty private disposable test directory. The generated endpoint registers `DEV_ENGRAM_PATH_PROBE` only if `kicomEngramDevTrustedConfig()` has already been supplied by trusted server bootstrap AND both bounded local module files are present. It uses the existing session manager and dedicated `engram.path.probe` capability, adding no production/secret/recovery or self-update authority. A missing provider or missing module keeps the handler UNREGISTERED.
- The generated endpoint explicitly rejects `DEV_ENGRAM_PATH_PROBE` on the GET DEV bridge; the synthetic probe is available only through the existing POST DEV API credential boundary in this candidate. A future host deployment must also apply anti-cache, TLS and safe log policies; GET denial is not a full transport-security proof.
- Candidate copies include `KiComEngramDevPathHandler.php` and `KiComEngramPrivatePathProbe.php` with no personal data. The handler requires empty request payload, checks an already-authorized DEV session and dedicated capability, resolves the three paths only via the trusted server callback, runs bounded synthetic private-file create/read/remove, and returns only booleans/generic codes, never filesystem paths/content. Existing actual private memory CRUD is neither exposed nor enabled.
- `fixture-engram-trusted-config.php` is a SYNTHETIC TEST FIXTURE only, not a server configuration or production provisioning mechanism.
- `test-engram-dev-route.php` now verifies the expanded pinned candidate. `test-engram-endpoint.php` invokes the generated `DevEndpoint.php` with a synthetic first-party runtime binding/diagnostics fixture and the original-derived DEV session/router/HTTP adapter. It checks no provider => unregistered, missing/invalid/revoked credentials, GET-bridge denial, request-provided path injection denial before provider invocation, positive authorized write/read/cleanup, no path disclosure, invalid trusted config fail-closed, and missing module => unregistered. The test does not execute a genuine WebAuthn browser assertion or a live host PHP SAPI.

## CI history and exact verified SHA

An intermediate candidate failed CI (run **35504501749**) because the offline patcher used PHP double-quoted strings containing unescaped `$handlers`/`$op` variables. This was corrected in commit `a3ec56e1dd89713436fdaff0236ad2e07c93e55d`. Do NOT treat the intermediate failing runs as passing.

Latest tested code SHA: **a3ec56e1dd89713436fdaff0236ad2e07c93e55d**.
GitHub Actions workflow `KiCom private Engram DEV` run **35504545465**, job **106062025329**: **success**. Original R3 source/package integrity preflight succeeded; PHP lint + synthetic suite succeeded.
Log markers: `KICOM_ENGRAM_TESTS_PASSED=49`; `KICOM_ENGRAM_PATH_PROBE_TESTS_PASSED=11`; `KICOM_ENGRAM_DEV_ROUTE_TESTS_PASSED=23`; `KICOM_ENGRAM_ENDPOINT_TESTS_PASSED=11`. Total **94 synthetic checks**.
Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35504545465

## Explicit deployment gate and immediate next safe work

This is an **isolated candidate only**. It is NOT wired into the running KiCom DEV endpoint; the real `kicomEngramDevTrustedConfig()` provider is NOT provisioned, and no host-side PHP identity/read-write/realpath or HTTP alias exposure checks have run. The original R3 `DevEndpoint.php`, session/router, actual installed modules, public webroot, existing databases and personal memories remain unchanged. The private Engram directory must not be configured as a public deployment target.

Before any authorized controlled installation, prepare an operator-reviewed, server-only fixed configuration and all-host-webroot/alias survey, an actual host identity/open_basedir test, candidate release manifest/genome/module loader verification, ordinary KiCom verifier/backup/health/rollback with explicit human authorization where required, and negative authorization checks through the actual installed POST endpoint. Do not use GitHub test-fixture configuration as a live config or bypass the existing protected installation boundary. Before ingesting real memory: implement audited user-scoped identity-bound CRUD, consent/redaction, and real erasure/retention covering revision histories, WAL/temp and every backup copy.

This documentation commit does NOT represent a new executable test. Resume from pinned SHA / run above.
