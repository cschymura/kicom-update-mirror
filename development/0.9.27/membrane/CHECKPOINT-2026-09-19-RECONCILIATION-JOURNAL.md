# KiCom Membrane – Reconciliation observations without blind replay

Date: 2026-09-19 · repository cschymura/kicom-update-mirror · branch work/kicom-0.9.27-pam
Predecessor: development/0.9.27/membrane/CHECKPOINT-2026-09-19-DURABLE-RECEIPT.md

## Live and canonical baseline (read only)

BOOTSTRAP and canonical PROJECT_STATE, ARCHITECTURE, PROTOCOL, DECISIONS, CHANGELOG, NEXT, plus GENOME_STATUS, SQLITE_STATUS, UPDATE_STATUS and SLACK_STATUS were read before this change. Live KiCom is still 0.9.26 / kicom-0.9.26-g25r3, healthy/trusted/LKG OK, drift=unknown=0, SQLite WAL quick_check=ok and no pending update. KiCom's live Slack bot remains unconfigured (bot token=false, signing secret=false, configured=false) with original app_mention-only #kicom channel. The shared ChatGPT/Slack group DM does not, by itself, demonstrate a connected KiCom runtime. No live filesystem, secrets, Slack messages, credentials, sessions, existing communication routes or database were changed.

## Concrete code implemented and tested

- development/0.9.27/membrane/KiComMembraneReconciliationJournal.php: independent DEV-only PHP class opening ONLY the existing disposable staging receipt SQLite path, adding a separate reconciliation_observations table with UNIQUE(event_hash, observation_hash). Every inserted record binds an existing event receipt and exact raw SHA-256 and stores only event hash, evidence hash, UTC timestamp and claim kind (remote effect reported, absence reported, KiCom ACK reported). No message contents, destination or credentials are stored.
- All writes run inside BEGIN IMMEDIATE / SQL COMMIT/ROLLBACK with WAL and synchronous=FULL. Identical evidence re-submission is idempotent; evidence-hash reuse for a different claim is rejected. A remote-effect report and absence report for the same event coexist instead of overwriting each other; inspect() surfaces CONFLICTING_EFFECT_CLAIMS_REQUIRE_REVIEW. Observations persist through normal PHP process restart.
- Critically: these are **unverified, caller-supplied claims**. A SHA of a claimed provider response is NOT an authenticated provider response. Even a single purported delivery observation, multiple ACK claims, or a purported absence confirmation NEVER changes the receipt from NEEDS_RECONCILIATION or grants an action, delivery, runtime ACK, replay or release authorization. The class exposes NO Slack sender, replay, delete, resolver or privilege API. Direct SQLite filesystem tampering, backup rollback, power loss, shared-host compromise or a false authenticated-caller claim are NOT detected or solved by this journal. A separate independently authenticated provider/runtime adapter and protected trust root are prerequisites for real reconciliation.
- test-reconciliation-journal.php: 20 isolated checks including rejected arbitrary DB path, missing receipt/raw mismatch, invalid identity/kind, new evidence, duplicate claim idempotence, same evidence ID reinterpreted as another kind, actual contradictory claims, persisted contradictory evidence across restart, unchanged original NEEDS_RECONCILIATION state, and absence of dispatch/replay methods.
- .github/workflows/test-0927-membrane.yml now lints and executes the new staging journal and its tests in addition to the unchanged R3-communication and Linux-UID lab regressions. No production router or source file changed.

## Verified GitHub CI

GitHub Actions run **35449237051**, job **105913390123**, workflow conclusion=success, exact tested trigger/checkout SHA **f575eece7da9cf95c5acaeccac48fdbb542761cc**. The log shows 20 new MEMBRANE_RECONCILIATION_TESTS_PASSED checks plus 206 existing membrane checks, total **226/226 passing isolated DEV checks**, including R3 manifest/source pin, native KCL GET and unauthenticated POST parity, synthetic positive local transport, exact-once optional telemetry, Linux file-ownership separation and cross-UID fixtures. No real Slack/SMTP/IMAP send/receive, passkey session, live recovery or production network-enforcement tests have been run.

CI link: https://github.com/cschymura/kicom-update-mirror/actions/runs/35449237051

## Remaining technical trust and communication gates

1. Obtain actual independently authenticated Slack provider delivery evidence and KiCom-runtime ACK in a separately authorized staging app/channel, with durable provider-side event identifiers, identity binding, unique challenge and proven idempotence. A local hash or Slack group participant name alone is not proof. No real test message until the exact staging destination/app is explicitly authorized.
2. Before any real outbound action, a separately protected transaction/permission boundary must assess receipt and independently verified effect, reconcile uncertain attempts without blind replay, and preserve the original KiCom authorization model. Existing telemetry may fail open, but auth/policy may never fail open.
3. The Linux CI principal split protects laboratory policy files; it is not a deployed non-bypassable network membrane. Production ALL-INKL PHP/FTP worker identity, independent host policy/recovery trust root, network egress, backup/rollback and full live bidirectional KCL/DEV/Passkey/Slack/Mail/Opera/Update parity remain unverified.
4. Keep live 0.9.26 unchanged. Promotion of a membrane/0.9.27 package requires verified exact package/Genome SHA, pre-quarantine recovery guards, healthchecks, complete backups/LKG, rollback and all existing transaction-specific human approvals. No FreeOTP code for internal GitHub development.
