# KiCom Engram — Mirage DEV-67 (2026-09-22) — Human-first FREIGABE + safe 409 diagnostics

## Authority and live state
Christoph requested that KiCom confirmation UX be short and human-friendly. He authorized development and testing; **no separate authorization was given to install 0.9.35**. A broad self-preservation instruction is not grounds to bypass authentication, CSRF, Passkey, or per-action live consent. Read-only GENOME_STATUS after local build reports actual live **0.9.34**, healthy=true, drift=0, unknown=0. DEV-67 has not been installed, and the inactive databases have not been confirmed created.

## Exact package (conversation artifact, not GitHub release)
`KiCom-0.9.35-Mirage-Engram-FREIGABE-VOLLSTAENDIG.zip`
SHA256 `6e111846dac342ebf3b2e05df15b17266eff26016c4d48bf61375b951a27eb21`, 82 entries. The parent is the exact 0.9.34 package SHA256 `14550466e751ec5f8c2dcc7b017fa141c53a8be637774f35e2f063f1f6576d6b`. New genome id `kicom-0.9.35-g34-mirage-human-first-admin-confirmation`, parent `kicom-0.9.34-g33-mirage-engram-admin-form-origin-compat`, generation 34, same kernel revision.

Only five ZIP entries differ from parent:
`MANIFEST.sha256`, `genome/genome.json`, `lib.php`, `modules/engram/KiComEngramAdminDbProvisionHttp.php`, `modules/engram/KiComEngramInactiveDbProvisioner.php`. Original `admin.php`, `api.php`, `index.php`, `guardian.php`, `recovery.php`, Passkey, origin guard, OAuth/MCP, activation and synthetic sample modules are byte-preserved.

## UX/security delta
The inactive database form now has one button **FREIGABE – Datenbanken vorbereiten**, with HTML `name="confirmation" value="FREIGABE"`; no manually typed long phrase. The provisioning operation requires the **exact new confirmation value** `FREIGABE`, its original admin session and CSRF, trusted root and original private inactive host state; the old long phrase is no longer accepted. A 409 occurring *after* original admin/session/CSRF/origin gates now includes a readable, allowlisted reason code, not exception text, host paths, owner records, database content, tokens or secrets. Unauthenticated/CSRF/cross-origin requests retain empty-denial responses. The previously confirmed Safari Origin:null compatibility remains narrow same-origin/same-navigation only.

## Re-run local evidence
- 14/14 real PHP form tests passed: proper GET, button, valid signed-in Safari-style origin/CSRF request reaches private DB gate, safe 409 after gate, old phrase denied, CSRF/session/origin/method/extra-fields denied, no negative-test DB creation.
- In the local PHP container pdo_sqlite is missing. The successful-path fixture **properly returns a visible SQLlTE_UNAVAILABLE 409**, not a claim that databases were actually prepared. Actual server-side 409 root cause will be known only after live deployment and authenticated use of the new UI.
- All 61 PHP file lints passed; all 10 bundled JS syntax checks passed.
- Real ZIP CRC, safe unique 82 entries, manifest SHA-256 81 entries, genome component SHA-256 74 components, exact parent lineage and five-path delta passed. Kernel, recovery, guardian, OAuth, MCP, activation and private data paths untouched.
- Exact parent KiCom 0.9.34 self-update verifier logic accepted 0.9.35, risk **YELLOW**, kernel_update=false, and rejected the deliberately altered candidate with `MANIFEST_HASH_MISMATCH`. The local PHP lacks native ZipArchive; a test-only adapter supplied byte-verified ZIP entries to the unchanged original verifier. Therefore a native on-host ZIP verifier and real SQLite success remain separate gates.

## Next bounded live step
Christoph can explicitly authorize ONE native KiCom admin installation of the exact SHA-confirmed 0.9.35 package (not a manual PHP replacement); verify on-host risk, version, healthy, drift, and rollback after installation. Then click **FREIGABE – Datenbanken vorbereiten** once as the logged-in operator and inspect its actual response. If 409, capture ONLY the displayed allowlisted error code, not cookies/CSRF or private config. Only after inactive schemas are verified can independent host-policy Passkey approval, separate Engram read activation, synthetic test record, OAuth connector and independent ChatGPT recall be authorized and tested.

Original exact source delta: `development/0.9.27/engram/dev67/KiCom-0.9.35-HUMAN-FIRST.patch.txt` on this branch. ZIP itself exists only as a conversation artifact; do not infer a sandbox path in another runtime without verification. No live installation or genuine cross-chat recall claimed.
