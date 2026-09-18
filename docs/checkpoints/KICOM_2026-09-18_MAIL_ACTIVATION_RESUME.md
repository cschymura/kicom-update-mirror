# KiCom mail activation checkpoint — 2026-09-18

Status: WAITING_FOR_FRESH_OTP_AFTER_TRANSPORT_ERROR_BODY_LOSS

## Live baseline reverified through TinyFish
- Live KiCom: 0.9.15 / genome g16.
- Canonical PROJECT_STATE reports genome healthy/trusted, LKG OK, drift=0, unknown=0.
- Exact trusted source manifest re-read successfully.
- Exact live lib.php SHA-256 remains `9e4616c95839d0bdc133bd0c347244e82c9703f3bb29018b7fa6b61efa997b14`.
- UPDATE_STATUS: no pending version, pull+push enabled, primary feed + GitHub mirror configured.
- No production mutation performed in this attempt.

## Mail candidate
- Identity: kicom@rurtalbahn.info
- IMAPS: w021efff.kasserver.com:993 SSL/TLS
- SMTPS: w021efff.kasserver.com:465 SSL/TLS
- Secret ref: KICOM_MAIL_PASSWORD
- Runtime transport, secret store, loopback verifier and CI are implemented on candidate/expansion-cell-v1.
- No mailbox password is stored in GitHub, canonical memory, logs or chat.

## 0.9.16 build attempt
A fresh isolated build was created from the exact live 0.9.15 source.

Build id:
`4cf5bd6924046a51ebfd`

Successfully applied inside the isolated build:
1. trusted-module-loader invocation after living.php
2. trusted-module-loader implementation via inert transaction buffer

The build is non-executable and production remains unchanged.

## Why the session was lost
After the transaction-buffer commit, an intentional optimistic-concurrency conflict request was used only to learn the resulting lib.php SHA. KiCom returned HTTP 422 with the rotated next token in its KCL body. TinyFish fetch_content reports 4xx as an error and does not expose the response body, so the rolling token became unavailable.

This is a transport/client behavior mismatch, not a KiCom runtime failure.

## Corrected resume strategy
Do NOT intentionally create 4xx responses through TinyFish.

On the next fresh autonomy session:
1. reverify BOOTSTRAP + exact live source manifest
2. start a fresh isolated 0.9.16 build (do not rely on the session-bound orphaned build)
3. apply small patches directly
4. apply long build patches through AUTONOMY_COMPACT_BATCH, because successful batch responses include each resulting sha256
5. carry the returned sha256 forward for the next exact-base patch
6. release_prepare -> finalize through the normal verifier
7. prepare exact protected install binding
8. request one fresh current-counter FreeOTP only for the critical install execute
9. verify live 0.9.16/g17 healthy/trusted/LKG, drift=0, unknown=0
10. continue to the mail-module activation path
11. provision mailbox password only through a protected server-side secret input
12. run IMAPS + SMTPS auth probe and exact Message-ID loopback
13. record only non-secret success evidence

## Safety
- No OTP value retained here.
- No session/token retained here.
- No mailbox password retained here.
- No verifier, TLS, trust or authorization boundary weakened.
- No manual ZIP shuttle.
- No live production change occurred during the failed attempt.
