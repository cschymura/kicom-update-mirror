# KiCom Artifact Transport Bus v1

Status: **candidate / not yet production-promoted**.

## Purpose

The transport bus makes update acquisition redundant without giving every transport installation authority.

Pipeline:

`known source -> acquire -> SHA-256 verify -> retained staging/archive -> existing KiCom updater -> health/commit/rollback`

The transport bus does **not** write the production application tree. The existing KiCom updater remains responsible for package inspection, risk classification, snapshots/LKG, installation, health checks, commit and rollback.

## Source strategy

All enabled automatic sources are tried in priority order until the exact requested artifact is obtained. Failure of a fast source does not disable slower fallbacks.

Supported source classes:

- `https-channel` — channel/manifest then package download;
- `https-package` — fixed configured package URL template;
- `local-inbox` — manually placed artifact, retained after use;
- `external-adapter` — bounded adapter interface, initially FTPS/explicit break-glass FTP;
- `manual-upload` — request-only fallback into the existing upload verifier;
- `recovery` — request-only break-glass path independent of normal runtime health.

Planned independent web mirrors, including a future Daisy/ChatGPT Sites mirror, are additional `https-channel` entries rather than special privileged paths.

## Security and authority

- Expected SHA-256 is mandatory before a package can be handed to the updater.
- HTTPS sources are configured and host-pinned; this is not arbitrary remote fetch.
- Redirects remain subject to the configured host set.
- FTP credentials are provided only at runtime by a credential provider and are never persisted in source configuration or history.
- FTPS is preferred. Plain FTP is rejected unless the individual source explicitly enables `allow_plaintext_ftp`.
- Source state and attempt history contain no credentials.
- Transport history is append-only.
- Acquired artifacts are archived after updater handoff, including failures.
- Local inbox originals are copied, not consumed.

## Source state

Each source maintains a materialized current state plus append-only history:

`UNKNOWN | AVAILABLE | UNAVAILABLE | DEGRADED | FORBIDDEN | STALE`

Remembered fields include last result code, success/failure timestamps, latency and counters. This is evidence for routing; it never grants additional authority.

## Existing KiCom updater integration

`KiComArtifactTransportBus::handoffToUpdater()` accepts a callback with the same conceptual boundary as the existing runtime function:

`receiver(path, originalName, source, allowAuto)`

In production that callback can be bound to the established self-update receiver. Transport does not duplicate or bypass updater validation.

## Files

- `ArtifactTransportBus.php` — routing, hashing, source memory and updater handoff.
- `ArtifactFtpAdapter.php` — FTPS and explicit plain-FTP break-glass acquisition.
- `sources.example.json` — proposed source matrix.
- `artifact-transport-selftest.php` — fallback, hash, history and authority tests.

## Promotion rule

This candidate should first be deployed as inert/DEV-visible code and observed. Productive binding to the existing updater is a separate promotion step. The current `/dev/` artifact importer remains a narrow bootstrap tool and must not be mistaken for the central production transport bus.
