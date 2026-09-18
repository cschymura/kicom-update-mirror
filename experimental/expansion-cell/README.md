# KiCom Expansion Cell v1

Candidate implementation for operator-authorized KiCom federation expansion.

## Purpose

A parent KiCom may deploy a child runtime to a specifically supplied webspace, enroll it once, and then communicate by signed HTTPS federation messages. Bootstrap FTP/FTPS is temporary transport only.

## Components

- `ExpansionProtocol.php` — IDs, one-time enrollment proof, Ed25519 signed envelopes.
- `ExpansionRegistry.php` — parent-side expansion state and child registry.
- `ExpansionCellRuntime.php` — child-local identity, activation state and replay protection.
- `ExpansionFtpDeployer.php` — bounded upload into a dedicated `kicom/` subtree. Credentials remain call-local.
- `ExpansionCronRelay.php` — signed `FEDERATION_TICK` / result helpers.
- `expansion-selftest.php` — standalone protocol/runtime/registry regression test.

## Boundaries

This candidate does not scan the internet, choose its own target, overwrite unrelated applications, persist FTP credentials, or inherit production authority. Recursive expansion requires a separately declared capability.
