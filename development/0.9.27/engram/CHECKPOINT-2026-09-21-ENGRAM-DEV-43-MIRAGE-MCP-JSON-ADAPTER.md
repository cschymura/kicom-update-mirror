# Mirage Engram DEV-43 — bounded MCP connector JSON adapter GREEN

Date: 2026-09-21. Branch `work/kicom-0.9.27-pam`. Single Mirage Engram instance.

## Conflict check / accepted real state

Read current branch HEAD DEV-42 and Slack DM `D0C2D8CKWDD` before writing. No newer conflicting Engram claim was present. Operator-confirmed KiCom 0.9.29 server SQLite roundtrip and signed passkey-owner assignment were not repeated. Real private API remains `INACTIVE_PRIVATE_SCAFFOLD`; independent ChatGPT/MCP retrieval remains unproven. No authorized All-inkl host-capable path was available in this run for vhost/alias/PHP-UID/open_basedir or backup-boundary inspection.

## Executable section completed

Added `KiComEngramMcpJsonAdapter.php`, `test-engram-mcp-json-adapter.php` and dedicated workflow. Adapter is an in-process DEV protocol boundary only: it opens no HTTP route and performs no authentication. Verified identity is supplied separately by the server-side caller and is never accepted from JSON. Request and response byte caps are explicit. Malformed JSON, top-level lists, oversized requests, unknown fields, owner spoof, foreign verified identity, disclosure-limit violations and inactive API all fail through minimized connector errors without exposing internal SQLite/auth/nonce details. Synthetic append/read performs connector JSON -> adapter -> DEV-42 controller -> authenticated contract -> private store -> minimum projection -> JSON response.

## Exact proof

Workflow `KiCom Engram MCP JSON adapter`, run **35562902635**, job **106219020206**, exact tested SHA **6a45f495d56dd4c93a732589edf7245103ec63b4**. The substantive steps `Verify immutable original R3 package and source` and `Lint adapter stack and execute synthetic connector roundtrip` completed successfully; checkout/PHP setup also succeeded. Original R3 package/source verifier is unchanged. No private data, passkeys, tokens or host configuration are test fixtures.

Run: https://github.com/cschymura/kicom-update-mirror/actions/runs/35562902635

## Boundary / next step

No production installation, host configuration, API activation, connector registration, secret/passkey, backup or private memory changed. This is not evidence of an independent ChatGPT memory retrieval.

Next conflict-free section: design and executable-test the **server-side activation gate** that can construct this adapter only from verified owner/passkey registry state plus an explicit inactive/active host policy, while retaining default inactive and without exposing a public route. Include negative tests for absent/ambiguous owner binding, disabled host policy, connector mismatch and stale/revoked binding. If an authorized host-capable read path becomes available first, prioritize real privacy-safe vhost/alias/PHP-UID/open_basedir and backup-isolation evidence. Actual activation and ChatGPT plugin/MCP registration remain separate operator-authorized milestones.

This checkpoint commit is documentation only; executable proof is the exact SHA/run above.
