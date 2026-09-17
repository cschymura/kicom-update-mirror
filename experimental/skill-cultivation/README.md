# KiCom Skill Cultivation v1

Status: **implemented candidate**, not yet part of the trusted live 0.9.15/g16 genome.

## Purpose

KiCom must not treat recurring human technical intervention as normal operation. Inside KiCom-owned territory, a repeated manual technical step is evidence of a missing or immature capability. Skill Cultivation turns that evidence into a bounded development lifecycle.

A KiCom skill is not merely code. It is:

- a tool or action mechanism,
- knowledge of when the mechanism is applicable,
- explicit boundaries and trust requirements,
- retained practice/evidence,
- measurable success criteria,
- a current maturity/availability state.

## Lifecycle

`observed_gap -> proposed -> experimenting -> practiced -> reliable -> available`

A capability may later become `degraded` or `superseded`. Superseding never deletes its retained evidence.

The default maturity thresholds in v1 are deliberately conservative:

- first observation: `observed_gap`
- second recurrence: `proposed`
- fewer than three practice episodes: `experimenting`
- established practice but insufficient diversity: `practiced`
- broader successful practice: `reliable`
- at least eight episodes, at least seven successes, at least two contexts and >=85% success: `available`

These thresholds are policy inputs for later evolution; they are not permission grants.

## Human-at-the-boundary rule

Repeated technical human mediation is a first-class gap kind: `human_technical_mediation`.

On its **second independent observation**, KiCom should create/promote a cultivation candidate instead of accepting the manual step as normal procedure.

Examples:

- repeatedly asking a human to upload or unpack an internal KiCom artifact,
- repeatedly asking a human to copy data between already-authorized KiCom locations,
- repeatedly requiring a human to restart a known internal recovery sequence.

A genuine protected external boundary is different. If an external service requires a new credential, permission, legal decision, or explicit owner authorization, the capability remains bounded by `EXTERNAL_AUTH_REQUIRED`.

## Non-negotiable boundaries

The cultivation layer itself cannot:

- execute arbitrary code,
- grant itself external permissions,
- expose or persist secrets,
- turn `UNKNOWN` into `AVAILABLE` without evidence,
- bypass a protected external authorization boundary,
- hard-delete history.

Default boundaries are stored with every skill:

- `arbitrary_code_execution = FORBIDDEN`
- `direct_secret_visibility = FORBIDDEN`
- `external_permission_grant = FORBIDDEN`
- `protected_external_action = EXTERNAL_AUTH_REQUIRED`
- `history_hard_delete = FORBIDDEN`

## Relationship to self-healing and evolution

**Self-healing** restores a known trusted state.

**Evolution** creates and evaluates a changed state that may improve KiCom beyond the previous one.

**Skill cultivation** sits between experience and evolution: it recognizes a recurring gap/opportunity, defines the ability that is missing, accumulates bounded practice evidence, and exposes the mature result to the action/world model as a capability.

This gives the loop:

`goal -> perceive -> remember -> identify gap/opportunity -> cultivate -> practice -> evaluate -> use -> observe result -> heal/evolve when needed`

## Intended 0.9.16/g17 integration

The first trusted integration should:

1. add this engine as a genome-bound module rather than a loose `/dev/` file;
2. persist its derived state under the KiCom living state area and its evidence as append-only JSONL;
3. feed repeated human mediation and repeated action failures from Experience Memory into `observeGap()`;
4. expose mature skills to the World/Action Model as `AVAILABLE`, immature skills as `DEGRADED`/`UNKNOWN`, and superseded skills as `STALE`;
5. keep external permission/auth state separate from skill maturity;
6. let the evolution subsystem propose/test implementations for `proposed` skill gaps in sandbox/test territory;
7. never require a human merely to shuttle KiCom-internal files between already-authorized KiCom endpoints.

## Current motivating example

The repeated manual DEV repair ZIP transfers on 2026-09-17 are evidence for the skill **resilient artifact delivery**. The desired mature skill is not merely “FTP support”; it is the ability to select among known transports, deliver exact SHA-bound bytes, verify the destination, fall back safely, and retain evidence without weakening verification.
