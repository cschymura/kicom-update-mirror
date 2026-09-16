# KiCom Passkey Auth Bridge v1 — integration contract

## Goal

Add a second human authentication path that removes routine dependence on six-digit FreeOTP codes while preserving KiCom's existing trust boundaries. The new path is for normal bounded-autonomy session creation only in v1.

## Endpoint contract

### `AUTH_CHALLENGE_CREATE`

Unauthenticated, rate-limited read/control endpoint.

Input:
- `client_pub`: base64url Curve25519 public key, exactly 32 bytes after decoding.

Output:
- `challenge_id`: 128-bit random identifier.
- `approve_url`: browser URL containing only the challenge id.
- `expires_in`: 120 seconds.

The server stores the client's public key plus a separate WebAuthn challenge. No runtime session exists yet.

### Browser approval page

Recommended path: `/auth/passkey.php?challenge=<challenge_id>`.

The page fetches assertion options, calls `navigator.credentials.get()`, and posts the returned assertion to KiCom. Requirements:
- RP ID: `kicom.rurtalbahn.info`
- origin: `https://kicom.rurtalbahn.info`
- `userVerification: required`
- enrolled credential allowlist only
- challenge single-purpose and short-lived

After successful assertion verification KiCom creates a normal bounded autonomy session using an internal function that does not consume TOTP. It immediately seals the JSON session payload to `client_pub` using `sodium_crypto_box_seal()`. Only ciphertext is persisted in the bridge challenge.

### `AUTH_CHALLENGE_STATUS`

Input:
- `challenge_id`

Output states:
- `pending`
- `verified`
- `approved`
- `expired`

When `approved`, return:
- `ciphertext`: base64url sealed-box ciphertext
- `payload_sha256`: audit checksum of the plaintext before sealing

No session credential is returned in plaintext.

## Enrollment

Initial enrollment must remain human-gated. Proposed flow:

1. User opens the KiCom admin/passkey page.
2. User enters one **fresh current-counter FreeOTP** code.
3. Existing KiCom TOTP verifier consumes it for purpose `passkey_enrollment` with no past-counter grace.
4. KiCom issues a short-lived enrollment ticket.
5. Browser calls `navigator.credentials.create()` using ES256/P-256, `userVerification: required`, `attestation: none`.
6. KiCom verifies origin, RP hash, UP, UV, attested credential data and COSE key and stores only the credential id, P-256 public coordinates and sign counter.

Passkey private material remains inside the platform authenticator / passkey provider.

## Runtime integration

The canonical KiCom live source must be read before applying patches. Do not patch the older mirror source directly into production.

Expected integration points after rebasing onto the live source:

- `lib.php`
  - include or inline bridge module
  - add internal `kicomAutonomySessionOpenVerifiedHuman()` that mints the same bounded session as `kicomAutonomySessionOpen()` but requires a verified passkey result from the local control plane rather than a TOTP code
  - add challenge/enrollment storage under `var/auth/passkey/`
- `index.php`
  - `AUTH_CHALLENGE_CREATE`
  - `AUTH_CHALLENGE_STATUS`
  - assertion-options read endpoint
  - status/reporting only; no critical-action expansion
- `api.php` or a dedicated same-origin passkey endpoint
  - assertion submit
  - enrollment assertion submit
- browser UI
  - small, readable passkey approval/enrollment page
- genome / manifest
  - update hashes and component set if a new executable file is added

## Security invariants

- No TOTP-rate-limit bypass.
- No session token is placed in an approval URL.
- No passkey private key leaves the authenticator.
- No plaintext session token is exposed by `AUTH_CHALLENGE_STATUS`.
- Challenge IDs are random, one-purpose, short-lived and expire closed.
- RP ID and origin are exact, not configurable from request input.
- User verification is mandatory.
- Signature counter rollback is rejected when both old and new counters are non-zero.
- Enrollment is never unauthenticated.
- v1 does not authorize RED, production or recovery-kernel actions.
- FreeOTP fallback remains unchanged.
- Existing bounded-autonomy scope remains unchanged.

## Release / activation plan

1. Rebase the prototype onto the canonical live KiCom source.
2. Add isolated self-tests and browser smoke tests.
3. Build a new candidate genome with exact manifest hashes.
4. Verify healthy/trusted/LKG and zero drift in an isolated environment.
5. Stage as a RED update because the authentication trust boundary changes.
6. Human explicitly approves the exact transaction with a fresh current-counter FreeOTP code.
7. After install, enroll the first passkey using one fresh current-counter FreeOTP code.
8. Test normal passkey session opening and encrypted handoff.
9. Keep critical actions on transaction-bound FreeOTP until a later separately reviewed version.
