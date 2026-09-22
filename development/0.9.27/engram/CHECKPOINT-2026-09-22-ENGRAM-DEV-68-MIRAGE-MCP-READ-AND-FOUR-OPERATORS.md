# Mirage DEV-68 — 2026-09-22 — MCP risk metadata and four-operator groundwork

## Production state
The operator's screenshot of a NEW ChatGPT chat shows two attempted `mirage-engram.engram_search` invocations were blocked by OpenAI security checks. NO authentic private-memory content returned; independent recall is NOT achieved. This is a platform/tool-access block, NOT a proven KiCom-origin 403/404. Do not claim metadata fixes will override platform safety checks. ChatGPT plugin UI previously showed `engram_search` with pessimistic default risk labels (public write / open-world / destructive); original MCP tool definition lacked annotations.

Read-only public KiCom GENOME_STATUS checked AFTER this development still reports live 0.9.35, healthy=true, lkg_ok=true, drift=0, unknown=0. No production installation or private-data mutation performed in DEV-68.

## Minimal RELEASE CANDIDATE for proving the original objective FIRST
Conversation artifact ONLY: `KiCom-0.9.36-Mirage-MCP-READONLY-FIX-VOLLSTAENDIG.zip`.
SHA256: `1b4b35de15ca9454add8501f5003411478a8091c5238a0063827a02a10af8b47`.
Exact parent `KiCom-0.9.35-Mirage-Engram-FREIGABE-VOLLSTAENDIG.zip` SHA256 `6e111846dac342ebf3b2e05df15b17266eff26016c4d48bf61375b951a27eb21`; parent genome id `kicom-0.9.35-g34-mirage-human-first-admin-confirmation`, new genome id `kicom-0.9.36-g35-mirage-mcp-truthful-readonly-annotations`, generation 35. 82 ZIP entries.

Exactly four paths differ: MANIFEST.sha256, genome/genome.json, lib.php, modules/engram/KiComEngramMcpProtocol.php. The last adds accurate `annotations={readOnlyHint:true,destructiveHint:false,openWorldHint:false}` to `engram_search` and improves title/description. Original OAuth tokens, scopes, owner registry, state, PasskeyBridge, recovery, guardian, admin, API router, private SQLite storage and all access checks are BYTE-PRESERVED. No new operator exposed by 0.9.36.

Local verification: CRC, path safety, 81 manifest hashes, 74 genome component hashes, byte-preserved crucial paths PASS; PHP -l 61/61 PASS, JS syntax PASS. Original parent KiCom update verifier accepted 0.9.36 as direct successor, 82 files, YELLOW, kernel_update=false. Corrupted negative fixture rejected MANIFEST_HASH_MISMATCH. Local PHP lacks ZipArchive/pdo_sqlite: original unchanged verifier was run with a TEST-ONLY ZIP adapter from Python-byte-verified exact ZIP; true native on-host verification and live OAuth/MCP recall have NOT yet occurred. ToolAnnotations are hints, not an override of platform safety controls.

Exact source patch: `development/0.9.27/engram/dev68/KiCom-0.9.36-READONLY-ANNOTATIONS.patch.txt`. Do not create a sandbox URL from this GitHub checkpoint in a new runtime.

## ALL FOUR operator interfaces prepared OFFLINE, NOT production enabled
A second exact conversation artifact `KiCom-Engram-Operatoren-DEV68-ENTWURF.zip`, SHA256 `f657d968dbcdc62ee4449d5da8ffef6a8475d7a52f6f3da36adfd9138b04c1aa`, contains `KiComEngramMcpOperatorDraft.php`, `test_catalogue.php`, README.txt. Defines accurate schemas and descriptions/annotations for:
- `engram_search`: read-only, non-destructive, private closed world;
- `engram_write`: append-only new record, write, non-destructive, non-idempotent;
- `engram_update`: revision-gated append of new revision, write with destructiveHint=true (current state changes);
- `engram_archive`: revision-gated withdrawal revision, write with destructiveHint=true; never hard delete.
Write/update/archive require trusted server-injected `engram.write` scope, owner mirage-owner, namespace project, distinct genuine operator write approval; reject existing read-only OAuth token and client-supplied owner/scope. Test of offline catalogue/authorization PASSED (including invalid scopes, owner, namespace, approval and malformed inputs). DO NOT advertise these as live-functional or installed. Full write API wiring and persistence tests, private OAuth scope/consent/migration, current registry, token revocation and real SQLite transaction/concurrency tests remain to be implemented. Do not silently upgrade a read-only OAuth token or retrofit write rights into a caller-provided payload.

## Next explicit user-controlled step
Operator separately approves one 0.9.35->0.9.36 native production install of EXACT SHA-confirmed package through existing KiCom admin; risk classification and on-host verifier must confirm, then live GENOME_STATUS/health. Reload ChatGPT plugin tool definitions and retry independent READ test in new chat. If platform blocks AGAIN, disclose platform-access issue (do not bypass by mislabeling write tools); diagnose supported plugin policy path. In parallel integrate offline operator drafts in a separate operator development line with true OAuth write permissions and positive/negative real SQLite/HTTP tests. No repeated installation of older 0.9.33/34/35 candidates.

Private actual memories, bearer tokens, cookies, passkeys or full OAuth browser URLs MUST NOT go into GitHub/Slack logs.
