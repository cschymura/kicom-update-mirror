# DEV-48 — KiCom 0.9.31 host integration checkpoint

Date: 2026-09-21. State: **HOST INTEGRATION BLOCKED ON TRUSTED 0.9.31 SOURCE; no deployment performed**.
This file contains only architecture/status and synthetic-test references. No private memory, tokens, owner records, session material, backups or retrieved results.

## Verified observations (read-only)

- Live first-party KiCom BOOTSTRAP, DESCRIBE and CHAT_UPDATE_STATUS each report installed version **0.9.31**. CHAT_UPDATE_STATUS reports no pending release at the time checked; the same status exposes server-side build, chunked direct and raw POST package paths.
- Live canonical PROJECT_STATE still describes version 0.9.26 and NEXT contains 0.9.25/0.9.26 tasks; do not mistake those stale documents for the currently running release or patch them from the public mirror without verified 0.9.31 source.
- Public GitHub mirror `main` still publishes 0.9.26 R3. The current `work/kicom-0.9.27-pam` Engram modules are **isolated DEV components**, not an installable update to 0.9.31.
- DEV-47 authenticated HTTPS -> MCP JSON-RPC -> synthetic private SQLite tests completed 29/29 in GitHub Actions run https://github.com/cschymura/kicom-update-mirror/actions/runs/35579780736, checkout SHA `599deedbf3a02415f7672200954b5ba290b5236f`. This is an isolated CI result, not a real live ChatGPT/connector test.
- Live `?q=MCP_STATUS` currently returned HTTP 400; do not infer a production MCP endpoint from the DEV-47 isolated module.
- A public, unauthenticated trusted-source request is rejected (401). No authenticated source read was available in this work session. Do not attempt a 0.9.27/0.9.26 installation over 0.9.31.

## Safe exact integration sequence

1. Obtain **the actual verified 0.9.31 full source** through KiCom's authenticated trusted source/release archive interface, including installed version/genome and a pinned digest. Do not substitute the GitHub main snapshot or earlier source. Maintain the existing installation and private persistent stores unchanged.
2. Diff real 0.9.31 `api.php`, `lib.php`, `guardian.php`, trusted-module allowlist/genome and updater manifest against DEV-47. Locate the existing pre-router early POST dispatch and actual trusted configuration/owner registry/activation DB APIs; never guess their names or invent authentication.
3. Add an **inactive by default** first-party `POST api.php?q=ENGRAM_MCP` (or equivalently explicit verified KiCom route), routing only to existing DEV-47 HTTP transport **before generic KCL parsing**. Existing KiCom request/storage bootstrap and guardian must remain intact. Do not directly expose DEV files through the document root or create another unauthenticated public endpoint.
4. Derive `trustedWebRoot`, `trustedTokenRecord`, `trustedRuntime`, `activationDb`, `lookupOwner`, `adapterFactory` from trusted server-side KiCom configuration only. Deny if any are missing. Preserve current WebAuthn/passkey and owner revocation semantics; no client-supplied owner/namespace/authorization flags. Never log Authorization headers, token hashes, private SQLite content or search results.
5. Keep actual engram database, its WAL/SHM, snapshots, and token-hash record outside document root with ownership/perms verified by the existing component. Public GitHub is code-only. Existing KiCom operational SQLite is not the Mirage private engram store.
6. Validate synthetically in isolated staging: known-good 0.9.31 base is unmodified; inactive/unauthorized route leaks no tools or data; activated read-only MCP `initialize`, `tools/list`, `tools/call`, revocation and owner isolation; existing KiCom KCL/DEV/admin/update endpoints are unchanged; run existing 29-case DEV-47 suite plus version-specific integration regressions.
7. Only after a verified 0.9.31-derived candidate, verified archive backup, normal updater verifier and rollback review may a new version be offered through KiCom's regular self-update process. Production installation, new credentials, external connector access and importing real memories remain distinct human-authorized action boundaries.
8. Finally configure actual connector authentication using the supported ChatGPT connection flow and test from a **new, independent conversation**; record only status/identifiers, never private returned memory content in GitHub. Until this passes, claim neither operational cross-chat recall nor successful live MCP access.

## Next concrete input / blocker

The verified 0.9.31 source and trusted KiCom runtime binding interfaces are not present in the available public mirror and could not be read without an authenticated KiCom source session. **Do not guess or downgrade.** Once an authenticated source snapshot is available, prepare a 0.9.31-derived inactive staging patch against those exact files and run regression tests before an installation proposal.
