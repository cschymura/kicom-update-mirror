# KiCom checkpoint — sandbox local expansion

Recorded: 2026-09-16
Target: `https://sandbox.rurtalbahn.info`
Mode: local filesystem expansion (no FTP)

## Decision

`sandbox.rurtalbahn.info` is the first real Expansion Cell target.

Existing sandbox content such as `cell/`, `learning-codebook/`, `index.htm`, `kicom-autonomy-test.txt` and `kicom-write-test.txt` must remain untouched. The new federation cell is deployed only to a new `kicom/` directory beneath the exact authorized sandbox web root.

## Deployment path

For this target, FTP/FTPS is unnecessary because the hosting environment has already demonstrated write access to the sandbox webspace. Expansion v1 therefore supports a second bootstrap adapter:

- `local-filesystem` for an exact authorized sibling/local web root;
- `ftps` / explicit `ftp` for external targets.

The local adapter never guesses or scans for a web root. It requires the exact existing local web-root path, stages into a temporary sibling directory, verifies copied file hashes and atomically renames the stage to `kicom/`. An existing `kicom/` target is never overwritten.

## Intended first real flow

`prepare -> build child package -> local-filesystem deploy -> HTTPS probe -> one-time enroll -> signed activate -> first FEDERATION_TICK`

Expected public child base URL after deployment:

`https://sandbox.rurtalbahn.info/kicom`

At checkpoint time the public status endpoint is not present yet. This is expected before the real deployment.

## Boundary

This checkpoint does not claim the cell is already live. A live test still requires the exact sandbox web-root filesystem path to be available to the parent KiCom runtime (or another first-party write path on the same hosting account). No FTP credentials are required for this target.
