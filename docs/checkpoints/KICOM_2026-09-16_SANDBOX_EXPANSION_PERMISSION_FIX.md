# KiCom checkpoint — Sandbox Expansion permission/resume fix

Date: 2026-09-16
Branch: `candidate/expansion-cell-v1`
PR: #19

## Live observation
The first real `sandbox.rurtalbahn.info` Expansion Cell attempt reached the point where the allowlisted `sandbox` deployment resource reported ready/writable, but `Sandbox-Zelle starten` returned `Expansion fehlgeschlagen`. The public child status endpoint was not reachable afterwards.

## Root cause identified in candidate
The local filesystem deployer copied the complete public child tree with mode `0600` and activated the `/kicom` directory with mode `0700`. Those permissions are appropriate for private state but can prevent a shared-hosting web server from traversing/reading the public PHP endpoints after a successful filesystem copy.

## Fix
- Public Expansion Cell tree now uses shared-hosting web permissions: directories `0755`, public files `0644`.
- `bootstrap.config.php` remains `0600`.
- `var/` remains `0700` and private state files remain `0600`.
- Existing `/kicom` is never blindly overwritten.
- A safe repair/resume path was added. It only accepts an existing target that proves it is a KiCom Expansion Cell via the expected cell markers.
- If the previous attempt copied the cell but HTTP bootstrap failed, KiCom repairs only permissions, recovers the existing expansion ID locally, and resumes enrollment/activation instead of creating a conflicting second deployment.
- Arbitrary/foreign existing target directories remain refused.

## Validation
Both PR CI workflows passed on commit `46f2077d2cd2493d319da0272374eb5d5672f541`:
- KiCom DEV zone checks run `35144831851`: success.
- KiCom Expansion Cell v1 run `35144831833`: success.

The local filesystem selftest now asserts:
- public root `0755`;
- public endpoint `0644`;
- public lib directory `0755`;
- bootstrap secret `0600`;
- private state directory `0700`;
- safe permission repair/resume metadata;
- refusal to repair a foreign existing target.

## Recovery package
A minimal live delta contains only:
- `dev/expansion/ExpansionLocalFilesystemDeployer.php`
- `dev/expansion/ExpansionOrchestrator.php`
- `dev/expansion/ExpansionService.php`

No DEV sessions, passkeys, OTPs, server paths, enrollment tokens or other secrets are included.
