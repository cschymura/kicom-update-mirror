# Mirage Engram DEV-44 — fail-closed MCP runtime authorization gate, CI GREEN

Date: 2026-09-21. Base DEV-43 checkpoint `69b71f5e4e0ea04b97833b617909bf88c745d422`; GitHub branch `work/kicom-0.9.27-pam`. Christoph requested continue. Before editing, checked branch and Slack `D0C2D8CKWDD` for conflicts. Slack CLAIM for DEV-44 was posted. Earlier 0.9.29 live synthetic SQLite roundtrip and first-party Passkey-owner mapping were **accepted** from Christoph's screenshots, not repeated.

## Executable code (not just a checkpoint)
- Added `KiComEngramMcpRuntimeGate.php` and `test-engram-mcp-runtime-gate.php`; added dedicated CI workflow `.github/workflows/test-0927-engram-mcp-runtime-gate.yml`. The runtime gate is a **DEV-only, in-process, read-only** boundary: no public HTTP/MCP route, no first-party login, no production activation, no config writes.
- Adapter/store factory is invoked only AFTER separate server-verified connector identity, reviewed AND enabled server-only host policy, exact connector/owner/host evidence binding, single active activation-state row, and fresh lookup of the CURRENT private passkey-owner registry (revocation/rights/namespace). The actual 0.9.29 default scaffold has no MCP grant and is rejected BEFORE opening a store.
- Project namespace only. A read through JSON adapter/contract/store is proved on synthetic fixtures; empty/malformed/oversized JSON and foreign namespace fail closed. Writes/append are rejected by this entry point: a separate signed exact-content, one-use consent path must be integrated before allowing any production MCP write.
- Owner binding convention in the DEV fixture is opaque SHA-256 of `mirage-owner`, NUL separator and verified credential fingerprint. This must be reconciled with the **actual** server-side owner evidence issuance before integration; never assume an HTTP-supplied owner/fingerprint or an arbitrary evidence hash is authoritative. Tested `active` policy is a synthetic fixture, NOT real All-inkl activation.

## Exact proof
- All new code + test + workflow committed at SHA **`d981cb9b39a0ac9d69c4854c8c91d6a6c6b47daf`**.
- GitHub Actions dedicated run **35576253079**, job **106258665405**, conclusion **success**, exact head SHA above. Original immutable KiCom 0.9.26-R3 package + source manifest verification green; PHP lint for six files green. **42/42 synthetic positive/negative tests** completed, including the actual-0.9.29-style inactive scaffold, no adapter factory before grant, revoked/foreign owner, missing/foreign activation state, wrong connector, request owner spoof, replay, unsolicited append rejection and SQLite quick_check.
- Run URL: https://github.com/cschymura/kicom-update-mirror/actions/runs/35576253079
- No production installation, no actual host isolation attestation, no real memory, no credentials, no ChatGPT app registration or independent chat recall were performed. These components exist only in the GitHub DEV branch, not in an installable KiCom 0.9.30 update.

## Next concrete milestones
1. Complete a first-party authenticated and privacy-safe *real-host* verification path for aliases/vhosts, PHP UID and cross-app read/write separation, open_basedir, backup+restore; no spoofed checked=true flags as evidence.
2. Reconcile verified owner binding with DEV-39/40 evidence, integrate in-process gate with original KiCom host-runtime loader and signed Passkey registry, preserving default inactive and native packaging/rollback. Do not wire an unauthenticated public PHP route.
3. Construct native full follow-up update only after original package verifier + integration tests pass; operator separately authorizes install and later private-runtime activation.
4. Build actual supported remote MCP auth/HTTP endpoint and connect a real ChatGPT plugin; prove independent new-chat recall of a synthetic server record. Current synthetic adapter is NOT a finished MCP protocol server.
