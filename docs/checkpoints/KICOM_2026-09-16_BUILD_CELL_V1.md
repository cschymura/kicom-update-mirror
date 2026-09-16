# KiCom Build Cell v1 checkpoint — 2026-09-16

## Purpose

Build Cell v1 is the next step after the practical DEV zone. Code execution is moved off the live shared host and into a disposable GitHub-hosted runner so KiCom development can use real PHP/Composer/test/build tooling without receiving production authority.

## Implemented

- Branch: `candidate/build-cell-v1`
- Draft PR: #18 `Candidate: KiCom Build Cell v1`
- Base: `candidate/dev-zone-v1`
- Native verifier with bounded repository inventory and deterministic tree SHA-256.
- PHP syntax parsing via `TOKEN_PARSE` without executing inspected source.
- JSON validation.
- Secret-like file guard for `.env`, private key names/extensions.
- Composer policy inspection.
- CI dependency materialization with `--no-scripts --no-plugins` when a root `composer.json` exists.
- Full-project PHP lint.
- PHPUnit execution inside the disposable runner when a test runner is present, with a hard timeout.
- Exact Git commit packaging with `git archive`, SHA-256 sidecar, native verifier report and cell metadata.
- Read-only repository permission in the Build Cell workflow.
- No production credentials are provided to the runner.

## Isolation model

The live shared-host PHP runtime is not treated as an OS sandbox. Arbitrary generated tests are therefore not executed there. The execution boundary is an ephemeral GitHub-hosted runner. Test code may run in that disposable environment, but it has no KiCom production bearer, FreeOTP/TOTP secret, passkey private material, hosting credential, deployment secret, recovery authority, kernel authority or production secret-store access.

GitHub-hosted runners have ordinary network access in v1, so the primary protection is credential isolation rather than an egress firewall.

## First CI result

Workflow: `KiCom Build Cell v1`
Run: `35123025693`
Result: success.

All stages passed: runtime inventory, PHP syntax for Build Cell code, Build Cell selftest, native repository verification, Composer phase, project PHP lint, disposable test runner phase, secret-material packaging guard, immutable artifact build and artifact upload.

GitHub Actions artifact: `kicom-build-cell-v1`
Artifact ID: `10457393754`
Artifact digest: `sha256:fe74117c9175b3fd9336264f2c7c0b126543ab95deceb4087ad20bdc3d7e3f68`
Retention: 14 days. Durable source remains the Git branch/commit history.

## Development loop

`edit candidate -> Build Cell -> tests -> immutable artifact -> review -> separate production promotion`

This keeps ordinary development fast while concentrating critical security checks at the production boundary.

## Secrets rule

Do not persist OTP codes, TOTP seeds, DEV/normal session tokens, passkey private material, hosting credentials, deployment credentials or private encryption keys in Build Cell requests, artifacts, documentation or Git history.

## Status

Candidate only. No production deployment or live KiCom core change is performed by PR #18 itself.
