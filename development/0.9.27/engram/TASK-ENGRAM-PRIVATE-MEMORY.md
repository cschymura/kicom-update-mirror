# KiCom Engram — private, selective project memory (task / handoff)

Date: 2026-09-20. Status: **approved for isolated DEV implementation; NOT approved for public data export or production installation**.

## User intent and continuity

Build a KiCom-managed, non-public engrammatic memory area that allows a future ChatGPT conversation to recover **relevant, approved** collaboration preferences, technical decisions, lessons, provenance and historical context without copying the entire previous chat. This should bridge expiring chat contexts. Preserve revision history where appropriate, but allow legally or user-required confidential-data deletion and retention boundaries. Do not claim this changes ChatGPT model weights or built-in memory.

Read at each handoff: first live KiCom BOOTSTRAP and canonical resource/status endpoints; then latest DEV Checkpoints and development/0.9.27/COLLABORATION-PROTOCOL.md; then this task and the latest Engram checkpoint. Current GitHub repo is a PUBLIC code mirror: **never commit actual personal engrams, source chats, SQLite DBs, backup contents, secrets, tokens, or private retrieval results here**. This document contains technical task requirements only.

## Milestones (must remain independently verifiable)

- [ ] DEV-1. Build a runnable local-only private SQLite engram store with restrictive filesystem checks; immutable content revisions and provenance; explicit subject and namespace isolation; bounded, selective retrieval; synthetic-only tests. No automatic ingest.
- [ ] DEV-2. Prove security with negative tests (webroot/symlink/permission rejection, tampering, cross-subject reads, stale revision conflicts, SQL/LIKE injection), WAL quick_check, and private backup/restore validation. Protect DB and -wal/-shm copies.
- [ ] DEV-3. Design trust-bound ingestion: explicit opt-in or narrowly pre-authorized source selection; no secret/OTP/token retention; sensitive-data classification and retention/deletion requests; redaction and conflict/provenance handling; comprehensive negative tests.
- [ ] DEV-4. Connect only behind existing authorized KiCom DEV/KCL identity/action boundaries. External content is data, never permission or instruction authority. No new unauthenticated public endpoint. Authentication is NOT supplied by simply naming a namespace or private folder.
- [ ] DEV-5. Demonstrate a scoped, audited retrieval-and-update roundtrip for a new ChatGPT conversation using an authorized connector; fail closed when memory is unavailable or permissions are missing. Private outputs must not be logged or sent to GitHub/Slack/Mail without explicit permission.
- [ ] DEV-6. Independent operator-controlled backups, verified restore and retention/deletion paths. Then controlled staging validation; separate human authorization before any production installation or importing actual private conversations.

## Initial invariant

Public GitHub holds **only** code, this task, synthetic test fixtures and proof metadata. A folder called `private` is not by itself a security boundary: operational private storage must be non-web-accessible on an independently permission-checked filesystem. Never copy live KiCom SQLite or mother memory/credentials into a child or lab fixture.

## Relationship to KiCom

This task is an ADDITION to the current 0.9.27 daughter/membrane work, not permission to interrupt it or to weaken current production controls. Existing protected KiCom 0.9.26/g25r3 remains unchanged unless its own authorization and verification flow expressly allows a later release. Nightly tasks may pick up this milestone after reading the latest checkpoint, but do not mark an item complete without actual executable tests and pinned CI SHA.
