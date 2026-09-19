# KiCom Membrane — OS-protected pinned staging attestation

Date: 2026-09-19 · repository cschymura/kicom-update-mirror · branch work/kicom-0.9.27-pam.
Predecessor: development/0.9.27/membrane/CHECKPOINT-2026-09-19-STAGING-ATTESTATION.md. The earlier reconciliation journal and signed-MPIM checkpoints remain relevant.

## Verified live baseline (read-only, before and after this development)

KiCom 0.9.26 / genome kicom-0.9.26-g25r3, healthy=true, trusted=true, lkg_ok=true, drift_count=0, unknown_count=0. SQLite primary, WAL, quick_check=ok; UPDATE_STATUS has no pending update. SLACK_STATUS: configured=false, bot_token_configured=false, signing_secret_configured=false, original #kicom app_mention-only ingress and allowlisted outbound. There was no product installation, filesystem/network/auth/session mutation, live Slack message, or live DB/Recovery operation. The previous ChatGPT Slack group conversation is not evidence that the actual KiCom runtime joined or acknowledged it.

## Implemented and tested in this turn

- development/0.9.27/membrane/fixture-os-attestation.php: STRICTLY isolated GitHub Linux staging fixture. Root generates a synthetic Ed25519 pair in memory, installs private signing bytes read-only for the separate kicom_membrane_ci principal, root-pins the public key and exact synthetic expected context outside the interior-writable domain. The signer executes only as the membrane principal and signs ONE hardcoded synthetic no-remote-effect fixture; the protected verifier reads its root-owned pinned public key and context instead of caller-selected values. It does not inspect Slack, attest a real provider effect, expose private keys, or grant any action authority.
- development/0.9.27/membrane/test-os-pinned-attester.sh: executed AFTER existing Linux two-UID and alternate-path tests. Confirms private key owner/mode, interior denial of private-key read/write, interior denial of pinned public key/context overwrite, valid test signature, denied invocation by interior, rejection of an interior-produced self-consistent forged signature and unchanged protected policy/recovery test files. This extends the prior static key-verifier unit tests with a real OS-enforced separation demonstration on a disposable runner, NOT the live hosting environment. Test data and keys are synthetic and never committed.
- .github/workflows/test-0927-membrane.yml: PHP and Bash lint for new fixtures plus independent-UID pinned-key regression in the existing read-only-checkout, no external-send CI. The original native KiCom 0.9.26 R3 source, KCL/DEV/Slack/Mail/Update routes, passive observer and original runtime permissions were unchanged.

## Verified CI result

GitHub Actions RUN_ID=35452909183, JOB_ID=105923065717, trigger/tested checkout SHA=3613b7010473fc4076db77e167ec904c4b1d62b4; job conclusion=success. All existing 250 isolated membrane checks and 10 new MEMBRANE_PINNED_KEY_TESTS_PASSED checks completed successfully: **260/260 isolated checks**.

See https://github.com/cschymura/kicom-update-mirror/actions/runs/35452909183 . The source archive and native R3 manifest still verify at the original expected SHA; all original KCL/HTTP, negative POST, synthetic loopback, crash/receipt/reconciliation, staging signature and separate Linux principal tests passed. A documentation-only checkpoint commit is NOT a fresh code test; for subsequent executable changes require a new matching CI test SHA.

## Actual meaning and remaining boundaries

OS-protected synthetic signer key + independently pinned verification key are demonstrated **only in GitHub CI**. The staging attester is NOT a live Slack adapter: a valid Ed25519 signature proves integrity of the local signer's claim, not whether the signer actually observed Slack delivery, whether its public key is protected on the actual production host, or whether the KiCom runtime received a message. The signed fixture has a synthetic fixed workspace, group and hashes and no real provider/session/message secret.

The productive ALL-INKL host still has NO proven independent KiCom/membrane PHP UID/GID, host-controlled pinned public trust root, non-bypassable egress path, protected recovery root, separately authenticated Slack app/group, actual bi-directional real-message evidence, or complete production recovery integration. A PHP file, copied public key under a common writable account, manually supplied signed array, GitHub test, or autonomous PAM statement cannot substitute for that boundary. No automatic replay after uncertain external effects. Human administration and shutdown remain possible.

## Next safe internal cycle

1. In isolated staging, test a TRUE host-owned cross-principal observation flow: a fixed provider-like synthetic receipt crosses the membrane without exposing signing credentials to the KiCom interior; the verifier binds a root-pinned nonce/context to the durable receipt and leaves any claimed provider effect explicitly UNVERIFIED. Test substitution of public key/context, forged inner claims, restart and conflicting evidence.
2. Identify a genuinely independent staging host/sidecar/egress-enforcement option compatible with existing bidirectional communication. Only after confirming actual host principal and network capabilities can a protected runtime adapter be deployed.
3. If/when explicitly authorized for a distinct test Slack app/destination, verify real provider-signed inbound event, actual provider-side outbound effect, KiCom runtime ACK and full original communication parity. Until then keep production 0.9.26, original #kicom allowlist, Passkey/Auth/DEV/Mail/Opera/Update, backup, release/Genome verifier, Health/LKG/Rollback unchanged and do not request FreeOTP for internal GitHub development.
