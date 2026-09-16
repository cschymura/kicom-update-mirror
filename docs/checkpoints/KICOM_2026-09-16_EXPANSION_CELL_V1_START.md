# KiCom Expansion Cell v1 — implementation start

Recorded: 2026-09-16
Status: ACTIVE CANDIDATE WORK
Branch: `candidate/expansion-cell-v1`

## Durable goal

KiCom shall be able to expand onto a user-authorized target domain/webspace from a parent node. The operator supplies a destination and temporary FTP/FTPS credentials. KiCom creates a one-time expansion transaction, uploads a child-cell package into a `kicom/` directory, probes the target, enrolls the child with the parent, activates a signed parent/child trust relationship, and then stops depending on FTP.

After activation, the parent cron acts as a federation scheduler and sends signed HTTPS ticks to active child cells. The federation is represented as a rooted tree. Recursive expansion is a separately granted capability, never an automatic default.

## Human interaction target

Normal operator flow should eventually be only:

1. `Expand to <domain>`.
2. Provide temporary FTP/FTPS host/user/password and web-root path.
3. KiCom performs deployment, probe, enrollment and activation.
4. Operator may remove/disable the temporary FTP account.

No repeated token relay should be required after the cell joins the federation.

## Security and scope invariants

- No scanning for targets. Every destination must be operator supplied.
- No propagation without destination credentials or an explicitly delegated expansion capability.
- FTP credentials are transient parameters only and are never persisted in Git, memory, logs, registry or child configuration.
- Prefer FTPS. Plain FTP requires an explicit transport choice.
- Parent private keys are never copied to a child.
- Child creates its own Ed25519 key pair locally.
- Enrollment token is one-time; parent persists only a verifier and erases it on successful enrollment.
- Federation messages are signed and bind sender, receiver, operation, timestamp, message ID and payload hash.
- Child federation membership does not imply production deployment, recovery, kernel, auth administration or secret-store authority.
- Deployment is additive into the dedicated `kicom/` directory; v1 does not delete unrelated target files.

## Implementation completed at this checkpoint

- Canonical expansion/federation project goal saved in `docs/goals/KICOM_EXPANSION_FEDERATION.md` and JSON companion.
- Protocol primitives implemented in `experimental/expansion-cell/ExpansionProtocol.php`.
- Parent-side one-time enrollment registry implemented in `experimental/expansion-cell/ExpansionRegistry.php`.

## Next implementation slices

1. Local child identity/runtime with persistent node identity and replay guard.
2. Bounded FTPS/FTP bootstrap deployer that uploads only a supplied cell package into `kicom/`.
3. Signed cron/federation relay envelopes and child tick handling.
4. Minimal child package builder/runtime endpoints.
5. End-to-end self-test covering prepare -> deploy marker -> descriptor/proof -> enrollment -> signed tick -> signed result.
6. Parent KiCom integration only after candidate tests are green.

## Project-memory rule

Treat this expansion/federation objective as a durable KiCom project goal. Preserve its history and checkpoints. Superseded implementations are archived rather than destructively deleted unless the operator explicitly requests deletion.
