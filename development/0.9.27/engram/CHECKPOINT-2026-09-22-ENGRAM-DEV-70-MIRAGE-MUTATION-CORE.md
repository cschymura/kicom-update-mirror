# Mirage DEV-70 — operator core, after independent live read (2026-09-22)

## Proven baseline
Christoph reports a real independent ChatGPT MCP `engram_search` response from private KiCom with the precise synthetic project record, id and revision=1. This is the **read-only success milestone**, not evidence that writing, updating, archiving or automatic recall without tool calls is live.

The exact current full operator-host parent attachment is KiCom 0.9.37. No production mutation, update, OAuth scope expansion, Passkey activation or live write was performed in this iteration. The four-operator user goal remains search + write + update + archive.

## Concrete development output
Created the PHP class `KiComEngramMcpMutationCore.php`, test `test_mutation_core.php`, README and a byte-identical copy of parent `KiComEngramStore.php` as local DEV-70 work. Portable, non-installable conversation artifact:

`KiCom-Engram-DEV70-Operatorenkern-NICHT-INSTALLIEREN.zip`

SHA256: `0e5f8dc4546e7bb3718c1e013477dabf8e69d36bfc3c06fee25f0d11761f5a0a`.

Contains standalone server-side validation for `engram_write`, `engram_update` and `engram_archive`, plus strongly typed calls into the existing store's create/revise methods. Owner and namespace are server-locked; args cannot set owner/namespace/scope/approval. A read-only OAuth token, revoked owner rights, wrong owner/namespace, invalid format, expired/mismatched grant, stale revision precondition, extra fields and unsupported operation are all rejected by validation. An archive appends a withdrawn revision rather than hard deletion.

## Actual tests
`php -l` for the new module and test passed; `php test_mutation_core.php` returned **24 passed, 0 failed**, including 3 positive validation scenarios and 21 rejected negative cases. ZIP CRC verification passed. This environment's PHP has PDO but lacks **pdo_sqlite and ZipArchive**, so **no real SQLite mutation, OAuth write consent, token issuance, signed grant validation, race/nonce/idempotency or original KiCom native update verification was tested**. The bundled file is NOT a KiCom release or deployable ZIP.

## Exact blocking integration work
- Add genuine, separate `engram.write` scope in existing first-party OAuth issuer + signed passkey/consent UI + revocation. Do not expand the previously issued `engram.read` bearer tokens implicitly.
- Create a first-party verifier for a server-owned, time-bound, token- and connector-bound write grant; trust no approval, owner or rights fields from MCP JSON.
- Bind three accurately classified MCP tools to the existing private store with a scoped current owner registry, sanitized audit records, expected hash/revision for concurrent update/archive, safe retries/idempotency and testable failure responses.
- Expose current `revision_hash` on authorized relevant read response for version preconditions.
- Run positive AND negative tests using actual SQLite, on-host native manifest/Genome verifier; check read-only `engram_search` regression; then propose ONE full native release with separate user installation authorization.

Source and tests are currently inside the exact conversation artifact only. They were NOT uploaded to GitHub as source in this turn; this checkpoint preserves exact hash and remaining steps. Do not invent a GitHub source path or claim live operator availability. No password, token, private memory body or OAuth URL should be published in public GitHub.
