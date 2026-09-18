# KiCom project memory — first live expansion cell active

Date: 2026-09-16
Canonical branch at capture: `candidate/expansion-cell-v1`

## Persisted facts

- Live KiCom parent remains the canonical authority at `https://kicom.rurtalbahn.info`.
- Live parent version reported by BOOTSTRAP/DESCRIBE: `0.9.15`.
- KiCom DEV Observer v1 is installed live and readable at `/dev/doctor.php`.
- Doctor status before and after first live expansion: `DEV_DOCTOR_OK`.
- Doctor verifies the required DEV/Expansion source inventory, PHP runtime and the allowlisted `sandbox` deployment resource.
- `sandbox` is class `test`, available, writable and has a healthcheck.
- First successful live expansion operation id: `op-e51bdcb614bfe257`.
- The first child cell is live at `https://sandbox.rurtalbahn.info/kicom`.
- Child status endpoint reports `CELL_STATUS`, `state=active`, `generation=1`.
- Child cell id: `cell-004ebc3e24949a0c3e7a16da`.
- Root/parent cell id reported by the child: `cell-efb0b82c5bfa6bab127eff7e`.
- Child capabilities reported live: `federation.tick`, `status.report`.
- Activation completed at `2026-09-16T20:54:51+00:00`.
- Parent observer recorded final activation code `EXPANSION_ACTIVE_WITH_TICK_WARNING`.
- The tick warning does **not** mean activation failed. Root cause: `cell-runtime/federation.php` treated a valid signed federation envelope as an error because signed envelopes intentionally have no top-level `ok` boolean. This made the endpoint return HTTP 422 even though the child generated a signed `FEDERATION_TICK_RESULT` envelope.
- Candidate fix committed: valid signed federation response => HTTP 200; actual error arrays => HTTP 422.
- The architectural rule from this incident: no more troubleshooting by blind FTP package iteration. DEV must expose bounded, sanitized observability and KiCom should use its own deployment/update paths for subsequent repairs.

## Immediate next task

Complete the first parent-to-child repair/update path without manual FTP:

1. parent identifies an active managed child under the allowlisted `sandbox` resource;
2. parent verifies the child/runtime markers and exact current target hash;
3. parent atomically replaces only the managed child runtime file(s) for the federation-response fix;
4. parent probes child status;
5. parent sends a signed `FEDERATION_TICK` and verifies a signed `FEDERATION_TICK_RESULT`;
6. observer records the repair operation and result;
7. no production target, OTP relay, credential disclosure or arbitrary filesystem path is introduced.

## Operator workflow rule

For DEV/test work: passkey-authenticated DEV should remain the normal human gate. Routine test-target file placement and repair should be performed by KiCom itself. Manual FTP is a recovery mechanism, not the development workflow.
