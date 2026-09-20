# KiCom Engram — DEV-15 exclusive same-parent concurrent rebuild gate

Date: 2026-09-20. Continuation of DEV-14. Live KiCom remains canonical 0.9.26; user requested autonomous isolated DEV until operator action required. Public GitHub contains only code, technical documentation, synthetic test fixtures, CI evidence; no actual engrams, chats, private backups/DB bytes, credentials, session/OTP, operator anchors, private configuration or retrieval results.

## Executable changes

- `KiComEngramMirrorSet::rebuildNewGeneration()` now obtains a nonblocking exclusive OS `flock` on its existing private, pinned parent MANIFEST file before inspecting it and before any creation of a child snapshot. The parent filename is validated and the file must remain private/regular. A second process attempting to repair the SAME parent is denied immediately without writing a child snapshot or manifest. The lease is held until manifest publication, released in `finally` or by the Linux kernel after actual SIGKILL; no new lock file is generated and the orphan inventory remains accurate.
- New `test-engram-concurrency.php` spawns an actual synthetic PHP subprocess, waits until it is paused while holding the parent lock, attempts a simultaneous rebuild and verifies denial/no files altered, kills lock holder with Linux SIGKILL, checks kernel-released lock, retries explicitly, independently verifies the new generation and restores the synthetic history.
- This is advisory local-filesystem single-parent mutual exclusion only, not across independent hosts or uncooperative same-UID processes; it does not choose a latest head, automatically endorse the child digest, cryptographically authenticate a privileged operator or serialize repair across distinct parent manifests.

## Pinned CI evidence

Tested executable SHA **`9e9dd75b778348863b7332ba827e87a95b34c502`**.
GitHub Actions `KiCom private Engram DEV` run **35507106235**, job **106068676343**, conclusion **success**. Original protected KiCom 0.9.26-R3 package/source manifest preflight PASSED; PHP syntax and complete suite PASSED.
Markers: main 49 + path 23 + DEV route 23 + endpoint 14 + integrity 16 + ingestion 29 + mirror 46 + exception-fault 45 + real SIGKILL 44 + concurrency 8 = **297 synthetic tests**.
Evidence: https://github.com/cschymura/kicom-update-mirror/actions/runs/35507106235

## Remaining key gates

- No actual operator-protected external manifest anchor signing service/trust root. Current 64-char digest arguments are trusted solely by CONTRACT; passing a digest read from the same compromised storage defeats integrity assurance. Next safe internal DEV: design and test a read-only independent signed anchor verifier with synthetic keys, strict schema, refusal of forged/expired/replayed contexts; do not store private signing keys in GitHub or on the unverified host. Actual operator public-key provisioning is a separate user-controlled action.
- No tested full retention/erasure of personal engrams across live SQLite revisions, WAL/SHM/temp, every immutable mirror generation, off-host copies and independent anchors. No valid production consent issuer, authenticated chat retrieval integration, cross-host physical RAID, host PHP UID/open_basedir/default-host/alias/isolation proof, real private memory import, real installation or deployment.
- The explicit independent digest of NEW generation must be retained by trusted operator infrastructure before any automatic use. An unknown orphan or simply newest file must NEVER be promoted to current memory. Existing production 0.9.26, kernel, Auth and protected update boundaries untouched; engram-private remains outside deployment targets.

This checkpoint documentation commit is not new executable verification. Resume from pinned SHA/run above.
