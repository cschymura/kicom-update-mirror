<?php
declare(strict_types=1);
require __DIR__ . '/KiComEngramActivationReadinessGate.php';

$checks = 0;
function ok(bool $v, string $m): void { global $checks; if (!$v) throw new RuntimeException('FAIL '.$m); $checks++; echo "PASS $m\n"; }
function denied(callable $f, string $m): void { try { $f(); } catch (RuntimeException|InvalidArgumentException $e) { ok(true,$m); return; } throw new RuntimeException('FAIL '.$m); }
$now = new DateTimeImmutable('2026-09-21T01:00:00Z');
$hostId = hash('sha256','synthetic-host-evidence');
$owner = hash('sha256','synthetic-owner');
$host = ['eligible'=>true,'status'=>'HOST_ISOLATION_EVIDENCE_COMPLETE_API_STILL_INACTIVE','evidence_id'=>$hostId,'checked_at_utc'=>'2026-09-21T00:55:00Z'];
$identity = ['schema'=>'mirage-owner-readiness/v1','verified'=>true,'owner_binding'=>$owner,'host_evidence_id'=>$hostId,'checked_at_utc'=>'2026-09-21T00:56:00Z','private_api_inactive'=>true,'mcp_connector_connected'=>false];
$r = KiComEngramActivationReadinessGate::evaluate($host,$identity,$owner,$now);
ok($r['ready'] === true && $r['status'] === 'AUTHENTICATED_ACTIVATION_READY_API_STILL_INACTIVE','complete synthetic evidence is readiness-only');
ok(hash_equals($owner,$r['owner_binding']) && hash_equals($hostId,$r['host_evidence_id']),'bindings preserved');
$x=$identity; $x['owner_binding']=hash('sha256','other-owner'); denied(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$x,$owner,$now),'wrong owner denied');
$x=$identity; $x['host_evidence_id']=hash('sha256','other-host'); denied(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$x,$owner,$now),'foreign host evidence denied');
$x=$identity; $x['checked_at_utc']='2026-09-21T00:30:00Z'; denied(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$x,$owner,$now),'stale owner evidence denied');
$x=$host; $x['checked_at_utc']='2026-09-21T00:30:00Z'; denied(fn()=>KiComEngramActivationReadinessGate::evaluate($x,$identity,$owner,$now),'stale host evidence denied');
$x=$identity; $x['checked_at_utc']='2026-09-21T01:01:00Z'; denied(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$x,$owner,$now),'future evidence denied');
$x=$identity; $x['private_api_inactive']=false; denied(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$x,$owner,$now),'active private API denied at readiness gate');
$x=$identity; $x['mcp_connector_connected']=true; denied(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$x,$owner,$now),'premature MCP connection denied');
$x=$identity; $x['verified']=false; denied(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$x,$owner,$now),'unverified owner denied');
$x=$host; $x['status']='SOMETHING_ELSE'; denied(fn()=>KiComEngramActivationReadinessGate::evaluate($x,$identity,$owner,$now),'wrong host gate state denied');
$x=$identity; $x['passkey']='secret'; denied(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$x,$owner,$now),'secret-like extra field denied');
$x=$host; $x['path']='/private/path'; denied(fn()=>KiComEngramActivationReadinessGate::evaluate($x,$identity,$owner,$now),'privacy-sensitive host extra field denied');
denied(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$identity,str_repeat('z',64),$now),'invalid expected owner binding denied');
denied(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$identity,$owner,$now,30),'unsafe evidence age policy denied');
echo "KICOM_ENGRAM_ACTIVATION_READINESS_TESTS_PASSED=$checks\n";
