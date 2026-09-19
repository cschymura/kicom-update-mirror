# KiCom Membrane – signed staging attestation boundary

Date: 2026-09-19. Repository: cschymura/kicom-update-mirror. Branch: work/kicom-0.9.27-pam.
Predecessor: development/0.9.27/membrane/CHECKPOINT-2026-09-19-RECONCILIATION-JOURNAL.md.

## Live and canonical starting point (read-only)

BOOTSTRAP, PROJECT_STATE, ARCHITECTURE, PROTOCOL, DECISIONS, CHANGELOG and NEXT, along with GENOME_STATUS, SQLITE_STATUS, UPDATE_STATUS and SLACK_STATUS, were read before code changes. Live KiCom still reports 0.9.26/g25r3, healthy/trusted/LKG OK, 0 drift or unknown, SQLite WAL quick_check=ok, no pending update. SLACK_STATUS continues to report configured=false, bot token=false and signing secret=false, inbound app_mention in the original #kicom channel. No live message, session, filesystem, production DB, routing, Slack app or protected authorization was changed. Historical g25r2/NEXT wording was not overwritten without a demonstrably authorized revision-preserving canonical memory action.

## New, actually implemented DEVELOPMENT-only module

KiComMembraneStagingAttestation.php is a strictly verification-only, bounded detached Ed25519 statement verifier. It validates an exact 10-field canonical statement, fixed version/domain separation, explicit signing-key ID, workspace, conversation, event SHA-256, raw-message SHA-256, unique-challenge SHA-256, observation SHA-256, claim kind and time within a five-minute window. It verifies a detached signature against a provided verification-only public key, checks each claim against independently provided expected receipt/context fields and a claim-kind allowlist, and returns only a minimal set of hashes/type. No private key, Slack API, signing endpoint, network, dispatch, replay, completion, permission or session method exists inside the verifier.

**Meaning of a positive result:** Integrity of a claim with respect to the *supplied* public key and bound expected values. This implementation DOES NOT prove that the supplied public key comes from a separate protected trust root; it DOES NOT prove that the signer really observed a Slack provider delivery, and it DOES NOT verify any KiCom-runtime receipt. Slack's normal Web API response is not itself an Ed25519-signed response; a future separately protected staging observer would have to attest a verified response without transferring its signing key into KiCom's writable domain. Even a fully matching signature returns independent_anchor_authenticated_here=false, slack_provider_effect_verified=false, kicom_runtime_ack_verified=false, action_authorized=false, delivery_authorized=false, automatic_replay_allowed=false. This is not permission to retry/send a message.

test-staging-attestation.php generates synthetic test-only signing keys in memory, covers 24 positive/negative cases: valid staging signature; no private message/destination exposure; mutated payload/claim, claim-kind downgrade, wrong observation and nonce, other workspace/conversation/event, incorrect key, invalid base64/missing key, stale/future timestamp, malformed or injected fields, missing context, unauthorized claim kind, forged signature and no action/signing surface. Both new files are linted and tested by the existing .github/workflows/test-0927-membrane.yml. No credential file or real Slack message was generated.

Upstream implementation reference: PHP sodium_crypto_sign_verify_detached manual: https://www.php.net/manual/en/function.sodium-crypto-sign-verify-detached.php . The key pair/signatures in the unit test are TEST FIXTURES, not KiCom's identity or proof of an independently hosted signer.

## Exact verified CI evidence

Workflow RUN_ID=35450872101, job ID=105917673345, tested/trigger commit SHA=1c6823265026255b0abbe76c255f384c35e01c5d, all jobs completed with conclusion=success. The exact tested native R3 archive/source-manifest, PHP lint, all existing communication/receipt/reconciliation/OS-principal tests and the new test-staging-attestation.php passed.

Previously 226 isolated membrane checks, plus MEMBRANE_ATTESTATION_TESTS_PASSED=24 = **250 successful isolated DEV checks**. CI: https://github.com/cschymura/kicom-update-mirror/actions/runs/35450872101 .

This is not 250 production or live Slack end-to-end tests. The documentation-only checkpoint commit is not a separate tested code SHA; run new CI for any subsequent executable change.

## Next safe, non-production work

1. On a separately administered, isolated staging host, establish a pinned verification public key with its signing private key wholly outside KiCom's writable/rollback domain. Verify that KiCom's runtime cannot swap the key, run the signer, invoke direct unrestricted network egress or rewrite receipt evidence.
2. Bind a *provider-authenticated* staging API observation to the exact request/nonce/message/event/channel and record verified observation provenance without granting permission. Verify the actual Slack effect and a distinct KiCom-runtime ACK; an attested local claim is insufficient. An unsuccessful or ambiguous external outcome stays NEEDS_RECONCILIATION and must not blindly replay.
3. Only after independent host and egress controls, safe staging credentials and an expressly authorized test group/destination can real Slack send/receive compatibility be measured. Current production Slack configured=false and its original #kicom channel must remain unchanged. Existing Auth/Passkey, KCL/DEV/Mail/Opera/Update communication, package/Genome verifier, SQLite backup, healthcheck, LKG and rollback remain intact.
4. For internal DEV do not request FreeOTP or claim Slack/PAM telemetry extends KiCom's action authority. No membrane/0.9.27 live promotion without separate protected production approval and complete communication parity.
