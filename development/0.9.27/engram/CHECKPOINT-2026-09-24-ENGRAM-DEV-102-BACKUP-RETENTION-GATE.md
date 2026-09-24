# KiCom Engram DEV-102 — retained-backup native gate correction

Date: 2026-09-24. Branch: `work/kicom-engram-dev100-one-tree-validation`.

## Concrete change

Reviewed the DEV-101 whole-staged-native-tree PDO SQLite gate against the actual DEV-95 `KiComEngramActiveSchemaUpgrade` retention behavior. The gate incorrectly assumed that the private backup directory must contain exactly two `dev95-*.sqlite` files after an upgrade. That assumption is incompatible with retained append-only backups from an earlier successful/aborted operator run and could falsely fail a legitimate future full-tree validation.

Commit `9dd8dd471c7683b173e51924d2ee04835bfc702f` corrects the gate. The fixture now starts with a valid retained prior SQLite snapshot, records the pre-run snapshot set, proves a denied/inactive runtime creates no new snapshots while preserving the retained one, and after the permitted upgrade requires **exactly two newly-created** snapshots by set difference. It separately verifies both new snapshots are private and `quick_check=ok`, and that the retained prior snapshot still exists.

This changes the full-tree validation harness only; no runtime module, OAuth policy, database, production host, token, Passkey, or release candidate was modified.

## Evidence and limits

The automation runtime was checked for the original parent ZIP and `/mnt/data` is empty. Conversation Files also reports no current-conversation uploads. A Library search did not locate the SHA-pinned KiCom 0.9.37 parent ZIP. Therefore the actual DEV-101/102 whole-tree PDO gate cannot be honestly executed in this runtime and no native PDO assertion count is claimed here.

The source-only workflow triggered for exact commit `9dd8dd471c7683b173e51924d2ee04835bfc702f` as Actions run `35936528573`; it validates/export-tracks source inputs only and MUST NOT be treated as the native PDO gate.

Expected private parent SHA-256 remains `0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7`. Never upload that private parent, private DBs, tokens, Passkeys, or memories to the public repository.

## Next step

At the first runtime that actually contains the parent ZIP, verify its SHA, stage the one native tree with current DEV-100 sources, then execute the corrected DEV-102 PDO gate in a PHP runtime with `ext-pdo_sqlite`. Continue on that same integrated tree into first-party Admin/WebAuthn/CSRF and HTTP/MCP OAuth read/write/update/archive/refresh/replay regression. No production activation before the separately required operator approval.
