# KiCom project goal — Expansion Cells / Federation

Status: ACTIVE GOAL
Recorded: 2026-09-16

## Goal

KiCom shall be able to receive a bounded command to expand onto a user-authorized target domain/webspace and create a connected child KiCom cell there.

The intended human interaction is deliberately small:

1. The operator tells the parent KiCom to expand to a target domain.
2. The operator provides temporary FTP/FTPS credentials and the remote web-root path.
3. Parent KiCom creates a one-time enrollment transaction and uploads a child-cell package into a `kicom/` directory on the target webspace.
4. The new child cell generates its own node identity/key material on the target host and immediately enrolls with its parent.
5. Parent and child establish a persistent signed trust relationship. Temporary FTP/FTPS credentials are no longer needed and must not be retained by KiCom.
6. The existing parent cron becomes a federation scheduler: it forwards authenticated ticks/work to active child cells, so a separate hosting-panel cron is not required for every cell.
7. The federation is then operational and visible as a node tree.

## Core model

Every KiCom node has:

- `cell_id`
- `root_id`
- `parent_id` (null only for the root)
- `generation`
- `public_signing_key`
- `base_url`
- `state`
- declared `capabilities`
- creation/last-seen timestamps

Private signing material is generated locally by each node and never copied from the parent.

## Expansion lifecycle

Canonical lifecycle names for v1:

- `EXPANSION_PREPARE`
- `EXPANSION_DEPLOY`
- `EXPANSION_PROBE`
- `EXPANSION_ENROLL`
- `EXPANSION_ACTIVATE`
- `EXPANSION_STATUS`
- `EXPANSION_REVOKE`

State machine:

`prepared -> deployed -> reachable -> enrolled -> active`

Failure/recovery states:

`failed`, `expired`, `revoked`.

Enrollment is one-time and transaction-bound. It must not grant production authority beyond the new child cell relationship.

## Bootstrap transport

FTP/FTPS is birth/bootstrap transport only.

Rules:

- Prefer FTPS whenever supported.
- FTP credentials are supplied transiently for one expansion operation.
- Credentials are never written to project memory, Git, logs, node registry, release notes, diagnostics or child configuration.
- The target deployment directory defaults to `kicom/` beneath the operator-supplied web root.
- After a successful enrollment, ongoing federation communication uses signed HTTPS messages, not FTP.

## Parent/child trust

The parent creates a one-time high-entropy enrollment token. Only a derived verifier is persisted by the parent.

The child:

1. creates its own Ed25519 signing key pair locally;
2. creates its own `cell_id`;
3. proves possession of the one-time enrollment token;
4. sends its public descriptor to the parent;
5. receives the parent/root descriptor and activation information;
6. erases transient enrollment material.

Normal federation messages are signed by the sending node and bind at least:

- sender cell ID,
- receiver cell ID,
- operation,
- issued-at time,
- nonce/message ID,
- payload hash.

## Cron federation

The root/parent cron may act as a scheduler/relay.

For every active child it can send a signed `FEDERATION_TICK` to the child's HTTPS endpoint. The child verifies the parent signature and freshness, performs only locally allowed work, and returns a signed result/status.

This means a child can operate on ordinary shared hosting without requiring a dedicated hosting-panel cron entry, provided inbound HTTPS execution is available.

## Recursive expansion

A child may later be allowed to create children of its own, producing a tree:

```
ROOT
├── Cell A
│   ├── Cell A1
│   └── Cell A2
└── Cell B
    └── Cell B1
```

Recursive expansion is an explicit capability and is not automatically inherited by every child.

## Relationship to the existing DEV and Build Cell work

- The practical `/dev/` zone remains the local development authority plane.
- GitHub + CI remains the current development transport while direct agent transport is unavailable.
- The GitHub-hosted Build Cell v1 remains archived as a separate build/test tool.
- Expansion Cells are a different concept: persistent KiCom nodes deployed to operator-authorized webspaces and joined into a federation.

## Non-goals for v1

- no arbitrary internet scanning or self-selected targets;
- no credential harvesting or persistence;
- no propagation without explicit operator-supplied destination access;
- no automatic production takeover of another application;
- no sharing of parent private keys/secrets with children;
- no implicit production deployment privilege simply because a node is federated.

## Durable project rule

This is a long-term KiCom objective. Preserve this goal and its implementation history. Superseded designs should be archived rather than destructively deleted unless the operator explicitly requests deletion.
