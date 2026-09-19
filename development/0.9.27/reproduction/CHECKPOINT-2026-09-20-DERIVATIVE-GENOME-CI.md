# KiCom 0.9.27 DEV – derivative genome integration checkpoint

Date: 2026-09-20 (automation cycle). Branch: `work/kicom-0.9.27-pam`.

## Scope and preserved boundaries

This checkpoint records only verified GitHub DEV work. Production KiCom, Auth/Passkey, Update, Slack/Mail/Opera, production SQLite and external hosts/accounts were not mutated. No FreeOTP/TOTP or parent secrets were requested or copied.

The prior three-cycle checkpoint remains the baseline: the isolated R3 daughter candidate was not a born daughter because its real native `GENOME_STATUS` still exposed the historical `kicom-0.9.26-g25r3` release identity.

## New executable milestone

`development/0.9.27/reproduction/KiComDerivativeGenome.php` now constructs and verifies a DEV-only derivative genome identity from:

- exact parent genome `kicom-0.9.26-g25r3` / version `0.9.26`;
- pinned original R3 package SHA-256 `6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f`;
- an independently supplied Ed25519 daughter public key fingerprint;
- a fresh host nonce hash.

The derived child ID is content-bound to those inputs. Verification rejects a different key, package substitution, textual child-ID substitution, binding tamper, authority escalation and stale `g25r2` parent identity. The derivative document explicitly keeps `authority_granted=false` and `deployment_permitted=false`.

`test-derivative-genome.php` performs 15 positive/negative executable checks, including deterministic identity for identical host inputs and a distinct identity for a fresh nonce.

## Independent CI verification

A dedicated workflow was added at `.github/workflows/test-0927-derivative-genome.yml`. It checks out the exact triggering SHA, verifies the original R3 ZIP digest and unchanged `source/0.9.26-r3/MANIFEST.sha256`, syntax-checks/runs the derivative tests, and reruns existing lineage and birth-quarantine tests.

Verified GitHub Actions result:

- workflow: `KiCom derivative genome integration`
- run: `35472219313`
- job: `105975117235`
- exact tested SHA: `048ae285be5c1d6c3ca201a5937832d9be988a3a`
- conclusion: `success`
- all workflow steps completed successfully, including unchanged R3 source/package verification, derivative binding test, and existing birth-quarantine tests.

This is a real executable DEV advance, but **not yet proof of a native daughter birth**. The derivative document exists and is independently CI-tested; the next requirement is to integrate it into an isolated derivative runtime such that the actual local KCL `GENOME_STATUS` is generated from and verifies the new protected child identity without changing or weakening the original R3 release/genome verifier.

## Next safe cycle

1. Build an isolated derivative runtime/package adapter around a copy of the verified R3 source, never editing the original R3 tree.
2. Have an independent host-side step generate daughter key + nonce and materialize the derivative genome into that isolated tree only after package/manifest verification.
3. Start that isolated runtime under the daughter principal and require its actual KCL `GENOME_STATUS` to report the derivative child ID and key/package binding.
4. Cross-check the KCL result with an independent host-owned attestation; fail closed on historical parent ID, malformed/missing binding, substituted key/package, or verifier drift.
5. Re-run original release/genome verification and the complete existing membrane suite at the exact tested SHA before any stronger birth claim.

Native child SQLite operational state, independent LKG/recovery, non-bypassable network boundary and real bidirectional channel parity remain separate later milestones. No production deployment or external communication is authorized by this checkpoint.
