# Mirage Engram DEV-61 — Shared-host operator decision is an explicit runtime policy, not fabricated isolation

Date 2026-09-21. Christoph's latest decision: the existing All-inkl shared webspace is acceptable for this private KiCom Engram project, no renewed hosting-migration discussion. ChatGPT Work/live update is deferred until 2026-09-22 **00:00 Europe/Berlin**; the ONE reminder was already created earlier and must not be duplicated. Continue GitHub code work now. Starting branch SHA `c78cb17887dbd561ddc7f89a9b70c5b4e9e0045f`, operator-installed **KiCom 0.9.32**, checked complete **0.9.33 candidate** remains PREPARED but NOT installed. No live admin/server mutation in DEV-61.

## Actual code/changes

- `KiComEngramHostingPolicy.php`: a separate decision state `host_isolation_verified=false` together with **all** of `operator_accepts_shared_host_risk=true`, precise mode `shared-host-explicit-operator-acceptance/v1`, `hosting_policy_source=protected-operator-host-config`, `hosting_policy_owner=mirage-owner`, an explicit `known_limitation=shared-php-uid-not-verified`, valid UTC acknowledgment and host-evidence SHA256. The helper never reports shared hosting as isolated; an isolated host and a simultaneously accepted shared-host configuration are contradictory and denied. Policy is evaluated only after ORIGINAL KiCom's protected host JSON is loaded, never from OAuth/MCP request fields.
- Updated `KiComEngramOAuthHttp.php` and `KiComEngramMcpRuntimeGate.php`: substitute the strict hosting-policy evaluator for their previously hard-coded `host_isolation_verified===true` requirement. All other requirements remain: owner/current Passkey mapping, operator approval, OAuth/connector enablement, unexpired token, distinct activation DB active state, exact owner/host binding and revoked-owner checks. A shared-host decision alone does not activate memory or make OAuth discoverable.
- Updated `KiComEngramActivationReadinessGate.php` for a **distinct** shared-host evidence shape/status `SHARED_HOST_RISK_EXPLICITLY_ACCEPTED_API_STILL_INACTIVE` that carries the precise known limitation and operator risk acceptance, without masquerading as `HOST_ISOLATION_EVIDENCE_COMPLETE_API_STILL_INACTIVE`. All original current-owner, evidence freshness and no-premature-connection checks remain. `KiComEngramActivationTransaction.php` requires a new **separate operator approval purpose** `activate-private-engram-shared-host`; a prior ordinary isolated-host approval cannot silently activate shared-host memory. No approval or nonce was minted on the real server.
- Exact `0.9.33` API router still contains its own isolation-only preflight. Prepared source patch `DEV61_0933_SHARED_HOST_API_ROUTER.patch` substitutes the same hosting-policy gate and loads the new helper in that route. Applied the EXACT replacement once to a copy of `api.php` from the actual previous 0.9.33 ZIP in the model container; local PHP 8.4 lint passed. **This source patch is NOT integrated in the older published PREPARED 0.9.33 ZIP, and must be included in a subsequent native build**. Do not claim current package/plugin already allows the shared-host policy.

## Verification (synthetic, not operator live)

Dedicated GitHub Actions final tested commit **`a988250a4e21c0028a3bfb66875e2f37deac2d53`**, run **35602753863**, job **106342523516**, **SUCCESS**, 7 PHP syntax checks and test suites:
- **48/48** shared-host runtime policy and OAuth/MCP authorization assertions,
- **21/21** shared-host activation/readiness and separate-approval transaction assertions,
- **15/15** original isolated-host readiness regression,
- **16/16** original isolated-host activation transaction regression,
- **36/36** existing OAuth HTTP tests, and
- **37/37** existing MCP JSON-RPC protocol tests.

Original immutable KiCom-R3 package/manifest verification was included. Run https://github.com/cschymura/kicom-update-mirror/actions/runs/35602753863 . Source-only exact-api route patch `DEV61_0933_SHARED_HOST_API_ROUTER.patch` was committed **after** this CI run as SHA `0761a6f97cecf4dc8bee0d8a0f9e0011820e878e`; local lint of that exact route transformation was successful, but it is NOT proven by the aforementioned GitHub run because it is a patch-only file.

## Exact remaining execution sequence

1. After the agreed 00:00 time, use authorized Work browser/install actions to obtain operator approval for the **existing 0.9.33 create-only DB admin functionality**; if integrating DEV-61 into a native successor, use strict genome+manifest lineage from the actual operator-installed version and original KiCom verifier. DO NOT edit the already operator-installed 0.9.32 server or assume the older PREPARED 0.9.33 ZIP already has shared-host logic.
2. Connect the genuinely protected existing host policy and genuine passkey-bound owner approval to the two private databases; owner must explicitly authorize activation via the separate shared-host-purpose path and any actual one-time OAuth connector grant. Trust comes from the server's private config + operator action, not a request-provided flag; do not label same-UID hosting isolated.
3. A real independent ChatGPT Work plugin connection and fresh-chat recall of synthetic data remain UNPROVEN. No actual All-inkl file write, personal-memory transfer or live token issuance happened here. Work/plugin linking needs the user's separate explicit UI action. Do not ask to repeat the prior successful real SQLite/passkey-enrollment tests. Do not spawn another midnight reminder or imply unattended work runs until then.

This checkpoint documents practical work toward an accepted existing shared host rather than a recommendation to migrate hosting.
