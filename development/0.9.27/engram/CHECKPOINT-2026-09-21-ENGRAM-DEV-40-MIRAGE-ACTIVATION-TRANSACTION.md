# Mirage Engram DEV-40 — disabled atomic activation transaction GREEN

Date: 2026-09-21. Branch `work/kicom-0.9.27-pam`. Engram-only single-instance cycle.

## Conflict / accepted boundary
Before writing, latest checkpoint was DEV-39 at `77bec8f6d3f8d060b67591a6d7bdb9b259b9396c`; Slack DM `D0C2D8CKWDD` was read and contained no newer conflicting Engram code claim. Accepted operator evidence was not repeated: KiCom 0.9.29 installed, `SYNTHETIC_SERVER_SQLITE_ROUNDTRIP_OK`, SQLite exists, signed passkey owner assignment stored. Private API remains inactive and independent ChatGPT/MCP retrieval remains unproven. Actual All-inkl vhost/alias/PHP-UID/open_basedir/cross-app and backup isolation still lacks an authorized host-capable read path in this instance.

## Executable change
Added `KiComEngramActivationTransaction.php`, test and dedicated workflow. It is deliberately not an HTTP endpoint and has no production wiring. It first requires DEV-39 readiness, then an exact, fresh, separately scoped operator-approval object bound to the same opaque owner and host evidence. SQLite performs an `inactive -> pending -> active` transition under `BEGIN IMMEDIATE`; committed approval nonces cannot be replayed, active state cannot be reactivated, foreign owner/host, stale/future approval, wrong purpose and unknown fields fail closed. A synthetic failure after `pending` must rollback both state and nonce atomically.

The first CI run `35552773976` on SHA `cf04a54a0a4e6f8260b2d1bfe969f7d13f5bd195` exposed a real rollback bug: PDO SQLite did not report a raw `BEGIN IMMEDIATE` through `inTransaction()`, leaving `pending`. This was fixed by explicit transaction ownership tracking rather than weakening the test.

## Exact green proof
Corrected code SHA **`f922ff4a26f6d3e42415dcd49a8c153df3616292`**. Workflow `KiCom Engram activation transaction`, run **35552801102**, job **106190560526**, conclusion **success**. Exact SHA checkout. Immutable original R3 ZIP SHA-256 `6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f` and source manifest verified. All PHP lint clean. Executable result **16/16** including mid-transaction rollback, retry only after uncommitted rollback, replay/second activation denial and scope/freshness negatives.

Run: https://github.com/cschymura/kicom-update-mirror/actions/runs/35552801102

## Boundary / next step
This proves only a synthetic disabled transaction primitive; it does **not** activate KiCom, mutate the real server, authorize activation, prove host isolation, connect MCP, or retrieve private memory. No secrets, paths, passkeys or private engrams were stored in GitHub/Slack.

Next conflict-free section: if host-capable evidence remains unavailable, develop the authenticated MCP-facing server contract around the still-inactive state: identity-bound read/write request envelopes, nonce/replay controls, minimum disclosure and fail-closed inactive/foreign-owner tests, with synthetic transport only. Do not expose a public unauthenticated route or claim independent ChatGPT retrieval. Real activation remains separately operator-authorized only after actual host/backup isolation evidence is complete.

This checkpoint commit is documentation only; executable proof is the exact code SHA/run above.
