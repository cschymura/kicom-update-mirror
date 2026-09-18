# KiCom mail activation checkpoint — 2026-09-18 09:07 CEST

Status: PAUSED_AT_EXTERNAL_TRANSPORT_TOOL_FAILURE

## Live baseline
- Expected live: KiCom 0.9.15 / g16.
- A prior isolated 0.9.16 server build was started from the exact live source.
- Build id observed before transport loss: `bf91ba0bfc9c7b08d285`.
- First bootstrap patch (loader call) succeeded.
- Second large patch (trusted module loader body) was committed through the transaction buffer successfully.
- Session token was subsequently lost/invalidated during a follow-up request; do not assume the old session is reusable.

## Mail candidate
- Mail identity: kicom@rurtalbahn.info
- IMAPS: w021efff.kasserver.com:993 SSL/TLS
- SMTPS: w021efff.kasserver.com:465 SSL/TLS
- Secret ref: KICOM_MAIL_PASSWORD
- Secret must never be written to GitHub, canonical memory, logs, or chat.
- Runtime transport, secret store, loopback verifier and CI are implemented and green on candidate/expansion-cell-v1.

## Resume sequence
1. Re-open a fresh KiCom autonomy session with a fresh OTP only when the HTTP transport is reachable.
2. Re-read BOOTSTRAP/GENOME_STATUS and confirm 0.9.15/g16 live healthy/trusted/drift=0.
3. Do not trust or continue an orphaned build unless the session-bound status can be read safely; otherwise start a fresh isolated build from exact live source.
4. Apply 0.9.16 trusted-module-bootstrap patches with transaction buffering for long replacements.
5. Prepare/finalize 0.9.16; pass normal verifier; install only through the existing self-update path with LKG snapshot/healthcheck/rollback.
6. Verify live 0.9.16/g17 healthy/trusted/drift=0.
7. Build 0.9.17/g18 with genome-bound mail modules and modules.json.
8. Provision mailbox password only at the protected secret boundary.
9. Run IMAPS+SMTPS probe and exact Message-ID loopback.
10. Record successful mail evidence without credential material.

## Safety
- No OTP retained here.
- No mailbox password retained here.
- No trust/TLS/verifier weakening.
- No manual ZIP shuttle.
