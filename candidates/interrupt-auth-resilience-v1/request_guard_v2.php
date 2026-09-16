<?php
declare(strict_types=1);

/* Pre-execution idempotency guard for normal-autonomy requests.
 * Requires interrupt_resilience_v1.php + request_replay_v2.php.
 */
function kicomRequestGuardRequestSha(string $operation,array $params,string $presentedToken): string {
    return hash('sha256',$operation.'|'.kicomReplayFingerprint($operation,$params).'|'.hash('sha256',$presentedToken));
}
function kicomRequestGuardBegin(string $sessionId,string $requestId,string $operation,array $params,string $presentedToken): array {
    $rid=kicomReplayRequestId($requestId)??'';if($rid==='')return ['ok'=>false,'code'=>'REQUEST_ID_INVALID'];
    $sha=kicomRequestGuardRequestSha($operation,$params,$presentedToken);
    $claim=kicomResilienceReceiptBegin($sessionId,$rid,$operation,$sha,1800);
    if(empty($claim['ok']))return $claim;
    if(($claim['code']??'')==='REQUEST_REPLAY'){
        $receipt=is_array($claim['receipt']??null)?$claim['receipt']:[];$status=(string)($receipt['status']??'');
        if($status==='IN_PROGRESS')return ['ok'=>true,'code'=>'REQUEST_IN_FLIGHT','mode'=>'IN_FLIGHT','replayed'=>false];
        if($status==='DONE'){
            $replay=kicomReplayLookup($sessionId,$rid,$operation,$params,$presentedToken);
            if(!empty($replay['ok']))return ['ok'=>true,'code'=>'REQUEST_REPLAY','mode'=>'REPLAY','response'=>$replay['response'],'replayed'=>true];
            return ['ok'=>false,'code'=>'REQUEST_REPLAY_UNAVAILABLE','detail'=>(string)($replay['code']??'UNKNOWN')];
        }
        return ['ok'=>false,'code'=>'REQUEST_RECEIPT_STATE_INVALID'];
    }
    return ['ok'=>true,'code'=>'REQUEST_EXECUTE','mode'=>'EXECUTE','claim'=>(string)($claim['claim']??''),'replayed'=>false];
}
function kicomRequestGuardComplete(string $sessionId,string $requestId,string $operation,array $params,string $presentedToken,string $claim,array $response): array {
    $replay=kicomReplayStore($sessionId,$requestId,$operation,$params,$presentedToken,$response,1800);
    if(empty($replay['ok']))return ['ok'=>false,'code'=>'REQUEST_REPLAY_STORE_FAILED','detail'=>(string)($replay['code']??'UNKNOWN')];
    $summary=['code'=>(string)($response['code']??(!empty($response['ok'])?'OK':'FAILED')),'ok'=>!empty($response['ok']),'response_sha256'=>hash('sha256',json_encode($response,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'')];
    $commit=kicomResilienceReceiptCommit($sessionId,$requestId,$claim,$summary);
    if(empty($commit['ok']))return ['ok'=>false,'code'=>'REQUEST_RECEIPT_COMMIT_FAILED','detail'=>(string)($commit['code']??'UNKNOWN')];
    return ['ok'=>true,'code'=>'REQUEST_COMMITTED'];
}
