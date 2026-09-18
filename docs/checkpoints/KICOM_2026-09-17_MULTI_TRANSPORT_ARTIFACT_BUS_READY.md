# KiCom checkpoint — Multi-Transport Artifact Bus ready

Date: 2026-09-17
Status: **candidate ready; DEV bootstrap not yet confirmed live**

## Goal

Make update acquisition independent from any single channel. Fast paths may fail without preventing slower known fallbacks from being tried.

## Architecture

Transport and installation are separated:

`known source -> acquire -> SHA-256 verify -> retained staging/archive -> existing KiCom updater -> snapshot/LKG/health/commit-or-rollback`

The transport layer has no direct production-tree write authority.

## Source classes

Implemented/modelled:

1. configured HTTPS channel/feed;
2. configured HTTPS package URL template;
3. GitHub mirror as HTTPS channel;
4. independent web mirror slot (future Daisy/ChatGPT Sites mirror);
5. local update inbox;
6. FTPS external adapter;
7. plaintext FTP explicit break-glass only;
8. existing browser/manual upload as request-only fallback;
9. recovery/break-glass as request-only fallback.

All enabled automatic sources are tried in priority order. A failure does not terminate the chain until all eligible automatic sources fail.

## Security properties

- expected SHA-256 mandatory;
- HTTPS hosts explicitly configured/pinned;
- redirects remain inside configured allowed host set;
- no arbitrary remote fetch;
- transport does not install into production;
- FTPS credentials supplied only at runtime and never persisted in source configuration/history;
- plaintext FTP requires per-source explicit opt-in;
- local inbox original is copied, not consumed;
- acquired artifacts and handoff receipts retained;
- source state history append-only.

Source states: `UNKNOWN`, `AVAILABLE`, `UNAVAILABLE`, `DEGRADED`, `FORBIDDEN`, `STALE`.

## Existing updater bridge

`ArtifactKiComBridge.php` is a narrow adapter to the established `kicomReceiveSelfUpdatePackage()` receiver. It does not duplicate package inspection, risk classification, snapshots/LKG, installation, health checks or rollback.

## DEV bootstrap

`DevArtifactImporter.php` remains deliberately `/dev/`-only. It is a bootstrap transport, not the central production bus. It supports SHA-bound HTTPS verify/install, archives replaced DEV files before overwrite, and retains failed/new rollback evidence.

The DEV UI exposes URL + SHA-256 controls through `dev-artifact.php` after the bootstrap package is deployed.

## Validation

At head `ca2b088a69cedc6e04a9ca515ebd4f1beb7e9f5a` all relevant workflows completed successfully:

- KiCom Expansion Cell v1 run `35245911905`: SUCCESS
- KiCom DEV zone checks run `35245911899`: SUCCESS
- KiCom DEV Observer v1 run `35245911930`: SUCCESS
- KiCom Artifact Transport v1 run `35245912123`: SUCCESS

Artifact Transport selftests cover:

- fast-source failure -> slower local fallback;
- mandatory hash verification across fallback paths;
- append-only source experience history;
- updater callback handoff of the exact verified artifact;
- external adapter participation in the same hash gate;
- plaintext FTP forbidden unless explicitly enabled.

## Prepared one-time bootstrap

Combined flat `/dev/` package built from the successful workflow artifacts:

`KiCom-DEV-FLAT-Multi-Transport-Bootstrap-2026-09-17.zip`

SHA-256:

`55d3893c4ea83172821ebb8ddb48036256fdf910c57c4622c8b99277e3ea9430`

The ZIP is flat for extraction **inside the existing `/dev/` directory**. It contains the repaired DEV runtime/importer plus an inert `artifact-transport/` candidate directory.

## Promotion state

- DEV SHA-bound importer: implemented candidate, awaiting live bootstrap deployment confirmation.
- Central Multi-Transport Bus: implemented candidate, CI green, inert until productive runtime binding/promotion.
- Existing production updater: unchanged.
- Autonomous executable evolution: still deferred.
- Autonomous reproduction: still deferred.
