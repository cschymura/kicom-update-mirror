# KiCom reproduction — isolated localhost daughter R3 runtime candidate

Date: 2026-09-19 · repo cschymura/kicom-update-mirror · branch work/kicom-0.9.27-pam
Previous checkpoint: development/0.9.27/reproduction/CHECKPOINT-2026-09-19-BLUEPRINT-GENESIS.md

## Canonical production status read before this work

BOOTSTRAP and PROJECT_STATE, ARCHITECTURE, PROTOCOL, DECISIONS, CHANGELOG, NEXT read. Live GENOME_STATUS reports 0.9.26/g25r3 healthy/trusted/LKG OK, drift_count=unknown_count=0. SQLITE_STATUS primary/WAL/quick_check=ok; UPDATE_STATUS no pending version. Canonical PROJECT_STATE and NEXT still have stale g25r2/old priorities. No production files, original communication routes, live credentials, SQLite, recovery, genome or status were modified.

## Actually implemented, in isolated GitHub DEV only

development/0.9.27/reproduction/test-daughter-runtime.sh: an isolated Linux runner creates a separate localhost 127.0.0.1:18734 native R3 PHP server under the previously created kicom_daughter_ci OS principal, after separately verifying original immutable R3 source MANIFEST.sha256 and the original R3 package SHA in the workflow. A root-owned release-code tree is copied from the verified source snapshot; the default code/tree cannot be overwritten by the daughter. The copied release var/ directory is removed BEFORE first start and recreated as a separate daughter-owned 0700 private runtime directory; stage/ is likewise separate. No mother var/, production memory, SQLite database, config.php, sessions, keys, archives or credentials are copied. The synthetic daughter Ed25519 private key resides under its own independent UID/private directory.

The daughter starts the original R3 index.php on localhost and gives a real KCL/1 OK ping and KCL/1 OK hello response. The runtime initializes its OWN release-seeded private project_memory directory on first request, inaccessible to the mother. Parent/child private Ed25519 key files remain mutually unreadable after the child server has initialized; mother-state baseline bytes are unchanged; the daughter cannot alter root-owned verified release code. No real Slack, Mail, Opera, GitHub, external Update, Passkey login or outbound actions were exercised; no real external credentials were provisioned.

This is a **running native R3 daughter runtime candidate in isolated localhost staging**, not a full born/activated KiCom clone: the original R3 release can seed historical canonical memory/genome templates, which MUST NOT be presented as an independently issued child identity. The child-specific Ed25519 key from the preceding test is not yet bound into the native KiCom Genome/Recovery/Auth model; local HTTP is not a verified HTTPS proxy or non-bypassable network membrane. Dedicated daughter recovery/rollback and full communication parity remain outstanding.

## CI status and correction history

Added bash syntax and execution to the existing .github/workflows/test-0927-membrane.yml after the existing original R3 source/manifests, transport, Linux principals, synthetic positive loopback, passive observer, signing and identity checks. The latest GitHub Actions RUN_ID=35458854390, JOB_ID=105938937976, tested checkout SHA=b3a63d2917411eddfbee51b3c3a9dc03d5554c73, conclusion=success. Previous 290 isolated checks plus KICOM_REPRODUCTION_RUNTIME_TESTS_PASSED=8 = **298 / 298 passing isolated tests**. See https://github.com/cschymura/kicom-update-mirror/actions/runs/35458854390 .

The successful run proves two functioning local KCL read-only requests and Linux owner isolation, not a successfully cloned or autonomously reproducible whole KiCom instance. The R3-release archive remains hash-pinned, original 0.9.26 production stays unchanged, and no external recipient was contacted.

## Required next steps

1. Implement **independent daughter identity binding** in an isolated derivative release or protected genesis adapter; ensure the native KCL/Genome status cannot falsely present the mother's genome identifier or historical seed memory as the daughter's distinct cryptographic/operational identity. Any child-specific state must be new and the trusted hosting root must verify binding to the exact original verified code and child key without writing to protected mother assets. Until then, treat the child as a derivative runtime candidate and do not promote.
2. Give the child its own independent protected recovery/rollback/root and inspect SQLite schema/quick_check with separate fresh state, no database copy. Test failed bootstrap, restart, rollback and preservation of both identities.
3. Verify existing positive and negative GET/POST/binary transport parity, authenticated DEV/Passkey, Slack inbound/outbound, Mail IMAP/SMTP, Opera/browser, Chat/Update, rate/timeout and token rotation on synthetic test targets and separately authorized genuine test destinations before claiming complete communication.
4. Independently verify host/PHP-worker UID/GID, protected policy/recovery identity and non-bypassable egress. GitHub OS isolation does not prove production ALL-INKL allows the needed membrane. No production daughter provisioning, external account, credentials, self-propagation or network changes without existing protected operator approval.
5. Do not request FreeOTP for internal GitHub-only development. Keep existing release verifier, Genome checks, backed-up LKG and original protected action boundary intact. Commit a new checkpoint and check complete fresh GitHub CI after any executable code change.
