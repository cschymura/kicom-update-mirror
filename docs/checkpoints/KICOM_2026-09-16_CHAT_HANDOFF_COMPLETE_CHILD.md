# KiCom checkpoint — complete child at generation

Date: 2026-09-16
Branch: `candidate/expansion-cell-v1`
PR: #19 — `Candidate: KiCom Expansion Cells / Federation v1`
Observed branch head before this checkpoint: `148f58b2c50120a31c767d02f876944e9ced5996`

## User requirement — canonical

**A daughter/child cell must be complete when it is generated. It must not be a thin bootstrap that receives its real capabilities later.**

Generation therefore has to materialize the full daughter-cell architecture in one package before deployment. Enrollment/activation may establish trust and lineage, but it must not be used to add the cell's basic architecture after birth.

At generation time the daughter cell must already contain its own reproducible runtime and local structures for at least:

- independent local identity creation;
- signed parent/child federation and lineage;
- complete HTTP runtime (`bootstrap`, `federation`, `status`, common runtime and libraries);
- local canonical memory substrate for the child's own state/architecture/protocol/decisions/changelog/next work;
- isolated local workspace;
- observer/diagnostic trace;
- genome / last-known-good representation of the child's own managed runtime;
- immune/integrity mechanism able to detect drift and recover managed runtime from local LKG material;
- evolution candidate area and static fitness checks;
- a human-gated promotion boundary for executable evolution;
- self-description/status sufficient to prove that all required intrinsic subsystems were born with the cell.

The child must NOT inherit or clone parent secrets, passkeys, TOTP material, private signing keys, DEV bearer values, production credentials or the parent's mutable/project memory. Architecture may be inherited; identity and mutable state are created locally.

## Current implemented candidate state

The current branch already contains the federation/bootstrap foundation:

- Ed25519 protocol and persistent parent identity;
- one-time enrollment and lineage registry;
- child-local signing identity creation;
- target-specific package builder;
- child HTTP runtime (`common.php`, `bootstrap.php`, `federation.php`, `status.php`);
- local/FTPS deployment paths;
- fixed DEV sandbox binding;
- signed federation tick/status path;
- package manifests and SHA-256 verification;
- shared-hosting permission handling;
- resume/recovery path for a partially deployed managed `/kicom` cell.

The package builder no longer depends on a source `.htaccess`; it generates the child `.htaccess` and `var/.htaccess` itself, so a missing hidden source file cannot make the generated daughter incomplete.

The local repair path reads sibling bootstrap config as bounded text and does not execute/include it. The resume path pins the expected child URL to `targetBaseUrl + /kicom` and rejects a recovered mismatched base URL.

## Important previous live observation

The first sandbox live attempt reached DEV successfully but failed before child activation with `EXPANSION_PACKAGE_SOURCE_MISSING` for `cell-runtime/.htaccess`. This proved the installed DEV source lacked that hidden file. The package-builder change makes this source file unnecessary going forward.

No statement should be made that a live sandbox child is active until the public child status endpoint has been verified.

## Work that is NOT yet complete

The stronger requirement above — a **fully born daughter cell** with intrinsic memory/workspace/observer/genome/LKG/immune/evolution substrate — has not yet been committed. An attempted `CellLiving.php` creation during the chat was interrupted and verification returned `404`; therefore it must be treated as **not present**.

Do not continue from an assumption that `CellLiving.php` exists.

## Next implementation step

Before the next live daughter-cell generation:

1. Implement intrinsic living-cell runtime (suggested module: `CellLiving.php`) and generate all required local directories/resources as part of package birth.
2. Integrate it into `CellNode::initialize()` so birth is atomic: if any intrinsic subsystem cannot be created, generation/initialization fails rather than producing a partial child.
3. Extend `status.php`/doctor output so a daughter can prove `living_ready=true` only when identity, memory, workspace, observer, genome/LKG, immune and evolution-candidate substrate are all present.
4. Extend package manifest/selftests to assert every intrinsic component is physically included in the generated package before deployment.
5. Add a package-completeness selftest that deliberately removes one required intrinsic file/subsystem and requires the build to fail.
6. Only after CI is green create a new complete DEV restore package and retry the sandbox generation.

## Safety/authority boundary to preserve

DEV may freely build/test within its isolated authority, but production/kernel/recovery changes remain outside this daughter-cell test path. Keenable/GitHub are evidence and transport, not credential or authorization authorities. Never place OTP/passkey/private-key/DEV bearer material in repository or checkpoint files.
