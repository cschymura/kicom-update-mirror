<?php
declare(strict_types=1);
require __DIR__ . '/KiComEngramHostEvidenceAdapter.php';

$n = 0;
function ok(bool $v, string $m): void { global $n; if (!$v) throw new RuntimeException('FAIL '.$m); $n++; echo "PASS $m\n"; }
function denied(callable $f, string $m): void { try { $f(); } catch (Throwable $e) { ok(true,$m); return; } throw new RuntimeException('FAIL '.$m); }

$binding = hash('sha256', 'synthetic-host-config');
$facts = [
 'schema'=>'mirage-host-facts/v1','checked_at_utc'=>'2026-09-21T00:00:00Z',
 'synthetic_only'=>true,'private_api_inactive'=>true,'mcp_connector_connected'=>false,
 'all_vhosts_reviewed'=>true,'all_aliases_reviewed'=>true,'default_host_reviewed'=>true,
 'private_paths_outside_all_webroots'=>true,'private_parent_mode_verified'=>true,
 'data_mode_verified'=>true,'backup_mode_verified'=>true,'php_identity_verified'=>true,
 'cross_app_read_denied'=>true,'cross_app_write_denied'=>true,
 'open_basedir_boundary_verified'=>true,'backup_snapshot_verified'=>true,
 'backup_restore_verified'=>true,'rollback_boundary_verified'=>true,
 'retention_delete_verified'=>true,
];
$r=KiComEngramHostEvidenceAdapter::evaluateReviewedFacts($facts,$binding);
ok($r['eligible']===true,'complete reviewed facts accepted');
ok($r['status']==='HOST_ISOLATION_EVIDENCE_COMPLETE_API_STILL_INACTIVE','adapter cannot activate API');
ok($r['checks']===15,'all material isolation facts counted');
ok(preg_match('/\A[a-f0-9]{64}\z/D',$r['evidence_id'])===1,'privacy-safe evidence id emitted');

$bad=$facts; $bad['cross_app_read_denied']=false;
denied(fn()=>KiComEngramHostEvidenceAdapter::evaluateReviewedFacts($bad,$binding),'failed cross-app read isolation denied');
$bad=$facts; unset($bad['backup_restore_verified']);
denied(fn()=>KiComEngramHostEvidenceAdapter::evaluateReviewedFacts($bad,$binding),'missing backup restore evidence denied');
$bad=$facts; $bad['private_api_inactive']=false;
denied(fn()=>KiComEngramHostEvidenceAdapter::evaluateReviewedFacts($bad,$binding),'already-active private API denied');
$bad=$facts; $bad['mcp_connector_connected']=true;
denied(fn()=>KiComEngramHostEvidenceAdapter::evaluateReviewedFacts($bad,$binding),'premature MCP connection denied');
$bad=$facts; $bad['absolute_path']='/secret/private/path';
denied(fn()=>KiComEngramHostEvidenceAdapter::evaluateReviewedFacts($bad,$binding),'absolute path field rejected');
$bad=$facts; $bad['php_uid']=12345;
denied(fn()=>KiComEngramHostEvidenceAdapter::evaluateReviewedFacts($bad,$binding),'PHP UID field rejected');
$bad=$facts; $bad['credential']='secret';
denied(fn()=>KiComEngramHostEvidenceAdapter::evaluateReviewedFacts($bad,$binding),'credential field rejected');
$bad=$facts; $bad['schema']='mirage-host-facts/v2';
denied(fn()=>KiComEngramHostEvidenceAdapter::evaluateReviewedFacts($bad,$binding),'unknown facts schema denied');
$bad=$facts; $bad['php_identity_verified']='yes';
denied(fn()=>KiComEngramHostEvidenceAdapter::evaluateReviewedFacts($bad,$binding),'non-boolean fact denied');
denied(fn()=>KiComEngramHostEvidenceAdapter::evaluateReviewedFacts($facts,'bad'),'invalid host binding denied');
$bad=$facts; $bad['checked_at_utc']='not-a-time';
denied(fn()=>KiComEngramHostEvidenceAdapter::evaluateReviewedFacts($bad,$binding),'invalid timestamp denied downstream');

echo "KICOM_ENGRAM_HOST_EVIDENCE_ADAPTER_TESTS_PASSED=$n\n";
