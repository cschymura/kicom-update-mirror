# KiCom DEV full-restore rule — 2026-09-16

Observed live on `https://kicom.rurtalbahn.info/dev/` after installing partial repair ZIPs through the hosting file manager:

- the HTML DEV page remained reachable;
- `dev-api.php`, `dev-auth.php`, and `dev-expansion.php` all returned HTTP 404;
- the browser therefore reported `DEV_RESPONSE_INVALID` with an Apache 404 HTML body;
- the previous sandbox expansion run had already shown that the sandbox deployment resource itself was ready.

## Root cause / operational conclusion

The hosting-side ZIP extraction workflow must be treated as potentially replacing the top-level `dev/` directory instead of safely merging a partial directory tree. Therefore a partial ZIP whose root contains only `dev/expansion/...` can remove sibling DEV endpoints.

This is an installation/packaging issue, not an Expansion Cell protocol failure.

## Permanent packaging rule

Do not ship partial repair ZIPs with a top-level `dev/` directory for installation through the hosting ZIP extractor.

Use one of these instead:

1. a full code-only `dev/` restore package containing every runtime endpoint and module; or
2. explicit individual-file replacement when the installer guarantees exact-file writes.

The full restore package must contain at minimum:

- `dev/index.html`
- `dev/dev-api.php`
- `dev/dev-auth.php`
- `dev/dev-expansion.php`
- Passkey and DEV session/router/binding modules
- `dev/expansion/` complete Expansion Cell runtime

It must not contain runtime state directories, session credentials, passkey private material, OTP values, or federation secrets.

## State preservation

DEV credentials/state are stored through `kicomVarDir()` (for example `dev_zone` and federation state), not in the public `/dev/` code directory. A code-only full restore therefore preserves runtime state while restoring the web endpoints.

## Recovery package

Use the latest green CI artifact from `candidate/expansion-cell-v1` and materialize its complete `kicom-dev-zone-v1/dev/` tree as the install payload.

Current verified candidate head at the time of this checkpoint: `46f2077d2cd2493d319da0272374eb5d5672f541`.
