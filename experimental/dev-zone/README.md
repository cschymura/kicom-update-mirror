# KiCom Development Zone v1

This candidate separates development authority from production authority and removes rolling-token friction from normal KiCom development.

## Developer experience

After one WebAuthn/passkey confirmation KiCom issues a DEV-only bearer session with a 7-day absolute lifetime and 24-hour idle lifetime. The bearer does not rotate per request and can be reused by the browser and approved development tooling. Server-side storage contains only the token hash. A session can be revoked at any time.

First-time passkey enrollment is intentionally separate: the existing FreeOTP verifier is used once to register a passkey. Normal DEV access then uses the passkey only.

## First-party runtime

The install-oriented bundle places the module under `dev/`:

- `dev/index.html` — small first-party control page for passkey enrollment, connection, status and logout.
- `dev/dev-auth.php` — enrollment and passkey authentication endpoint.
- `dev/dev-api.php` — header-authenticated DEV API.
- `dev/browser.js` — WebAuthn browser codec.
- `dev/dev-client.js` — reusable browser DEV client.
- PHP modules for session, auth flow, router, HTTP transport and bindings to KiCom's existing workspace/source/build primitives.

DEV credentials are sent as `X-KiCom-Dev-Session` and `X-KiCom-Dev-Token` headers. Query-string credentials are not used.

## Fixed DEV capabilities

`source.snapshot.read`, `workspace.read`, `workspace.write`, `workspace.delete`, `workspace.history`, `build.begin`, `build.patch`, `build.status`, `build.test`, `build.finalize_candidate`, `candidate.read`, `candidate.discard`, and `logs.read`.

Explicitly excluded: production deployment, self-update installation, kernel mutation, recovery mutation, auth administration and secret-store access. There is no wildcard capability.

## Runtime operations

The router implements `DEV_SESSION_STATUS`, `DEV_SESSION_REVOKE`, `DEV_SOURCE_SNAPSHOT`, workspace read/write/delete/history, build begin/patch/status/test/finalize-candidate, candidate read/discard and log-read capability routing. Each operation is bound to exactly one fixed capability before its handler runs.

Workspace writes keep KiCom's exact-base concurrency semantics and history. Source reads reuse the trusted installed-source allowlist. Builds reuse the protected KiCom fast-build area.

## Candidate boundary

`DEV_BUILD_FINALIZE_CANDIDATE` is intentionally **not** the existing production-oriented fast-build finalize call. DEV performs release preparation, validates the isolated tree with KiCom's existing package verifier and exports a candidate ZIP inside the protected build area. It does **not** call `kicomReceiveSelfUpdatePackage`, does not create a production pending update and cannot install anything.

The candidate can be read in bounded chunks for CI/review or discarded. Promotion to live KiCom is a separate operation at the production boundary.

## Concurrency and revocation

Passkey verification/session issuance is serialized per WebAuthn challenge, so one challenge cannot mint two DEV sessions. Authentication and revocation share the same exclusive per-session lock; a concurrent request therefore cannot overwrite a completed revoke with stale active state.

## Development rules

The DEV zone deliberately favors iteration speed. It does not provide arbitrary shell access or arbitrary filesystem access, and it cannot cross into production authority. CI checks syntax, crypto/runtime dependencies, capability boundaries, header-only credential transport, WebAuthn/session behavior, DEV router behavior, HTTP behavior and KiCom runtime bindings, then builds an install-oriented candidate artifact.
