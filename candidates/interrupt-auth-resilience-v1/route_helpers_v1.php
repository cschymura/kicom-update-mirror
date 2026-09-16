<?php
declare(strict_types=1);
require_once __DIR__.'/session_open_resilient_v2.php';
require_once __DIR__.'/approval_status_v1.php';

function kicomClientRequestIdV1(array $get,array $post=[]): string {
    $v=(string)($post['request_id']??$get['request_id']??'');
    return preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$v)?$v:'';
}
function kicomRouteAuthSessionOpenV1(string $code,string $requestId): array {
    if($requestId!=='')return kicomSessionOpenIdempotentV2($code,$requestId);
    return kicomAutonomySessionOpen($code);
}
function kicomRouteApprovalStatusV1(string $approvalId,string $bindingSha256): array {
    return kicomAuthApprovalStatusReadV1($approvalId,$bindingSha256);
}
function kicomApprovalStatusKclLinesV1(array $r,string $serverRequestId): array {
    if(empty($r['ok']))return [KCL_PROTOCOL,'ERROR auth_approval_status','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'FAILED')),'END'];
    return [KCL_PROTOCOL,'OK auth_approval_status','FACT request_id='.kclString($serverRequestId),'FACT code="OK"','FACT approval_id='.kclString((string)$r['approval_id']),'FACT action='.kclString((string)$r['action']),'FACT risk='.kclString((string)$r['risk']),'FACT status='.kclString((string)$r['status']),'FACT terminal='.(!empty($r['terminal'])?'true':'false'),'FACT binding_sha256='.kclString((string)$r['binding_sha256']),'FACT expires_at='.(int)$r['expires_at'],'FACT used_at='.kclString((string)$r['used_at']),'FACT result_code='.kclString((string)$r['result_code']),'RULE read-only-no-authorization','END'];
}
