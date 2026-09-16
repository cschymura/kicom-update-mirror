# KiCom checkpoint — Sandbox Expansion Cell ready

Date: 2026-09-16
Status: candidate ready for controlled first real test

## Canonical/live facts checked

- Live KiCom trusted baseline remains 0.9.15.
- Existing KiCom deployment registry exposes alias `sandbox` as enabled class `test` with a healthcheck.
- First real child target is `https://sandbox.rurtalbahn.info/kicom/`.
- Existing sandbox root content must remain untouched; only a new `kicom/` directory may be created.
- `https://sandbox.rurtalbahn.info/kicom/status.php` was absent before deployment.

## Implemented deployment path

The Expansion Cell candidate now supports KiCom deployment-resource aliases directly. `KiComExpansionKiComDeployTargetResolver` resolves an existing enabled KiCom target through `kicomDeployTarget()` inside the parent runtime. Expansion v1 accepts only target classes `test` and `staging`; production resources are rejected. Absolute filesystem roots stay internal and are never returned in Expansion result payloads.

For the sandbox test the command is fixed to:

- target base URL: `https://sandbox.rurtalbahn.info`
- deploy mode: `resource`
- deployment resource: `sandbox`
- child directory: `kicom/`

No FTP credentials and no operator-visible absolute hosting path are required.

## DEV integration

The existing passkey DEV zone gains two DEV-only capabilities:

- `expansion.resource.status`
- `expansion.test.execute`

A dedicated header-authenticated POST endpoint `dev/dev-expansion.php` exposes only:

- `STATUS`
- `EXECUTE_SANDBOX`

The endpoint is hard-bound to resource alias `sandbox` and `https://sandbox.rurtalbahn.info`. It does not accept arbitrary target aliases, arbitrary filesystem paths, or production expansion. The GET agent bridge cannot execute this operation.

The DEV UI now contains `Sandbox prüfen` and `Sandbox-Zelle starten`. Existing DEV sessions predate the new capabilities, so after installing this bundle a fresh passkey DEV session must be opened once. Existing passkey registration remains usable; no new FreeOTP enrollment is required.

## Validation

Validated source head: `6110ea440781ce0cc8ce96a4323f524b0f156e4c`

- DEV zone workflow run `35142878619`: SUCCESS.
  - syntax
  - candidate manifest
  - DEV boundary checks
  - passkey/session selftest
  - runtime bindings
  - diagnostics
  - sandbox expansion binding selftest
  - install bundle build and artifact upload
- Expansion Cell workflow run `35142878491`: SUCCESS.
  - federation/enrollment
  - cron relay
  - package/orchestrator
  - parent service
  - local filesystem deploy
  - KiCom deployment-resource resolver
  - secret/shared-hosting/local-resource/authority boundary guards

DEV artifact ID: `10466346142`
Outer artifact digest: `sha256:1e7cab10c4f0aa6816662934e203359bb5d47834978e2a7e93d32d51102fc415`
Install ZIP SHA-256: `aa2e25cf63a1889d48d913ce3a5b9e0324459b86e4714b3ec5db1f2a3761c24c`

## Next live sequence

1. Install the validated DEV candidate over the existing `/dev/` module.
2. Open `https://kicom.rurtalbahn.info/dev/?v=4`.
3. Open a fresh DEV session with the already-enrolled passkey.
4. Run `Sandbox prüfen`; require an enabled writable `test` resource.
5. Run `Sandbox-Zelle starten`.
6. Parent prepares child package, deploys only to `sandbox/kicom/`, probes child, enrolls it, signs activation and sends the first federation tick.
7. Verify `https://sandbox.rurtalbahn.info/kicom/status.php` and the parent cell registry.
8. Record the result as experience/checkpoint. Do not claim deployment success before step 7 succeeds.

No OTPs, DEV bearers, passkey private material, FTP credentials or absolute hosting paths belong in this checkpoint.
