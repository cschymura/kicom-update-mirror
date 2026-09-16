<?php
declare(strict_types=1);
require_once __DIR__.'/request_guard_v4.php';
require_once __DIR__.'/session_consume_resilient_v2.php';

/* Normal-autonomy wrapper with exact replay and never-blind-reexecute semantics. */
function kicomGuardedNormalActionV3(string $sessionId,string $presentedToken,string $requestId,string $operation,array $params,callable $action): array {
    $g=kicomRequestGuardBeginV4($sessionId,$requestId,$operation,$params,$presentedToken);if(empty($g['ok']))return $g;
    $mode=(string)($g['mode']??'');
    if($mode==='REPLAY')return is_array($g['response']??null)?$g['response']:['ok'=>false,'code'=>'REQUEST_REPLAY_INVALID'];
    if($mode==='IN_FLIGHT')return ['ok'=>false,'code'=>'REQUEST_IN_FLIGHT','retry_same_request'=>true,'request_id'=>$requestId];
    if($mode==='UNCERTAIN')return ['ok'=>false,'code'=>'REQUEST_UNCERTAIN','inspect_required'=>true,'request_id'=>$requestId];
    if($mode!=='EXECUTE')return ['ok'=>false,'code'=>'REQUEST_GUARD_MODE_INVALID'];
    $claim=(string)($g['claim']??'');$ss=kicomAutonomySessionConsumeResilientV2($sessionId,$presentedToken);
    if(empty($ss['ok'])){$response=$ss;$done=kicomRequestGuardCompleteV4($sessionId,$requestId,$operation,$params,$presentedToken,$claim,$response);return empty($done['ok'])?($done+['original_response'=>$response]):$response;}
    try{$response=$action($ss);if(!is_array($response))$response=['ok'=>false,'code'=>'ACTION_RESPONSE_INVALID'];}catch(Throwable $e){$response=['ok'=>false,'code'=>'ACTION_EXCEPTION'];}
    $response['session_id']=(string)$ss['session_id'];$response['next_token']=(string)$ss['next_token'];$response['expires_at']=(int)$ss['expires_at'];$response['idle_expires_at']=(int)$ss['idle_expires_at'];
    $done=kicomRequestGuardCompleteV4($sessionId,$requestId,$operation,$params,$presentedToken,$claim,$response);
    if(empty($done['ok']))return ['ok'=>false,'code'=>'REQUEST_REPLAY_PERSIST_FAILED','detail'=>(string)($done['code']??'UNKNOWN'),'action_response'=>$response,'inspect_required'=>true];
    return $response;
}
