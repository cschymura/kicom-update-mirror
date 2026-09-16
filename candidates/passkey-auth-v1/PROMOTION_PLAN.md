# KiCom Passkey Auth v1 — promotion plan

This candidate is an **authentication trust-boundary change** and therefore RED. It must never be copied directly into the productive tree. Promotion is only through KiCom's isolated server-side build, existing verifier/genome pipeline and exact transaction-bound human approval.

## Release ordering

Default safe sequence: install and stabilize Interrupt/Auth Resilience v5 as 0.9.16/g17, then rebase this passkey candidate onto that trusted source and target 0.9.17/g18.

If the passkey bridge is intentionally prioritized first because authentication transport itself is blocking routine maintenance, it may instead become 0.9.16/g17 **only after** the resilience candidate is explicitly retargeted to 0.9.17/g18. Never let two candidates claim the same version/generation.

## Live baseline binding

Current trusted baseline is KiCom 0.9.15 / `kicom-0.9.15-g16`, healthy/trusted/LKG, drift=0, unknown=0. Before build creation, verify the exact source hashes from `LIVE_INTEGRATION_0.9.15.json`. A mismatch means STOP and rebase; do not patch by approximate anchors.

## Runtime design

The browser/passkey path may create only a normal bounded-autonomy session. The passkey verifier does not gain authority to perform RED, production or kernel actions. FreeOTP remains the critical trust-boundary mechanism in v1 and also authorizes first passkey enrollment with a fresh current-counter code.

Chat-side handoff uses an ephemeral Curve25519 keypair. `AUTH_CHALLENGE_CREATE` stores only the caller public key plus a short-lived WebAuthn challenge. After Face ID/Touch ID/device verification, KiCom mints the existing bounded session, seals the session payload with `sodium_crypto_box_seal()`, and exposes ciphertext only through status polling. The ephemeral private key never leaves the chat-side client.

## Required live integration points

- `living.php`: add a narrowly scoped internal normal-session mint helper that is callable only after locally verified passkey authentication. It must produce the same scope/TTL/token semantics as normal `AUTH_SESSION_OPEN` without consuming TOTP. Do not touch critical approval execution semantics or workspace proposal batch behavior.
- `index.php`: add public challenge-create/status and read-only WebAuthn options routes. These routes must be rate limited, challenge-bound and non-mutating with respect to KiCom authority until browser verification succeeds.
- same-origin browser submit handler (`api.php` or dedicated allowlisted file): assertion submit and enrollment submit only. Exact RP ID `kicom.rurtalbahn.info`, exact origin `https://kicom.rurtalbahn.info`, `userVerification=required`, ES256/P-256 only in v1.
- passkey module: `PasskeyBridge.php` plus `RuntimeAdapter.php`; no shell, SQL or arbitrary remote-fetch primitives.
- UI: readable `/auth/passkey.php` approval/enrollment page using `browser.js`; no token or TOTP seed in URL/localStorage.
- genome/manifest: include every new executable/control file with exact SHA-256; component-set change makes the package RED by design.

## Mandatory invariants

1. FreeOTP fallback remains unchanged.
2. Critical RED/production/kernel execution remains fresh-current-counter, transaction-bound FreeOTP.
3. Existing `workspace_proposal_batch` remains intact and FreeOTP-gated.
4. Rolling normal-session token semantics remain unchanged after handoff.
5. Passkey challenge lifetime is short and single-purpose; replay or expired challenge fails closed.
6. RP ID/origin are compile/config constants, never request-controlled.
7. User verification bit is required; user-presence-only assertions are rejected.
8. Credential signature counter rollback is rejected when both old and new counters are non-zero.
9. Passkey private key never leaves authenticator/passkey provider.
10. Session credentials are never returned in plaintext by the public challenge-status route.
11. Initial enrollment requires the existing current-counter TOTP verifier with no session-open grace.
12. No new arbitrary filesystem, shell, SQL or remote-fetch capability.

## Isolated validation matrix

- PHP syntax and required `openssl` + `sodium` extensions.
- Synthetic ES256 registration and assertion.
- Negative origin, RP hash, UV flag, credential-id, signature and sign-counter cases.
- Enrollment denied without successful existing-TOTP gate.
- No session minted before assertion verification.
- Exactly one bounded session minted after a valid assertion.
- Sealed handoff decrypts only with the ephemeral caller key and status never contains plaintext credentials.
- Existing FreeOTP session-open regression remains green.
- Existing transaction-bound RED/production/kernel approval tests remain green.
- Existing workspace proposal batch regression remains green.
- Verifier/genome/manifest checks pass.
- Install healthcheck passes; drift=0 and unknown=0.
- Rollback returns to the exact previous trusted genome and healthcheck.

## Human activation

After all isolated tests pass, stage the exact package as RED and present its bound version, package SHA-256, genome SHA-256, changed paths and risk reasons. Installation requires a new fresh current-counter FreeOTP code for `AUTH_APPROVAL_EXECUTE`. The first passkey enrollment then requires one separate fresh current-counter FreeOTP code. No code supplied for one step may be reused for the other.
