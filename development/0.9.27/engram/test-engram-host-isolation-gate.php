<?php
declare(strict_types=1);
require __DIR__ . '/KiComEngramHostIsolationGate.php';

$passed = 0;
function okHost(bool $v, string $label): void { global $passed; if (!$v) throw new RuntimeException('FAIL '.$label); $passed++; echo "PASS $label\n"; }
function denyHost(callable $fn, string $label): void { $d=false; try {$fn();} catch (RuntimeException|InvalidArgumentException $e) {$d=true;} okHost($d,$label); }
$binding = hash('sha256', 'synthetic-host-config-v1');
$base = [
 'schema'=>'mirage-host-isolation/v1','host_binding'=>$binding,'synthetic_only'=>true,
 'private_api_inactive'=>true,'mcp_connector_connected'=>false,'checked_at_utc'=>'2026-09-21T00:00:00Z',
 'all_vhosts_reviewed'=>true,'all_aliases_reviewed'=>true,'default_host_reviewed'=>true,
 'private_paths_outside_all_webroots'=>true,'private_parent_mode_verified'=>true,
 'data_mode_verified'=>true,'backup_mode_verified'=>true,'php_identity_verified'=>true,
 'cross_app_read_denied'=>true,'cross_app_write_denied'=>true,'open_basedir_boundary_verified'=>true,
 'backup_snapshot_verified'=>true,'backup_restore_verified'=>true,'rollback_boundary_verified'=>true,
 'retention_delete_verified'=>true,
];
$out = KiComEngramHostIsolationGate::evaluate($base,$binding);
okHost($out['eligible'] === true && $out['checks'] === 15, 'complete synthetic host evidence eligible for later separate activation review');
okHost($out['status'] === 'HOST_ISOLATION_EVIDENCE_COMPLETE_API_STILL_INACTIVE', 'success never claims API activation');
okHost(strlen($out['evidence_id']) === 64, 'non-secret evidence digest emitted');
$reordered = array_reverse($base, true);
okHost(KiComEngramHostIsolationGate::evaluate($reordered,$binding)['evidence_id'] === $out['evidence_id'], 'evidence digest canonical across key order');
foreach (['all_vhosts_reviewed','all_aliases_reviewed','default_host_reviewed','private_paths_outside_all_webroots','php_identity_verified','cross_app_read_denied','cross_app_write_denied','open_basedir_boundary_verified','backup_snapshot_verified','backup_restore_verified','rollback_boundary_verified','retention_delete_verified'] as $field) {
 $x=$base; $x[$field]=false; denyHost(fn()=>KiComEngramHostIsolationGate::evaluate($x,$binding), "missing $field fails closed");
}
$x=$base; unset($x['data_mode_verified']); denyHost(fn()=>KiComEngramHostIsolationGate::evaluate($x,$binding),'missing required field fails closed');
$x=$base; $x['host_binding']=hash('sha256','other-host'); denyHost(fn()=>KiComEngramHostIsolationGate::evaluate($x,$binding),'foreign host binding rejected');
denyHost(fn()=>KiComEngramHostIsolationGate::evaluate($base,'not-a-digest'),'invalid expected binding rejected');
$x=$base; $x['mcp_connector_connected']=true; denyHost(fn()=>KiComEngramHostIsolationGate::evaluate($x,$binding),'premature MCP connection rejected');
$x=$base; $x['private_api_inactive']=false; denyHost(fn()=>KiComEngramHostIsolationGate::evaluate($x,$binding),'already active private API rejected');
$x=$base; $x['synthetic_only']=false; denyHost(fn()=>KiComEngramHostIsolationGate::evaluate($x,$binding),'non-synthetic preactivation evidence rejected');
$x=$base; $x['checked_at_utc']='2026-02-30T00:00:00Z'; denyHost(fn()=>KiComEngramHostIsolationGate::evaluate($x,$binding),'impossible evidence date rejected');
$x=$base; $x['secret']='must-not-be-accepted'; denyHost(fn()=>KiComEngramHostIsolationGate::evaluate($x,$binding),'unknown or secret-bearing field rejected');
$x=$base; $x['schema']='mirage-host-isolation/v2'; denyHost(fn()=>KiComEngramHostIsolationGate::evaluate($x,$binding),'unknown schema rejected');
echo "KICOM_ENGRAM_HOST_ISOLATION_GATE_TESTS_PASSED=$passed\n";
