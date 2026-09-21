# Mirage Engram DEV-41 — authenticated MCP-facing contract GREEN

Date: 2026-09-21. Branch `work/kicom-0.9.27-pam`. Engram-only single-instance cycle.

## Conflict and accepted real boundary
Before writing, branch HEAD was DEV-40 checkpoint `9651d81015b08bc2da165cb7d80367dadb9c2b51`; Slack DM `D0C2D8CKWDD` was read. No newer conflicting Engram code claim was present. Accepted operator evidence was not repeated: KiCom 0.9.29 installed, `SYNTHETIC_SERVER_SQLITE_ROUNDTRIP_OK`, SQLite exists and signed passkey owner assignment is stored. Private API remains inactive and independent ChatGPT/MCP retrieval remains unproven. Actual All-inkl vhost/alias/PHP-UID/open_basedir/cross-app and backup isolation still has no authorized host-capable read path in this instance.

## Executable change
Added `KiComEngramMcpContract.php`, test and dedicated workflow. This is deliberately a DEV-only server-side transport contract, not an HTTP endpoint and not wired to production. It requires identity supplied by an already verified server-side connector, exact opaque owner binding, API state `active`, strict request schema, read/append-only operation, 60-second freshness, bounded result limit and SQLite-backed one-use nonces. Foreign identity/owner, replay, stale/future requests, admin operation, excessive disclosure and extra token/secret fields fail closed. Read projection allows only id/revision/body/source_kind and rejects unexpected private metadata.

The contract's `active` test fixture is synthetic policy exercise only: the real KiCom API is still inactive and DEV-40 activation primitive is not invoked.

## Exact green proof
Code/test/workflow SHA **`f8fc160c7313ea8d7d94d669074ddaffc664274c`**. Workflow `KiCom Engram MCP contract`, run **35556085325**, job **106199891082**, conclusion **success**. Exact SHA checkout. Immutable original R3 ZIP SHA-256 `6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f` and source manifest verified. PHP lint clean. Executable result **14/14**, including inactive fail-closed, valid synthetic envelope, nonce replay, foreign identity/owner, stale/future, limit, secret-field, admin-op, identity-token and private-row-metadata negatives plus bounded projection and append-envelope validation.

Run: https://github.com/cschymura/kicom-update-mirror/actions/runs/35556085325

## Boundary / next step
No host configuration, installation, activation, secret, passkey, private engram or external message was changed. This does **not** prove a connected ChatGPT plugin/MCP client or an independent memory retrieval. It establishes the missing server contract primitive that a future authenticated connector can target only after real host/backup isolation evidence and separate operator activation approval.

Next conflict-free section: integrate DEV-41 contract with the existing synthetic connector/client harness and Engram store behind an explicitly disabled controller facade; prove end-to-end synthetic read/append, identity propagation, replay protection and inactive-state denial without opening a public route. If an authorized host-capable read path becomes available first, prioritize privacy-safe host/backup evidence through DEV-38/39 instead. Real activation and ChatGPT plugin connection remain separate operator-authorized milestones.

This checkpoint commit is documentation only; executable proof is the exact SHA/run above.
