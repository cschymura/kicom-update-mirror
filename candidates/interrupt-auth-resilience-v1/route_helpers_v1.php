<?php
declare(strict_types=1);
require_once __DIR__.'/session_open_resilient_v2.php';
require_once __DIR__.'/approval_status_v1.php';
require_once __DIR__.'/update_pending_inspect_v1.php';
require_once __DIR__.'/source_manifest_status_v1.php';
require_once __DIR__.'/release_consistency_status_v1.php';

function kicomClientRequestIdV1(array $get,array $post=[]): string {
    $v=(string)($post['client_request_id']??$get['client_request_id']??'');
    return preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$v)?$v:'';
}
function kicomRouteAuthSessionOpenV1(string $code,string $clientRequestId): array {
    if($clientRequestId!=='')return kicomSessionOpenIdempotentV2($code,$clientRequestId);
    return kicomAutonomySessionOpen($code);
}
function kicomRouteApprovalStatusV1(string $approvalId,string $bindingSha256): array {return kicomAuthApprovalStatusReadV1($approvalId,$bindingSha256);}
function kicomApprovalStatusKclLinesV1(array $r,string $serverRequestId): array {
    if(empty($r['ok']))return [KCL_PROTOCOL,'ERROR auth_approval_status','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'FAILED')),'END'];
    return [KCL_PROTOCOL,'OK auth_approval_status','FACT request_id='.kclString($serverRequestId),'FACT code="OK"','FACT approval_id='.kclString((string)$r['approval_id']),'FACT action='.kclString((string)$r['action']),'FACT risk='.kclString((string)$r['risk']),'FACT status='.kclString((string)$r['status']),'FACT terminal='.(!empty($r['terminal'])?'true':'false'),'FACT binding_sha256='.kclString((string)$r['binding_sha256']),'FACT expires_at='.(int)$r['expires_at'],'FACT used_at='.kclString((string)$r['used_at']),'FACT result_code='.kclString((string)$r['result_code']),'RULE read-only-no-authorization','END'];
}
function kicomRoutePendingInspectAuthorizedV1(string $sessionId,string $token): array {
    $s=kicomAutonomySessionPeek(strtolower(trim($sessionId)),strtolower(trim($token)));
    if(empty($s['ok']))return ['ok'=>false,'code'=>(string)($s['code']??'SESSION_REJECTED')];
    $r=kicomUpdatePendingInspectV1();
    if(empty($r['ok']))return $r;
    $r['authorized_session_id']=(string)($s['session_id']??'');
    return $r;
}
function kicomPendingInspectKclLinesV1(array $r,string $serverRequestId): array {
    if(empty($r['ok']))return [KCL_PROTOCOL,'ERROR update_pending_inspect','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'FAILED')),'RULE authorized-read-only','END'];
    $lines=[KCL_PROTOCOL,'OK update_pending_inspect','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'OK')),'FACT pending='.(!empty($r['pending'])?'true':'false')];
    if(!empty($r['pending'])){foreach(['from_version','to_version','zip_sha256','manifest_sha256','genome_id','genome_sha256','source','risk_class','created_at'] as $k)$lines[]='FACT '.$k.'='.kclString((string)($r[$k]??''));$lines[]='FACT kernel_update='.(!empty($r['kernel_update'])?'true':'false');$lines[]='FACT files_count='.(int)($r['files_count']??0);$lines[]='FACT install_files_count='.(int)($r['install_files_count']??0);foreach(($r['risk_reasons']??[]) as $i=>$x)$lines[]='RISK #'.($i+1).'. reason='.kclString((string)$x);foreach(($r['changed_paths']??[]) as $i=>$x)$lines[]='CHANGED #'.($i+1).'. path='.kclString((string)$x);}
    $lines[]='RULE authorized-read-only-no-token-rotation';$lines[]='RULE read-only-no-package-mutation';$lines[]='END';return $lines;
}
function kicomSourceManifestKclLinesV1(array $r,string $serverRequestId): array {
    $lines=[KCL_PROTOCOL,'OK source_manifest_status','FACT request_id='.kclString($serverRequestId),'FACT version='.kclString((string)($r['version']??'')),'FACT manifest_sha256='.kclString((string)($r['manifest_sha256']??'')),'FACT files='.count($r['files']??[])];
    foreach(($r['files']??[]) as $i=>$f)$lines[]='FILE #'.($i+1).'. path='.kclString((string)($f['path']??'')).' bytes='.(int)($f['bytes']??0).' sha256='.kclString((string)($f['sha256']??''));$lines[]='RULE hashes-only-no-source-content';$lines[]='END';return $lines;
}
function kicomRouteReleaseConsistencyV1(): array {return kicomReleaseConsistencyStatusV1();}
function kicomReleaseConsistencyKclLinesV1(array $r,string $serverRequestId): array {
    if(empty($r['ok']))return [KCL_PROTOCOL,'ERROR release_consistency_status','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'FAILED')),'END'];
    $lines=[KCL_PROTOCOL,(!empty($r['consistent'])?'OK':'WARN').' release_consistency_status','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'OK')),'FACT consistent='.(!empty($r['consistent'])?'true':'false'),'FACT runtime_version='.kclString((string)($r['runtime_version']??'')),'FACT genome_id='.kclString((string)($r['genome_id']??'')),'FACT genome_generation='.(int)($r['genome_generation']??0),'FACT project_state_version='.kclString((string)($r['project_state_version']??'')),'FACT project_state_genome_id='.kclString((string)($r['project_state_genome_id']??'')),'FACT project_state_generation='.(int)($r['project_state_generation']??0),'FACT project_state_consistent='.(!empty($r['project_state_consistent'])?'true':'false'),'FACT changelog_has_runtime_release='.(!empty($r['changelog_has_runtime_release'])?'true':'false'),'FACT next_priority1_has_runtime='.(!empty($r['next_priority1_has_runtime'])?'true':'false'),'FACT protocol_workspace_batch_consistent='.(!empty($r['protocol_workspace_batch_consistent'])?'true':'false')];
    foreach(($r['stale_resources']??[]) as $i=>$name)$lines[]='STALE #'.($i+1).' resource='.kclString((string)$name);
    foreach(($r['memory_sha256']??[]) as $name=>$sha)$lines[]='MEMORY resource='.kclString((string)$name).' sha256='.kclString((string)$sha);
    $lines[]='RULE read-only-no-memory-mutation';$lines[]='RULE diagnostic-does-not-grant-authority';$lines[]='END';return $lines;
}
