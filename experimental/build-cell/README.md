# KiCom Build Cell v1

Build Cell v1 is the next development step after the practical DEV zone. It moves code execution away from the live KiCom host and into a disposable GitHub-hosted runner, so KiCom development can use real PHP/Composer/test/build tooling without giving those tools production authority.

## Why this is a real isolation improvement

The existing live host is shared hosting and is not an OS sandbox. Running arbitrary generated PHP/tests there would let test code reach resources outside a nominal project directory. Build Cell therefore uses a separate ephemeral CI machine as the execution boundary.

The cell receives repository code only. It receives no KiCom production bearer, FreeOTP/TOTP secret, passkey private material, live session token, hosting credential, or deployment secret. Repository permissions are read-only.

## Pipeline

1. Checkout candidate source into a disposable GitHub runner.
2. Run the native verifier: bounded tree inventory, SHA-256 tree identity, PHP parser checks, JSON validation and secret-like file rejection.
3. If a root `composer.json` exists, validate it and materialize dependencies with `--no-scripts --no-plugins`.
4. Lint all project PHP.
5. If PHPUnit is present, run tests inside the disposable runner with a hard timeout.
6. Package the exact Git commit with `git archive`, calculate SHA-256 and attach the native verification report.
7. Upload the result as an expiring CI artifact.

## Security boundary

Build Cell may execute project tests because the runner is disposable and has no production credentials. It cannot deploy to KiCom, install a KiCom self-update, mutate production memory, touch recovery/kernel state, administer authentication, or read the production secret store.

GitHub-hosted runners have ordinary network access in v1. The protection is therefore **credential isolation**, not an egress firewall: there are no production secrets available to exfiltrate. A later Build Cell version may add a dedicated worker with explicit network policy if that becomes useful.

## Native verifier limits

- maximum 5,000 inventoried files;
- maximum 64 MiB total inspected content;
- maximum 4 MiB per inspected file;
- `.git`, `vendor`, `node_modules`, `dist`, and `.build-cell` are excluded from the native source inventory;
- `.env`, private-key-like files, and private key extensions are rejected;
- PHP is parsed with `TOKEN_PARSE` without executing the file;
- JSON must decode cleanly.

## Development model

Normal loop:

`edit in candidate branch -> Build Cell -> tests -> immutable artifact -> review -> separate production promotion`

This deliberately concentrates security at the production boundary while allowing broad freedom inside an isolated development runner.

## Status

Candidate only. This branch does not change the live KiCom production runtime by itself.
