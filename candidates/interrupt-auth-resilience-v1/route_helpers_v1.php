<?php
declare(strict_types=1);
require_once __DIR__.'/session_open_resilient_v2.php';
require_once __DIR__.'/approval_status_v1.php';
require_once __DIR__.'/update_pending_inspect_v1.php';
require_once __DIR__.'/source_manifest_status_v1.php';

function kicomClientRequestIdV1(array $get,array $post=[]): string {
    $v=(string)($post['request_id']??$get['request_id']??'');
    return preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$v)?$v:'';
}
function kicomRouteAuthSessionOpenV1(string $code,string $requestId): array {
    if($requestId!=='')return kicomSessionOpenIdempotentV2($code,$requestId);
    return kicomAutonomySessionOpen($code);
}
function kicomRouteApprovalStatusV1(string $approvalId,string $bindingSha256): array {return kicomAuthApprovalStatusReadV1($approvalId,$bindingSha256);}
function kicomApprovalStatusKclLinesV1(array $r,string $serverRequestId): array {
    if(empty($r['ok']))return [KCL_PROTOCOL,'ERROR auth_approval_status','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'FAILED')),'END'];
    return [KCL_PROTOCOL,'OK auth_approval_status','FACT request_id='.kclString($serverRequestId),'FACT code="OK"','FACT approval_id='.kclString((string)$r['approval_id']),'FACT action='.kclString((string)$r['action']),'FACT risk='.kclString((string)$r['risk']),'FACT status='.kclString((string)$r['status']),'FACT terminal='.(!empty($r['terminal'])?'true':'false'),'FACT binding_sha256='.kclString((string)$r['binding_sha256']),'FACT expires_at='.(int)$r['expires_at'],'FACT used_at='.kclString((string)$r['used_at']),'FACT result_code='.kclString((string)$r['result_code']),'RULE read-only-no-authorization','END'];
}
function kicomPendingInspectKclLinesV1(array $r,string $serverRequestId): array {
    $lines=[KCL_PROTOCOL,'OK update_pending_inspect','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'OK')),'FACT pending='.(!empty($r['pending'])?'true':'false')];
    if(!empty($r['pending'])){foreach(['from_version','to_version','zip_sha256','manifest_sha256','genome_id','genome_sha256','source','risk_class','created_at'] as $k)$lines[]='FACT '.$k.'='.kclString((string)($r[$k]??''));$lines[]='FACT kernel_update='.(!empty($r['kernel_update'])?'true':'false');$lines[]='FACT files_count='.(int)($r['files_count']??0);$lines[]='FACT install_files_count='.(int)($r['install_files_count']??0);foreach(($r['risk_reasons']??[]) as $i=>$x)$lines[]='RISK #'.($i+1).'. reason='.kclString((string)$x);foreach(($r['changed_paths']??[]) as $i=>$x)$lines[]='CHANGED #'.($i+1).'. path='.kclString((string)$x);}
    $lines[]='RULE read-only-no-package-mutation';$lines[]='END';return $lines;
}
function kicomSourceManifestKclLinesV1(array $r,string $serverRequestId): array {
    $lines=[KCL_PROTOCOL,'OK source_manifest_status','FACT request_id='.kclString($serverRequestId),'FACT version='.kclString((string)($r['version']??'')),'FACT manifest_sha256='.kclString((string)($r['manifest_sha256']??'')),'FACT files='.count($r['files']??[])];
    foreach(($r['files']??[]) as $i=>$f)$lines[]='FILE #'.($i+1).'. path='.kclString((string)($f['path']??'')).' bytes='.(int)($f['bytes']??0).' sha256='.kclString((string)($f['sha256']??''));$lines[]='RULE hashes-only-no-source-content';$lines[]='END';return $lines;
}
