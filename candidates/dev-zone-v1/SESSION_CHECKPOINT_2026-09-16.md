# KiCom DEV Zone — Session Checkpoint 2026-09-16

## Agreed operating mode

- Normal KiCom development should be practical and low-friction.
- Development work proceeds primarily through GitHub / isolated candidate builds.
- KiCom remains the controlled build/target system and production authority boundary.
- Do not re-introduce rolling-token or repeated FreeOTP/browser-relay chains into normal development.
- Production / self-update install / kernel / recovery / auth administration / secrets remain outside DEV authority.

## Live DEV Zone result

- DEV Zone is installed under `/dev/` on the KiCom host.
- First-party browser UI loads successfully.
- Passkey flow works and can create an active DEV-scoped session.
- DEV sessions are designed as reusable non-rotating credentials with 7-day absolute TTL and 24-hour idle TTL.
- Browser DEV API path issue was fixed so the client stays inside `/dev/`.
- The agent bridge exists as a DEV-only GET bridge, including an extensionless `/dev/agent/` entry point for tools that cannot consume `.php` URLs directly.
- DEV credentials carry only DEV authority and must never be treated as production authorization.

## Current transport limitation

The user-facing browser path and DEV session work. However, ChatGPT's currently available external web extraction transport does not reliably return dynamic private/capability URL responses from the KiCom agent bridge. This is a transport/tooling limitation outside the KiCom browser flow, not evidence that the DEV session itself is broken.

## Development decision

Until a direct first-party connector/transport is available, continue development via GitHub and CI. Use KiCom as build/target system rather than spending further cycles on browser/OTP/token relay plumbing. Revisit the agent bridge only when a suitable direct connector exists or when a concrete integration need justifies it.

## Security / secret handling

Do not store any live session ID, bearer token, FreeOTP code, passkey secret, or other credential in this checkpoint, Git history, canonical memory, or project documentation.

## Standing intent

Treat these results and decisions as the default project context for subsequent KiCom work unless the user explicitly overrides them.
