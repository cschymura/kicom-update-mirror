# KiCom Membrane — Slack Group DM ingress staging and communication preservation

Date: 2026-09-19
Repository: cschymura/kicom-update-mirror
Development branch: work/kicom-0.9.27-pam
Previous checkpoint: development/0.9.27/membrane/CHECKPOINT-2026-09-19-SLACK-BRIDGE.md

## Verified current state

Read-only BOOTSTRAP, SLACK_STATUS, GENOME_STATUS, SQLITE_STATUS and UPDATE_STATUS still report live 0.9.26 / kicom-0.9.26-g25r3, healthy/trusted/LKG true, SQLite WAL quick_check=ok, no pending update. The original Slack runtime reports configured=false, bot_token_configured=false, signing_secret_configured=false and an inbound app_mention-only allowlist for its original #kicom channel.

Christoph created a Slack group DM containing ChatGPT and a participant named KiCom. ChatGPT read it and sent one non-sensitive receipt request through its own existing Slack connection. A subsequent read did NOT find a KiCom reply. This proves ChatGPT ↔ Slack read/send, NOT KiCom-runtime Slack receipt, autonomous two-way operation or an independent membrane. Never archive the private group DM identifiers or message contents in public GitHub.

## Development implemented in this cycle

- KiComMembraneSlackMpimIngress.php: isolated PHP, read-only, metadata-only STAGING handler for a separately authorized Slack message.mpim event. Requires explicit protected staging signing secret and exact expected workspace / group conversation. Validates raw-body Slack HMAC-SHA256 with X-Slack-Signature, exact X-Slack-Request-Timestamp within ±300 seconds, one-MiB cap, valid event_callback and message/mpim type, non-bot/non-subtype human sender, event ID, exact workspace and conversation. Consults an injected read-only dedup hint only after signature/allowlist verification. Returns hashes of event/message metadata but NEVER message text, credentials or a message execution capability.
- This module deliberately does NOT mark an event as durably consumed or claim exactly-once delivery. Even on a fully verified candidate it returns delivery_authorized=false, action_authorized=false and runtime_ack_verified=false. A future protected event adapter must use a durable atomic check-and-mark with crash/retry reconciliation; event ID and a PHP closure alone do not prove idempotence. A caller-supplied secret or boolean is not an external authority.
- test-mpim-ingress.php: 24 synthetic regression checks including positive signed test event, invalid HMAC/timestamp/body, stale/future timestamp, missing trust configuration, wrong workspace/conversation/event type, bot/subtype loops, malformed event ID, replay hint and failing dedup backend. All data and signing secret are synthetic.
- Existing .github/workflows/test-0927-membrane.yml now lints and executes the new files with the original R3 communication, isolated local HTTP, cross-UID and no-replay tests. The native R3 Slack route, the production #kicom allowlist, OAuth configuration and network paths are unchanged.

## Current tested evidence

GitHub Actions RUN_ID=35448511062, JOB_ID=105911509564, conclusion=success; exact tested checkout/trigger SHA dbb583923a06a175685e470f2d97d6ca89f64c14.

28 Shadow + 24 native R3 transport contract + 16 transparent-tap + 14 group-DM bridge observation + 24 new signed MPIM metadata ingress + 16 R3 GET + 27 negative R3 POST + 20 positive synthetic loopback + 6 OS filesystem boundary + 8 synthetic cross-UID transport + 6 OS alternate-path checks = **189 successful isolated checks**.

CI: https://github.com/cschymura/kicom-update-mirror/actions/runs/35448511062

This verifies STAGING SOURCE and TEST FIXTURES only. It is not evidence of a live KiCom Slack bot, successfully delivered Slack events, an outbound KiCom ACK or production network membrane.

## Official protocol basis

- Slack request signing (raw body, v0 HMAC-SHA256, five-minute timestamp window): https://docs.slack.dev/authentication/verifying-requests-from-slack
- Group direct-message event message.mpim and field channel_type=mpim: https://docs.slack.dev/reference/events/message.mpim/
- Group DM history/event scope mpim:history: https://docs.slack.dev/reference/scopes/mpim.history/
- app_mention is not the same as group DM message events: https://docs.slack.dev/reference/events/app_mention/

A future live group DM ingress needs an authorized app installed in the specific group DM, an explicitly configured/bound separate event route and properly selected scopes. Do not change the original #kicom allowlist or broaden a private-group audience silently. Keep provider-verified identity distinct from KiCom-runtime acknowledgement. Handle provider retries and message receipts with durable idempotence; don't auto-replay mutating messages on timeout.

## Next technically safe development

1. In completely isolated staging, implement a durable atomic event-id receipt and a read-only/no-action KiCom-runtime ACK handshake, with crash/retry and cross-UID tests. Keep the provider-signature reader separate from application action authorization. No real Slack messages until a distinct staging app/channel/test destination is explicitly authorized.
2. Independently verify whether the actual production host can run protected runtime principals and a network-egress controller outside KiCom's writable/rollback domain. Existing GitHub CI demonstrates OS file permission separation on Linux only; not the actual production host and not non-bypassable egress.
3. For production, retain original KCL/DEV/Passkey/Mail/Opera/Update semantics, package/Genome hash verifier, original authorization, backup, LKG, healthcheck and tested rollback. No FreeOTP request for internal GitHub work, but external privileged boundaries remain separately authorized.
