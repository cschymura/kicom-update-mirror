<?php
declare(strict_types=1);

/* Receipt semantics v2: a request_id is never automatically re-executed once claimed.
 * Expired IN_PROGRESS becomes UNCERTAIN and requires status inspection. */
function kicomResilienceReceiptBeginV2(string $sessionId,string $requestId,string $operation,string $requestSha,int $ttl=1800): array {
    if(!kicomResilienceEnsure())return ['ok'=>false,'code'=>'RESILIENCE_STORAGE_UNAVAILABLE'];
    $file=kicomResilienceReceiptFile($sessionId,$requestId);$requestSha=strtolower(trim($requestSha));
    if($file===null||!preg_match('/^[a-f0-9]{64}$/',$requestSha))return ['ok'=>false,'code'=>'RECEIPT_INPUT_INVALID'];
    $old=kicomResilienceJsonRead($file);$now=time();
    if(is_array($old)){
        if(!hash_equals((string)($old['request_sha256']??''),$requestSha)||!hash_equals((string)($old['operation']??''),$operation))return ['ok'=>false,'code'=>'REQUEST_ID_CONFLICT'];
        $status=(string)($old['status']??'');
        if($status==='IN_PROGRESS'&&(int)($old['expires_at']??0)<$now){$old['status']='UNCERTAIN';$old['uncertain_at']=gmdate('c');kicomResilienceJsonWrite($file,$old);$status='UNCERTAIN';}
        if(in_array($status,['IN_PROGRESS','UNCERTAIN','DONE'],true))return ['ok'=>true,'code'=>'REQUEST_REPLAY','receipt'=>$old];
        return ['ok'=>false,'code'=>'REQUEST_RECEIPT_STATE_INVALID'];
    }
    $claim=bin2hex(random_bytes(16));$row=['schema'=>2,'session_hash'=>hash('sha256',$sessionId),'request_id'=>$requestId,'operation'=>$operation,'request_sha256'=>$requestSha,'status'=>'IN_PROGRESS','claim_hash'=>hash('sha256',$claim),'result'=>null,'created_at'=>gmdate('c'),'expires_at'=>$now+max(60,min(3600,$ttl))];
    if(!kicomResilienceJsonWrite($file,$row))return ['ok'=>false,'code'=>'RECEIPT_WRITE_FAILED'];return ['ok'=>true,'code'=>'REQUEST_CLAIMED','claim'=>$claim];
}
function kicomResilienceReceiptCommitV2(string $sessionId,string $requestId,string $claim,array $result): array {
    $file=kicomResilienceReceiptFile($sessionId,$requestId);if($file===null)return ['ok'=>false,'code'=>'RECEIPT_INPUT_INVALID'];$row=kicomResilienceJsonRead($file);if(!is_array($row))return ['ok'=>false,'code'=>'RECEIPT_NOT_FOUND'];
    if(!hash_equals((string)($row['claim_hash']??''),hash('sha256',$claim)))return ['ok'=>false,'code'=>'RECEIPT_CLAIM_INVALID'];
    if(($row['status']??'')==='DONE')return ['ok'=>true,'code'=>'REQUEST_ALREADY_COMMITTED','receipt'=>$row];
    if(!in_array((string)($row['status']??''),['IN_PROGRESS','UNCERTAIN'],true))return ['ok'=>false,'code'=>'REQUEST_RECEIPT_STATE_INVALID'];
    $row['status']='DONE';$row['result']=kicomResilienceSanitize($result);$row['completed_at']=gmdate('c');if(!kicomResilienceJsonWrite($file,$row))return ['ok'=>false,'code'=>'RECEIPT_WRITE_FAILED'];return ['ok'=>true,'code'=>'REQUEST_COMMITTED','receipt'=>$row];
}
