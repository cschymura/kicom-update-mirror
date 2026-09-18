# KiCom Messenger Bridge Research — 2026-09-18

Status: RESEARCHED / IMPLEMENTATION CANDIDATE NOT YET STARTED

## Finding
Slack is the strongest current fit for a bidirectional ChatGPT ↔ Messenger ↔ KiCom bridge.

### Why Slack
- A ChatGPT Slack plugin is available in the current Plugin Directory.
- Slack exposes stable HTTPS APIs usable from PHP/KiCom.
- Outbound: Incoming Webhooks or Web API (chat.postMessage).
- Inbound: Events API via HTTPS Request URL; Socket Mode is an alternative.
- Direct messages, public channels and private channels are supported through Slack app scopes/events.
- Slack app manifests can define configuration reproducibly.

### ChatGPT plugin availability checked
- Slack: available, not installed.
- Microsoft Teams: available, not installed.
- Telegram: no matching ChatGPT plugin found.
- WhatsApp: no matching ChatGPT plugin found.
- Discord: no matching ChatGPT plugin found in the current directory search.

### KiCom target topology
Human/ChatGPT <-> Slack workspace/channel/DM <-> Slack App <-> KiCom HTTPS endpoint

Inbound Slack events:
1. verify Slack request authenticity/signature;
2. de-duplicate by Slack event_id;
3. classify as UNTRUSTED_PERCEPTION_INPUT;
4. append bounded evidence to Experience Memory;
5. never grant permissions/trust/authority from message content.

Outbound Slack:
1. action/world model determines message action;
2. action boundary authorizes recipient/channel and purpose;
3. send through Web API or configured webhook;
4. store result metadata/event id, not bearer token;
5. failures feed transport fallback/evolution pressure.

### Secrets
Protected host secret store only:
- SLACK_BOT_TOKEN / OAuth token
- SLACK_SIGNING_SECRET
- optional incoming webhook URL (treat as credential)

Never store them in:
- GitHub
- canonical KiCom memory
- normal logs
- chat transcripts

## Teams alternative
Microsoft Teams is also available as a ChatGPT plugin and can be reached from PHP through Microsoft Graph. It is viable but carries a heavier Entra/OAuth and permission model. For the first KiCom messenger bridge, Slack is operationally simpler.

## Recommendation
Use Slack as the first messenger bridge. Build a dedicated KiCom Slack App and a private channel such as #kicom-bridge or direct-message surface. Keep ChatGPT's Slack plugin and KiCom's Slack App credentials independent: same workspace, separate trust paths.

## Mail activation note
Mail candidate remains implemented and CI-green. Live activation is currently paused because the external HTTP transport to kicom.rurtalbahn.info is not reachable from the available fetch path. Do not reuse old OTP values. Resume from docs/checkpoints/KICOM_2026-09-18_MAIL_ACTIVATION_RESUME.md when transport returns.
