<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramActivationTransaction.php';
require_once __DIR__.'/KiComEngramHostingPolicy.php';

$checks=0;
function yes61(bool $ok,string $name):void{
 global $checks;
 if(!$ok)throw new RuntimeException('FAIL '.$name);
 ++$checks; echo "PASS ".$name."\n";
}
function reject61(callable $f,string $name):void{
 try{$f();}catch(RuntimeException|InvalidArgumentException $e){
  yes61(true,$name);return;
 }
 throw new RuntimeException('FAIL '.$name);
}
$now=new DateTimeImmutable('2026-09-21T13:15:00Z');
$hostId=hash('sha256','synthetic checked shared webspace configuration');
$binding=hash('sha256','synthetic original signed KiCom owner binding');
$host=[
 'eligible'=>true,
 'status'=>'SHARED_HOST_RISK_EXPLICITLY_ACCEPTED_API_STILL_INACTIVE',
 'evidence_id'=>$hostId,
 'checked_at_utc'=>'2026-09-21T13:10:00Z',
 'hosting_policy_mode'=>KiComEngramHostingPolicy::SHARED_MODE,
 'hosting_policy_owner'=>'mirage-owner',
 'known_limitation'=>'shared-php-uid-not-verified',
 'operator_accepts_shared_host_risk'=>true,
];
$owner=[
 'schema'=>'mirage-owner-readiness/v1',
 'verified'=>true,'owner_binding'=>$binding,
 'host_evidence_id'=>$hostId,
 'checked_at_utc'=>'2026-09-21T13:11:00Z',
 'private_api_inactive'=>true,
 'mcp_connector_connected'=>false,
];
$ready=KiComEngramActivationReadinessGate::evaluate($host,$owner,$binding,$now);
yes61($ready['ready']===true
 &&$ready['hosting_mode']==='operator_accepted_shared_host'
 &&$ready['status']==='AUTHENTICATED_SHARED_HOST_ACCEPTED_API_STILL_INACTIVE',
 'explicit shared-host risk acceptance is readiness with honest non-isolation label');
yes61($ready['owner_binding']===$binding&&$ready['host_evidence_id']===$hostId,
 'shared-host readiness retains current private owner and host bindings');
$bad=$host;$bad['operator_accepts_shared_host_risk']=false;
reject61(fn()=>KiComEngramActivationReadinessGate::evaluate($bad,$owner,$binding,$now),
 'shared-host evidence without operator approval denied');
$bad=$host;$bad['hosting_policy_mode']='verified-isolation';
reject61(fn()=>KiComEngramActivationReadinessGate::evaluate($bad,$owner,$binding,$now),
 'shared-host status cannot be upgraded to fake verified isolation');
$bad=$host;$bad['status']='HOST_ISOLATION_EVIDENCE_COMPLETE_API_STILL_INACTIVE';
reject61(fn()=>KiComEngramActivationReadinessGate::evaluate($bad,$owner,$binding,$now),
 'shared-host evidence cannot masquerade as completed isolation gate');
$bad=$host;$bad['hosting_policy_owner']='foreign';
reject61(fn()=>KiComEngramActivationReadinessGate::evaluate($bad,$owner,$binding,$now),
 'shared-host consent by unrelated user denied');
$bad=$host;$bad['known_limitation']='none';
reject61(fn()=>KiComEngramActivationReadinessGate::evaluate($bad,$owner,$binding,$now),
 'absence of documented shared-PHP-UID limitation denied');
$bad=$host;$bad['checked_at_utc']='2026-09-21T12:10:00Z';
reject61(fn()=>KiComEngramActivationReadinessGate::evaluate($bad,$owner,$binding,$now),
 'stale hosting evidence denied despite accepted shared-host policy');
$bad=$owner;$bad['verified']=false;
reject61(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$bad,$binding,$now),
 'operator hosting decision cannot bypass verified owner evidence');
$bad=$owner;$bad['owner_binding']=hash('sha256','different private user');
reject61(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$bad,$binding,$now),
 'operator hosting decision cannot authorize other memory owner');
$bad=$owner;$bad['host_evidence_id']=hash('sha256','foreign webspace');
reject61(fn()=>KiComEngramActivationReadinessGate::evaluate($host,$bad,$binding,$now),
 'operator hosting decision cannot authorize memory for a different host');

$approval=[
 'schema'=>'mirage-activation-approval/v1','approved'=>true,
 'purpose'=>'activate-private-engram-shared-host',
 'owner_binding'=>$binding,'host_evidence_id'=>$hostId,
 'approval_nonce'=>hash('sha256','synthetic separate operator activation acceptance'),
 'approved_at_utc'=>'2026-09-21T13:14:00Z',
];
$open=static fn():PDO=>new PDO('sqlite::memory:',null,null,[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
]);
$db=$open();
yes61(KiComEngramActivationTransaction::state($db)==='inactive',
 'shared-host activation fixture starts inactive');
$wrong=$approval;$wrong['purpose']='activate-private-engram';
reject61(fn()=>KiComEngramActivationTransaction::apply(
 $db,$host,$owner,$binding,$wrong,$now
),'ordinary isolation-mode approval cannot activate accepted shared-host deployment');
yes61(KiComEngramActivationTransaction::state($db)==='inactive',
 'incorrect operator approval leaves all activation state inactive');
$wrong=$approval;$wrong['approved']=false;
reject61(fn()=>KiComEngramActivationTransaction::apply(
 $db,$host,$owner,$binding,$wrong,$now
),'shared-host acceptance alone does not substitute for actual operator activation');
$wrong=$approval;$wrong['owner_binding']=hash('sha256','different owner');
reject61(fn()=>KiComEngramActivationTransaction::apply(
 $db,$host,$owner,$binding,$wrong,$now
),'activation scope must match current Passkey owner');
$wrong=$approval;$wrong['approved_at_utc']='2026-09-21T12:00:00Z';
reject61(fn()=>KiComEngramActivationTransaction::apply(
 $db,$host,$owner,$binding,$wrong,$now
),'shared-host activation approval must be recent');
yes61(KiComEngramActivationTransaction::state($db)==='inactive',
 'all rejected approvals leave activation database inactive');
$result=KiComEngramActivationTransaction::apply(
 $db,$host,$owner,$binding,$approval,$now
);
yes61($result['activated']===true
 &&$result['hosting_mode']==='operator_accepted_shared_host'
 &&$result['status']==='SYNTHETIC_ACTIVATION_TRANSACTION_COMMITTED',
 'separately approved synthetic shared-host activation binds exact owner, host and explicit mode');
yes61(KiComEngramActivationTransaction::state($db)==='active',
 'synthetic activation state commits only after distinct owner-scoped approval');
reject61(fn()=>KiComEngramActivationTransaction::apply(
 $db,$host,$owner,$binding,$approval,$now
),'activation nonce and active state cannot be reused');

echo "KICOM_ENGRAM_SHARED_HOST_ACTIVATION_TESTS_PASSED=$checks\n";
