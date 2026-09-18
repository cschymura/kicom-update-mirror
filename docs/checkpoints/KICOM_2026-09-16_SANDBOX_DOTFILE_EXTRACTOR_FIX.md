# KiCom checkpoint — Sandbox dotfile extractor compatibility fix

Date: 2026-09-16

## Observed live failure
The first real Sandbox Expansion Cell run reached the Expansion Cell package builder but returned:

- HTTP 422
- `EXPANSION_PACKAGE_SOURCE_MISSING`
- missing source path: `cell-runtime/.htaccess`

At the same time the DEV session itself was healthy and active.

## Root cause
The shared-hosting archive/extraction path can omit hidden dotfiles. The DEV bundle was valid in GitHub CI and contained the source `.htaccess`, but the live installed expansion source did not. The child package builder incorrectly treated that hidden source file as mandatory.

## Fix
`ExpansionCellPackageBuilder` no longer depends on a source `.htaccess` file. It generates the child `.htaccess` itself during package construction. The generated rules preserve the intended protections for `bootstrap.config.php` and `common.php`, and the private `var/.htaccess` is generated as well.

The orchestrator selftest now deliberately builds from a source fixture that contains no dotfile and verifies that the child `.htaccess` is generated correctly.

## Validation
Code head validated: `694a0cebdbe5c012f5071f5da2fefec4e5436a7a`

- Expansion Cell CI run `35146877894`: SUCCESS
- DEV Zone CI run `35146878031`: SUCCESS

## Deployment rule retained
On this shared-hosting path, prefer full DEV code restores over partial `dev/` archive overlays. Runtime/session/passkey state remains outside the code bundle under KiCom's var storage.

## Next live step
Install the full DEV restore built from the validated head, reload `/dev/`, confirm `DEV verbunden`, then run `Sandbox-Zelle starten` again. The next expected boundary is child package deployment/enrollment, not source-dotfile discovery.
