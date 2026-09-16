# KiCom Passkey Auth Bridge v1 (isolated prototype)

This directory contains a **non-active prototype** for adding a second KiCom authentication path based on WebAuthn/passkeys. It is intentionally not wired into the productive KiCom control plane yet.

## Security model

The prototype does **not** weaken or bypass FreeOTP rate limiting. Instead it adds a separate, phishing-resistant path for opening a normal bounded-autonomy session:

1. The chat-side client generates an ephemeral Curve25519 keypair.
2. KiCom creates a short-lived challenge bound to the client's public key.
3. The human approves that challenge in the KiCom browser UI with a registered passkey and user verification (Face ID / Touch ID / device PIN).
4. KiCom creates the normal bounded autonomy session only after successful WebAuthn verification.
5. The returned `session_id` and rolling session `token` are sealed with `sodium_crypto_box_seal()` to the chat-side public key.
6. A public status endpoint may expose only the ciphertext; only the chat-side private key can recover the session credentials.

This avoids sending a TOTP seed, TOTP code, passkey private key, session token, or long-lived exchange secret through the web-fetch transport.

## Scope of v1

- Passkey can open a **normal autonomy session**.
- Existing FreeOTP remains available as fallback.
- RED, production, recovery-kernel and other critical actions remain transaction-bound and require the existing fresh-current-counter FreeOTP path in v1.
- Passkey enrollment itself must be authorized once by an existing trusted control plane; the intended first implementation uses a fresh current-counter FreeOTP code.
- No shell, SQL, arbitrary filesystem or arbitrary remote-fetch authority is added.

## Files

- `PasskeyBridge.php` — composer-free WebAuthn ES256 verification, challenge storage and encrypted session handoff.
- `selftest.php` — deterministic synthetic registration + assertion test and libsodium handoff roundtrip.
- `INTEGRATION.md` — proposed KiCom endpoint contract and staged activation plan.
- `.github/workflows/passkey-auth-prototype.yml` — syntax, crypto-extension, static-boundary and synthetic end-to-end checks.

## Required PHP features

- PHP 8.x
- OpenSSL extension
- Sodium extension (`sodium_crypto_box_seal`)

The prototype deliberately supports WebAuthn **ES256 / P-256** only and requests `attestation: none`. This keeps the verifier small and auditable.

## Current status

The module has been locally syntax-checked and the synthetic end-to-end self-test passes. It has not been merged into KiCom runtime and must not be treated as a production authentication authority until it has been rebased onto the canonical live KiCom source, packaged, verified and explicitly RED-approved.
