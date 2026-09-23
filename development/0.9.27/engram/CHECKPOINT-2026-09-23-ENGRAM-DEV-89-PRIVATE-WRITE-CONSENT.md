# MIRAGE DEV-89 — actual private SQLite receipt lookup required for real writes
Date: 2026-09-23. Development ONLY; **no native release ZIP or production installation**.

## Concrete problem closed at the development boundary
DEV-88's provenance verifier accepted `server_provenance.verified=true` together with a syntactically plausible `consent:<64 hex>`. Although this value was intended to come from the first-party server, the verification class did NOT independently prove the referenced approval existed. A future faulty bridge could have passed a fabricated receipt. DEV-89 makes this failure mode fail closed: real-memory provenance requires a separately injected FIRST-PARTY private-consent lookup; a missing/false lookup is rejected. Synthetic test provenance still works only in explicitly synthetic runtime.

## Actual committed code
- `dev88/KiComEngramVerifiedWriteProvenance.php`: the real-memory branch now requires `$trustedReceiptLookup` return exactly true for the current owner+namespace+token fingerprint+kind+receipt. Neither client-supplied `verified=true` nor an opaque-looking `consent:` string alone qualifies.
- `dev76/KiComEngramCanonicalMutationService.php`: accepts the trusted callback separately in its server-side constructor, not as an MCP argument; without one, all REAL provenance writes fail closed. The DEV-76/87/88 existing synthetic mutation E2E remains unchanged and testable.
- `dev89/KiComEngramPrivateWriteConsentReader.php`: a READ-ONLY PDO SQLite lookup joining an explicitly approved, unrevoked, owner-/namespace-/passkey-bound PRIVATE consent row to a *current, nonrevoked, unexpired* OAuth access-token row with explicitly issued `engram.write` scope. A preexisting engram.read token never gains write. There is NO DDL, token issue, user login, approval or network endpoint in this class.
- `dev89/test_private_consent_reader.php`: synthetic real-PDO SQLite fixture of the current native OAuth token-row columns + proposed separate operator-owned `mirage_oauth_write_consents` table. Covers absent schema, read-only token denial, approved write, owner/namespace/receipt/kind/bearer mismatch, revoke/expiry/passkey rebinding, combined scope, missing callback/fake approved flags, actual SQLite integrity.
- `dev88/test_verified_provenance.php`: adds explicit false-positive prevention and read-only lookup behavior tests.
- `.github/workflows/test-kicom-dev89-private-write-consent.yml`: deterministic PHP 8.2 ext-pdo_sqlite positive/negative and previous DEV-76/87/86 regressions.

## Actual independently checked result
GitHub Actions run **35831794138**, job **107085870410**, completed **success** with genuine PHP 8.2 ext-pdo_sqlite on branch `work/kicom-engram-dev89-real-consent-gate`. Logs confirm 20/20 DEV89 private-consent SQL checks, 17/17 provenance-contract checks, 13/13 DEV87 schema checks, 10/10 DEV76 canonical mutation checks and 18/18 DEV86 two-connection durable grant checks: **78/78 real native PDO SQLite SYNTHETIC assertions passed, zero failed**, and four PHP syntax checks passed.
https://github.com/cschymura/kicom-update-mirror/actions/runs/35831794138

## Critical distinction: still NOT live
This is an actual independent SQLite RECEIPT VALIDATION COMPONENT, not proof the original KiCom administration already issues or stores real `engram.write` consent. The new `mirage_oauth_write_consents` SQL schema exists ONLY in the synthetic CI fixture. Production OAuthTransactions::verify in KiCom 0.9.37 is READ-ONLY and the current MCP protocol advertises ONLY engram_search. The original PasskeyConsent UI approves READ only. No live passkey/write OAuth issuance, current-owner revocation hook, native MCP write routing, or browser/client write E2E has been implemented.

NEXT in ONE consolidated release: operator-only additive private schema preparation/backup for existing active OAuth and Engram DB; actual first-party passkey-backed `engram.write` consent creation/revocation and OAuth token issuance/verification (including combined scopes where genuinely approved), current owner revocation check; trusted native MCP write/update/archive dispatch with source binding and existing read preserved; DEV82/83/84/85 OAuth continuity and ui_locales, real PHP PDO+HTTP regressions; exact original Genome/MANIFEST/update/recovery/rollback; ONE final package, separate install permission, ONE ChatGPT new-chat/iPhone live test. On a failed live test stop automated auth redesign and review jointly with Christoph.

No user credentials, true memory texts, passkeys or tokens were committed; all fixture values synthetic.
