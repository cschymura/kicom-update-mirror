# KiCom 0.9.4 – Autonomous Evolution Epochs

KiCom 0.9.2 reduces human cognitive load and adds redundant update transport without creating a second trust path.

## Human Control Plane
- The admin front page prioritizes **health, attention required, update state and human decisions**.
- Technical hashes, channel secrets, deployment internals and recovery details move behind an **expert view**.
- Human effort is risk-proportional:
  - **Green:** presentation/metadata-only changes with unchanged trust boundaries can auto-install.
  - **Yellow:** verified non-boundary code/memory changes require one deliberate click.
  - **Red:** kernel, API/perimeter, invariant or trust-boundary changes require explicit confirmation.

## Redundant update channels
Three transports coexist:
1. **Admin upload** – recovery/fallback path.
2. **Pull** – KiCom checks up to two human-configured HTTPS release feeds.
3. **Authenticated Push / Inbox** – an authorized client POSTs a ZIP directly to `api.php?q=UPDATE_PUSH`.

All transports converge on the **same verifier**: manifest, SHA-256, PHP syntax, Genome lineage, kernel revision, risk classification, complete backup, post-write healthcheck and automatic rollback.

### Feed format
```json
{
  "schema": 1,
  "product": "kicom",
  "releases": [
    {
      "version": "0.9.3",
      "url": "https://update.rurtalbahn.info/kicom/KiCom-0.9.3.zip",
      "sha256": "<64 hex>",
      "published_at": "2026-09-15T00:00:00Z"
    }
  ]
}
```
The package URL must be HTTPS and use the same host as the feed URL.

### Push
POST raw ZIP or multipart field `package` to:
`api.php?q=UPDATE_PUSH`

Authenticate with:
`X-KiCom-Update-Key: <secret>` or `Authorization: Bearer <secret>`.

### Idle-time update agent
The admin UI shows a secret URL:
`?q=UPDATE_AGENT&key=<secret>`

A shared-hosting URL cron can call it. Green updates can install without operator interaction; yellow/red updates are staged for the Control Plane.

## Existing 0.9 safety remains
The trusted Genome, LKG, Autonomous Immune Guardian, maintenance/healing locks, Recovery Kernel, quarantine, evolution lineage, privacy hardening and automatic rollback remain active.

`var/` and `stage/` runtime content remain preserved across self-updates.


## 0.9.3
Per-feed diagnostics in the Human Control Plane: last check time, HTTP status, JSON validity, release count and concrete result code. No trust-boundary or permission changes.

## 0.9.4 – Autonomous Evolution Epochs
- Evolution loop runs from the existing Update-Agent cadence; no new cron secret is required.
- Derives non-executable improvement goals from Living Insights.
- FIT green candidates can be promoted and installed autonomously after the same manifest, SHA-256, Genome, backup and healthcheck path.
- Yellow/red candidates remain human-gated.
- Product version and evolution generation are separate.
- Every 10 successful evolutionary generations creates a protected Epoch report plus LKG metadata snapshot.
- Evolution stops immediately on untrusted Genome, missing LKG, drift, unknown code or kernel drift.
- A human can pause/resume autonomy at any time.
