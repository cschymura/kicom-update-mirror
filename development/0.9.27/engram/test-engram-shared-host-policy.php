<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramHostingPolicy.php';
require_once __DIR__.'/KiComEngramOAuthHttp.php';
require_once __DIR__.'/KiComEngramMcpRuntimeGate.php';

$count=0;
function check61(bool $yes,string $label):void{
 global $count;
 if(!$yes)throw new RuntimeException('FAIL '.$label);
 ++$count;echo "PASS $label\n";
}
function denied61(callable $f,string $label):void{
 try{$f();}catch(RuntimeException $e){
  check61($e->getMessage()==='MCP_RUNTIME_INACTIVE_OR_UNAUTHORIZED',$label);
  return;
 }
 throw new RuntimeException('FAIL '.$label);
}
$fp=hash('sha256','synthetic original KiCom enrolled credential');
$ownerBinding=hash('sha256',"mirage-owner\0".$fp);
$hostId=hash('sha256','synthetic host acceptance snapshot');
$runtime=[
 'enabled'=>true,'operator_approved'=>true,'host_isolation_verified'=>true,
 'review_enabled'=>true,'mcp_connector_enabled'=>true,'oauth_enabled'=>true,
 'runtime_source'=>'server-only-reviewed',
 'private_memory_scope'=>'dev-verified-owner',
 'admin_subject'=>'mirage-owner',
 'expected_origin'=>'https://kicom.rurtalbahn.info',
 'rp_id'=>'kicom.rurtalbahn.info',
 'mcp_connector_id'=>'mirage-dev61',
 'owner_binding'=>$ownerBinding,
 'host_evidence_id'=>$hostId,
];
$owner=['enabled'=>true,'credential_fingerprint'=>$fp,
 'subject'=>'mirage-owner','namespaces'=>['project'],
 'engram_rights'=>['engram.read']];
$lookup=static fn(string $k):?array=>$k===$fp?$owner:null;
$identity=['authenticated'=>true,'connector_id'=>'mirage-dev61',
 'credential_fingerprint'=>$fp,'owner_binding'=>$ownerBinding,
 'host_evidence_id'=>$hostId];
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE activation_state
 (singleton INTEGER PRIMARY KEY,state TEXT,owner_binding TEXT,host_evidence_id TEXT)');
$q=$db->prepare('INSERT INTO activation_state VALUES (1,?,?,?)');
$q->execute(['active',$ownerBinding,$hostId]);

check61(KiComEngramHostingPolicy::permits($runtime),
 'previously verified isolated host mode is still supported');
check61(KiComEngramHostingPolicy::mode($runtime)==='host_isolation_verified',
 'verified isolated host is labelled accurately');
check61(KiComEngramOAuthHttp::available($runtime),
 'previously verified isolated OAuth availability remains intact');
check61(KiComEngramMcpRuntimeGate::authorize($runtime,$db,$lookup,$identity)['owner']==='mirage-owner',
 'previously isolated mode still requires matching current owner and activation');
$shared=$runtime;
$shared['host_isolation_verified']=false;
check61(!KiComEngramHostingPolicy::permits($shared),
 'shared host without separate explicit operator decision remains unavailable');
check61(!KiComEngramOAuthHttp::available($shared),
 'missing shared-host approval cannot disclose OAuth capabilities');
denied61(fn()=>KiComEngramMcpRuntimeGate::authorize($shared,$db,$lookup,$identity),
 'missing shared-host approval cannot access MCP memory');

$shared['operator_accepts_shared_host_risk']=true;
$shared['hosting_policy_mode']=KiComEngramHostingPolicy::SHARED_MODE;
$shared['hosting_policy_source']='protected-operator-host-config';
$shared['hosting_policy_owner']='mirage-owner';
$shared['hosting_policy_known_limitation']='shared-php-uid-not-verified';
$shared['hosting_policy_acknowledged_at_utc']='2026-09-21T12:00:00Z';
check61(KiComEngramHostingPolicy::permits($shared),
 'explicit private operator shared-host decision accepted without claiming isolation');
check61($shared['host_isolation_verified']===false
 &&KiComEngramHostingPolicy::mode($shared)==='shared_host_risk_explicitly_accepted_not_isolated',
 'shared-host mode visibly retains false isolation flag');
check61(KiComEngramOAuthHttp::available($shared),
 'OAuth discovery allows exact operator-approved shared-host runtime');
check61(KiComEngramMcpRuntimeGate::authorize($shared,$db,$lookup,$identity)['connector_id']==='mirage-dev61',
 'matching current owner, connector, activation and accepted hosting allow MCP read');

$mutate=[
 ['operator_accepts_shared_host_risk',false,'operator explicitly refuses shared host'],
 ['hosting_policy_mode','shared-host-unreviewed','wrong policy mode'],
 ['hosting_policy_source','browser-post','untrusted browser claims policy source'],
 ['hosting_policy_owner','someone-else','wrong acceptance owner'],
 ['hosting_policy_known_limitation','none','shared-host limitation misrepresented'],
 ['hosting_policy_acknowledged_at_utc','yesterday','malformed operator timestamp'],
 ['hosting_policy_acknowledged_at_utc','2026-02-30T10:00:00Z','impossible operator timestamp'],
 ['host_evidence_id','synthetic-not-hash','unbound host evidence ID'],
 ['runtime_source','setup-pending','unreviewed private host config'],
 ['host_isolation_verified',true,'contradictory isolated plus shared accepted flags']
];
foreach($mutate as [$key,$value,$label]){
 $bad=$shared;$bad[$key]=$value;
 check61(!KiComEngramHostingPolicy::permits($bad),$label.' denied by policy');
 check61(!KiComEngramOAuthHttp::available($bad),$label.' denies OAuth');
 denied61(fn()=>KiComEngramMcpRuntimeGate::authorize($bad,$db,$lookup,$identity),
  $label.' denies private MCP memory');
}
$unavailable=$shared;$unavailable['enabled']=false;
check61(!KiComEngramOAuthHttp::available($unavailable),
 'operator hosting acceptance alone never activates disabled OAuth');
denied61(fn()=>KiComEngramMcpRuntimeGate::authorize($unavailable,$db,$lookup,$identity),
 'operator hosting acceptance alone never enables disabled MCP');
$unavailable=$shared;$unavailable['operator_approved']=false;
check61(!KiComEngramOAuthHttp::available($unavailable),
 'hosting acceptance distinct from private-memory operator approval');
denied61(fn()=>KiComEngramMcpRuntimeGate::authorize($unavailable,$db,$lookup,$identity),
 'hosting acceptance cannot bypass owner-memory approval');
$db->exec("UPDATE activation_state SET state='inactive' WHERE singleton=1");
denied61(fn()=>KiComEngramMcpRuntimeGate::authorize($shared,$db,$lookup,$identity),
 'operator-hosting acceptance cannot bypass inactive private activation database');
$db->exec("UPDATE activation_state SET state='active' WHERE singleton=1");
$revoked=$owner;$revoked['enabled']=false;
denied61(fn()=>KiComEngramMcpRuntimeGate::authorize(
 $shared,$db,static fn(string $k):?array=>$revoked,$identity),
 'operator-hosting acceptance cannot bypass revoked current passkey owner');
$wrong=$identity;$wrong['connector_id']='foreign';
denied61(fn()=>KiComEngramMcpRuntimeGate::authorize($shared,$db,$lookup,$wrong),
 'operator-hosting acceptance cannot bypass wrong OAuth connector identity');
echo "KICOM_ENGRAM_SHARED_HOST_POLICY_TESTS_PASSED=$count\n";
