# KiCom sandbox daughter-cell Living upgrade ready — 2026-09-17

## Live facts

- Parent KiCom live baseline: 0.9.15 / genome g16, healthy/trusted/LKG.
- `/dev/` full restore is live again; `doctor.php` reports DEV core files present and sandbox deployment resource available/writable.
- A real managed child already exists at `https://sandbox.rurtalbahn.info/kicom/`.
- Live child state is `active`, generation 1, with stable local identity and signed federation lineage.
- The live child is still the earlier thin federation runtime; its public status does not yet expose `living_ready`.

## Implemented candidate upgrade

The candidate now contains a bounded managed upgrade from the existing thin child to the complete intrinsic daughter architecture.

The upgrade:

- is fixed to the allowlisted `sandbox` test resource and `/kicom/` child;
- refuses unmanaged targets and managed-file drift;
- preserves `var/`, node identity, signing key, parent/root lineage and mutable child state;
- installs only the fixed Living runtime set (`CellLiving`, runtime schema, doctor and required child runtime files);
- materializes local canonical memory, isolated workspace, observer, genome/LKG, immune recovery and evolution candidate substrate;
- verifies `living_ready=true` after the write;
- runs a signed federation tick smoke test;
- rolls back runtime files and newly-created Living state if verification or federation fails.

The DEV browser exposes one explicit control: `Living-Unterbau aktualisieren`, backed by `UPGRADE_SANDBOX_LIVING` on the POST-only DEV endpoint. The GET agent bridge still cannot execute sandbox expansion or upgrade actions.

## Validation

Candidate head after implementation: `a30464a9c4e599231874a427ab13e7abc9b55cf1`.

GitHub Actions on that head:

- KiCom Expansion Cell v1 run 35211801566: SUCCESS.
- KiCom DEV zone checks run 35211801559: SUCCESS, including install-bundle artifact creation.
- KiCom DEV Observer v1 run 35211801680: SUCCESS.

DEV install artifact: `kicom-dev-zone-v1`, artifact id `10493015149`.

## Next controlled live step

1. Install the full code-only DEV restore from the green artifact so `/dev/` receives the new upgrade operation/UI while persistent DEV state remains untouched.
2. Open/reuse a Passkey DEV session.
3. Press `Living-Unterbau aktualisieren` once.
4. Verify public child `status.php` reports `living_ready=true`, all intrinsic subsystems ready, drift=0 and LKG OK.
5. Verify child doctor and signed federation tick.

Do not create a second child and do not replace or clone the existing child identity.

No OTP, passkey private material, DEV bearer/session token, federation private key or absolute hosting path is stored in this checkpoint.
