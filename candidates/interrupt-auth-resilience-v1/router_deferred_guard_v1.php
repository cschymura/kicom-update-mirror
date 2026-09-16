<?php
declare(strict_types=1);

/* Candidate integration helper for KiCom 0.9.14.
 * External protocol uses client_request_id; FACT request_id remains the server request id.
 * Requires request_guard_v5.php and request_replay_v3.php.
 */
function kicomClientRequestIdV2(array $get,array $post=[]): string {
    $v=(string)($post['client_request_id']??$get['client_request_id']??'');
    return preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$v)?$v:'';
}
function kicomDeferredGuardContextV1(): ?array {
    $x=$GLOBALS['kicom_deferred_guard_v1']??null;
    return is_array($x)?$x:null;
}
function kicomDeferredGuardClientRequestIdV1(): string {
    $x=kicomDeferredGuardContextV1();
    return is_array($x)?(string)($x['client_request_id']??''):'';
}
function kicomDeferredGuardClearV1(): void {
    unset($GLOBALS['kicom_deferred_guard_v1']);
}
function kicomDeferredGuardPreflightV1(string $sessionId,string $presentedToken,string $clientRequestId,string $operation,array $params): array {
    $clientRequestId=trim($clientRequestId);
    if($clientRequestId==='')return ['ok'=>true,'mode'=>'LEGACY'];
    $g=kicomRequestGuardBeginV5($sessionId,$clientRequestId,$operation,$params,$presentedToken);
    if(empty($g['ok']))return $g;
    $mode=(string)($g['mode']??'');
    if($mode==='REPLAY'){
        $r=is_array($g['response']??null)?$g['response']:[];
        $lines=$r['lines']??null;$status=(int)($r['status']??0);
        if(!is_array($lines)||$status<100||$status>599)return ['ok'=>false,'code'=>'REQUEST_REPLAY_INVALID'];
        return ['ok'=>true,'mode'=>'REPLAY','lines'=>array_values(array_map('strval',$lines)),'status'=>$status];
    }
    if($mode==='IN_FLIGHT')return ['ok'=>true,'mode'=>'IN_FLIGHT','code'=>'REQUEST_IN_FLIGHT'];
    if($mode==='UNCERTAIN')return ['ok'=>true,'mode'=>'UNCERTAIN','code'=>'REQUEST_UNCERTAIN'];
    if($mode!=='EXECUTE')return ['ok'=>false,'code'=>'REQUEST_GUARD_MODE_INVALID'];
    $GLOBALS['kicom_deferred_guard_v1']=[
        'session_id'=>$sessionId,
        'presented_token'=>$presentedToken,
        'client_request_id'=>$clientRequestId,
        'operation'=>$operation,
        'params'=>$params,
        'claim'=>(string)($g['claim']??''),
    ];
    return ['ok'=>true,'mode'=>'EXECUTE'];
}
function kicomDeferredGuardCompleteV1(array $lines,int $status): array {
    $ctx=kicomDeferredGuardContextV1();
    if(!is_array($ctx))return ['ok'=>true,'code'=>'NO_DEFERRED_GUARD'];
    kicomDeferredGuardClearV1();
    $response=['status'=>$status,'lines'=>array_values(array_map('strval',$lines))];
    return kicomRequestGuardCompleteV5(
        (string)$ctx['session_id'],(string)$ctx['client_request_id'],(string)$ctx['operation'],
        is_array($ctx['params']??null)?$ctx['params']:[],(string)$ctx['presented_token'],(string)$ctx['claim'],$response
    );
}
function kicomDeferredGuardErrorLinesV1(string $serverRequestId,string $clientRequestId,string $code): array {
    return [KCL_PROTOCOL,'ERROR request_recovery','FACT request_id='.kclString($serverRequestId),'FACT client_request_id='.kclString($clientRequestId),'FACT code='.kclString($code),'RULE do-not-reexecute-blindly','END'];
}
