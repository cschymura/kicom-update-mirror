# Mirage Engram DEV-42 — inactive MCP controller + store roundtrip GREEN

Date: 2026-09-21. Branch `work/kicom-0.9.27-pam`. Single Mirage Engram instance.

## Conflict check / accepted real state
Read branch HEAD DEV-41 and Slack DM `D0C2D8CKWDD` before writing. No newer conflicting Engram code claim existed. Did not repeat operator-confirmed KiCom 0.9.29 server SQLite roundtrip or signed passkey-owner assignment. Real private API remains inactive and independent ChatGPT/MCP retrieval remains unproven. This instance still has no authorized All-inkl host-capable read/write path for vhost/alias/PHP-UID/open_basedir or backup isolation.

## Executable section completed
Added `KiComEngramMcpController.php`, `test-engram-mcp-controller.php` and dedicated workflow. The controller is an **in-process DEV facade only**: no HTTP/public route and no authentication primitive. It composes DEV-41 authenticated/replay-bounded MCP contract with the private Engram store. Default API state is `inactive`; inactive dispatch fails before store access. Synthetic active fixture exercises read/append only, derives store subject from server-side owner, retains namespace and disclosure limits, projects minimum read fields, and rejects empty append. No secrets/passkeys/private memories are fixtures.

## Exact proof
Workflow `KiCom Engram MCP controller`, run **35559379078**, job **106209181846**, exact tested SHA **1841a98b438550ba3722baf6555e550f44fc40eb**, conclusion **success**. Immutable original R3 ZIP SHA-256 `6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f` and source manifest verification passed. PHP lint and controller test step passed. Positive/negative coverage includes inactive fail-closed with zero store mutation, authenticated synthetic append/read roundtrip, minimum disclosure projection, replay denial, foreign identity/subject isolation, disclosure-limit denial, empty append denial, unchanged store after rejected append, and SQLite health.

Run: https://github.com/cschymura/kicom-update-mirror/actions/runs/35559379078

## Boundary / next step
No production installation, host configuration, API activation, connector registration, passkey, secret, backup or private content changed. Synthetic `active` is test policy only and is not evidence that KiCom private API is active. No claim of an independent ChatGPT memory retrieval.

Next conflict-free section: build a connector-facing protocol adapter around this controller without opening a route: deterministic request/response JSON schema, error minimization, response-size cap and explicit server-side identity injection; test malformed JSON/unknown fields/oversize/error disclosure and full synthetic connector→controller→store→projection roundtrip. If an authorized host-capable path appears first, prioritize privacy-safe host/alias/PHP-UID/open_basedir and backup-boundary evidence. Real host activation and ChatGPT MCP/plugin connection remain separate operator-authorized milestones.

This checkpoint commit is documentation only; executable proof is the exact SHA/run above.
