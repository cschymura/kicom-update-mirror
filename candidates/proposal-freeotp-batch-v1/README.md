# KiCom Candidate: transaction-bound FreeOTP workspace proposal batch

Status: candidate / non-authoritative / non-executable until promoted through KiCom's normal verifier.
Target baseline: KiCom 0.9.13 or later.

## Goal

Allow ChatGPT to prepare an exact batch of pending **workspace_write** proposals and let the human approve that exact batch with one fresh FreeOTP code, without visiting the admin UI.

## Intentionally narrow v1 scope

- 1..16 proposals per batch.
- `kind=workspace_write` only.
- `base_sha256=NEW` only.
- No memory, goal, archive, deploy, self-update, production or recovery-kernel proposal can enter this action.
- An active bounded-autonomy session is required to prepare the approval.
- Execution uses the existing `kicomAuthApprovalExecute()` path, therefore current-counter TOTP consumption, replay protection, rate limiting and one-use approval state stay unchanged.
- Risk class is `red` so the exact prepared binding may survive ordinary session idle for up to one hour; execution still requires a fresh current-counter FreeOTP code.

## Exact binding

Each item is re-read from `var/pending` during preparation and binds:

- proposal ID
- proposal-file SHA-256
- content SHA-256
- workspace path
- `base_sha256=NEW`

The sorted item set is canonicalized to `batch_sha256`. Execution re-reads every proposal and rejects any changed, missing, non-workspace or no-longer-NEW item before the first write.

## Endpoint contract

Preparation extends the existing endpoint:

`?q=AUTH_APPROVAL_PREPARE&action=workspace_proposal_batch&proposals=<id>:<content_sha256>,<id>:<content_sha256>...&session_id=<id>&token=<rolling>`

Expected response additions:

- `BIND proposal_count="N"`
- `BIND batch_sha256="..."`
- `BIND scope="workspace-new-only"`
- one `ITEM` line per bound proposal with ID, path and content SHA-256
- `REQUIRES freeotp_code=true`

Execution remains unchanged:

`?q=AUTH_APPROVAL_EXECUTE&approval_id=<id>&code=<fresh-current-FreeOTP>`

## Required integration patches

1. Add the candidate helper functions from `proposal_approval_v1.php` to the trusted internal implementation (prefer exact server-side patch into `living.php`; do not expose a standalone web-executable helper).
2. In `kicomAuthApprovalExecute()`, add:

```php
elseif($action==='workspace_proposal_batch')
    $result=kicomApplyWorkspaceProposalBatchApproval($payload,is_array($row['binding']??null)?$row['binding']:[]);
```

3. Extend `AUTH_APPROVAL_PREPARE` in `index.php`:

```php
if($action==='self_update_install')
    $r=kicomAuthPrepareSelfUpdate((string)$ss['session_id']);
elseif($action==='workspace_proposal_batch')
    $r=kicomAuthPrepareWorkspaceProposalBatch((string)$ss['session_id'],(string)($_GET['proposals']??''));
else
    $r=['ok'=>false,'code'=>'APPROVAL_ACTION_UNSUPPORTED'];
```

When `workspace_proposal_batch` succeeds, emit item evidence from `$r['items']` in addition to the existing binding facts.

4. Extend `DESCRIBE` with the capability and rules:

- `CAPABILITY HUMAN_AUTH ... transaction_bound_workspace_proposal_batch`
- `RULE workspace-proposal-batch=new-only|max16|exact-id-and-sha-bound`
- `RULE workspace-proposal-batch-cannot-approve-memory-deploy-update-production-kernel`
- `RULE workspace-proposal-batch-preflight-all-before-write`
- `RULE workspace-proposal-batch-compensating-rollback-preserves-history`

5. Promote only through the normal KiCom server-side build/release verifier. Update version/genome/canonical memory only as part of that verified release.

## Failure semantics

The batch takes an exclusive local approval lock. All items are preflighted before the first workspace write. If a later write fails, earlier newly-created workspace artifacts are removed only after their current SHA still matches the just-applied SHA; their content is first recorded to workspace revision history with action `proposal_batch_compensating_rollback`.

Pending proposal files are removed only after the full batch succeeds.

## Tests already run locally

- normal two-item prepare/apply
- pending proposals consumed only after success
- proposal-file tampering after prepare is rejected before any write
- simulated second-write failure triggers compensation
- compensated content remains in revision history
- PHP syntax check passes on PHP 8.4

## Promotion rule

GitHub is a mirror/candidate transport only. The live KiCom server state, live source hashes, verifier, genome and canonical memory remain authoritative.
