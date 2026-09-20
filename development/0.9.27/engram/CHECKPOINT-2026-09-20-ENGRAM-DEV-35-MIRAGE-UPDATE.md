# Mirage Engram DEV-35 — native KiCom 0.9.28 admin scaffold update built and verified

Date: 2026-09-20. Branch: work/kicom-0.9.27-pam, predecessor checkpoint commit 02884310870a5eae8f67ba0b8ef7c6721629ce67. User reports actual KiCom 0.9.27 installed; the real server version and host isolation have NOT been independently verified.

## Actual completed work
- Built a **complete native KiCom 0.9.28 release archive** starting from the original byte-verified 0.9.27 ZIP (SHA-256 `38e9fce1a2d3f31fda45b04bfd29c13b38a05d0fb7033b1f9916a68461185d26`).
- Artifact: `KiCom-0.9.28-Mirage-Engram-Setup-DISABLED.zip`; SHA-256 `53d251c2d522fbdb6f4725879ffe5f8223e5b255be35ea3a2480f470ab48e6ac`; 219846 bytes; 52 files. **Exists as an artifact in the current ChatGPT conversation only; do not claim a GitHub binary release or update-feed publication.**
- Parent genome `kicom-0.9.27-g26-engram-integrated-disabled`; next genome `kicom-0.9.28-g27-mirage-engram-setup-disabled`; generation 27. The update adds only `modules/engram/KiComEngramSetupWizard.php`; modifies `admin.php`, `lib.php` (version metadata only), `genome/modules.json`, `genome/genome.json`, and manifest. `api.php`, `guardian.php`, `recovery.php`, `index.php`, `living.php`, and all security htaccess files are byte-identical to the original 0.9.27 package.
- The native KiCom 0.9.27 package verifier (run against a disposable original-install fixture, not the live server) ACCEPTED the exact 0.9.28 ZIP; its risk classifier returned **RED (human admin approval required)**, kernel change FALSE, parent/generation and changed-file inventory matched expectations.
- All 35 PHP files in the archive passed syntax check; 19/19 synthetic existing DEV-34 wizard tests passed. The native verifier rejected each of three bad archives: tampered PHP, missing new module, and wrong genome parent despite recomputed manifest.
- Reproducible local source paths from this conversation: `/mnt/data/Mirage-Engram-0.9.28/build_release.py`, `verify_release.py`, `test_native_negative.py`, `INSTALLATION-UND-TEST.md`, `TESTBERICHT.txt`. They are **not yet in GitHub**. Source inputs: prior DEV-34 `KiComEngramSetupWizard.php` and patched `admin.php`, generated locally; original release 0.9.27. No private configuration or customer data entered the archive.

## Behavior after the human-approved native update
- New first-party admin link to `admin.php?engram_setup=1`. Authenticated GET runs a read-only preflight.
- A separately confirmed, CSRF-bound HTTPS POST can create only missing private 0700 subdirectories, empty 0600 owner registry and a 0600 `engram-host.json` with all operational flags FALSE and unreviewed trust-source values. Existing files are not overwritten.
- **Engram personal memory is STILL DISABLED after updating and even after scaffold creation.** There is NO active private owner/passkey mapping, live SQLite creation, real authenticated ChatGPT MCP connection, or proven independent new-chat recall. No real server or production update was performed by the developer.
- User must upload the exact ZIP via the existing KiCom Admin `KiCom Update-ZIP` / `Update prüfen`, verify source `0.9.27` and target `0.9.28` and approve the RED update by entering `0.9.28` precisely. Do not upload the DEV-34 source ZIP, sideload PHP into webroot or bypass native install/rollback gates.

## Next task
Once the operator confirms 0.9.28 installed, read the protected setup-page status without exposing paths or credentials; only then request the separate scaffold preparation action. Real host/vhost/UID/private-backup checks and a fresh passkey-to-owner provisioning remain required before switching runtime flags on; run a true synthetic live-server write/read across independent authorized KiCom sessions, then implement/attach and test the real ChatGPT MCP connector. Local PHP lacks `pdo_sqlite`: native package validation and synthetic scaffold tests do NOT establish a real SQLite roundtrip.

No public GitHub release or feed was modified and no live server or private user data was accessed in this development step.
