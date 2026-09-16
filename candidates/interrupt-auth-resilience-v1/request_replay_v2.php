<?php
declare(strict_types=1);

/* KiCom interrupt/auth resilience candidate v2.
 * Purpose: replay the exact response of an already executed normal-autonomy request
 * when the client lost the response/next rolling token.
 * Security: same session + request_id + request fingerprint + presented old token only.
 * This grants no new action and does not alter RED/production/kernel approval semantics.
 */

function kicomReplayDir(): string { return kicomAuthDir().'/request_replay'; }
function kicomReplayEnsure(): bool { $d=kicomReplayDir(); return is_dir($d)||(@mkdir($d,0700,true)&&is_dir($d)); }
function kicomReplayRequestId(string $id): ?string {
    $id=trim($id); return preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$id)?$id:null;
}
function kicomReplayFile(string $sessionId,string $requestId): string {
    return kicomReplayDir().'/'.hash('sha256',$sessionId.'|'.$requestId).'.json';
}
function kicomReplayFingerprint(string $operation,array $params=[]): string {
    foreach(['token','session_id','code','recovery_handle','password','secret','totp'] as $k)unset($params[$k]);
    ksort($params,SORT_STRING);
    return hash('sha256',json_encode([$operation,$params],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'');
}
function kicomReplayKey(string $presentedToken,string $requestId): string {
    return hash('sha256','kicom-request-replay-v2|'.$requestId.'|'.$presentedToken,true);
}
function kicomReplaySeal(array $response,string $presentedToken,string $requestId): ?array {
    if(!function_exists('openssl_encrypt'))return null;
    $plain=json_encode($response,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($plain===false)return null;
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',kicomReplayKey($presentedToken,$requestId),OPENSSL_RAW_DATA,$iv,$tag,'kicom-request-replay-v2');
    if($cipher===false)return null;
    return ['iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'cipher'=>base64_encode($cipher)];
}
function kicomReplayOpen(array $sealed,string $presentedToken,string $requestId): ?array {
    if(!function_exists('openssl_decrypt'))return null;
    $iv=base64_decode((string)($sealed['iv']??''),true);$tag=base64_decode((string)($sealed['tag']??''),true);$cipher=base64_decode((string)($sealed['cipher']??''),true);
    if($iv===false||$tag===false||$cipher===false)return null;
    $plain=openssl_decrypt($cipher,'aes-256-gcm',kicomReplayKey($presentedToken,$requestId),OPENSSL_RAW_DATA,$iv,$tag,'kicom-request-replay-v2');
    if($plain===false)return null;$r=json_decode($plain,true);return is_array($r)?$r:null;
}
function kicomReplayLookup(string $sessionId,string $requestId,string $operation,array $params,string $presentedToken): array {
    $requestId=kicomReplayRequestId($requestId)??'';if($requestId==='')return ['ok'=>false,'code'=>'REPLAY_REQUEST_ID_INVALID'];
    $row=kicomAuthJsonRead(kicomReplayFile($sessionId,$requestId));if(!is_array($row))return ['ok'=>false,'code'=>'REPLAY_MISS'];
    if((int)($row['expires_at']??0)<time())return ['ok'=>false,'code'=>'REPLAY_EXPIRED'];
    if(!hash_equals((string)($row['session_id']??''),$sessionId))return ['ok'=>false,'code'=>'REPLAY_SESSION_MISMATCH'];
    if(!hash_equals((string)($row['fingerprint']??''),kicomReplayFingerprint($operation,$params)))return ['ok'=>false,'code'=>'REPLAY_FINGERPRINT_MISMATCH'];
    if(!hash_equals((string)($row['presented_token_hash']??''),hash('sha256',$presentedToken)))return ['ok'=>false,'code'=>'REPLAY_TOKEN_MISMATCH'];
    $response=kicomReplayOpen(is_array($row['sealed']??null)?$row['sealed']:[],$presentedToken,$requestId);
    if(!is_array($response))return ['ok'=>false,'code'=>'REPLAY_DECRYPT_FAILED'];
    return ['ok'=>true,'code'=>'REPLAY_HIT','response'=>$response,'replayed'=>true];
}
function kicomReplayStore(string $sessionId,string $requestId,string $operation,array $params,string $presentedToken,array $response,int $ttl=1800): array {
    $requestId=kicomReplayRequestId($requestId)??'';if($requestId==='')return ['ok'=>false,'code'=>'REPLAY_REQUEST_ID_INVALID'];
    if(!kicomReplayEnsure())return ['ok'=>false,'code'=>'REPLAY_STORAGE_UNAVAILABLE'];
    $sealed=kicomReplaySeal($response,$presentedToken,$requestId);if($sealed===null)return ['ok'=>false,'code'=>'REPLAY_SEAL_FAILED'];
    $row=['schema'=>2,'session_id'=>$sessionId,'request_id_hash'=>hash('sha256',$requestId),'fingerprint'=>kicomReplayFingerprint($operation,$params),'presented_token_hash'=>hash('sha256',$presentedToken),'sealed'=>$sealed,'created_at'=>gmdate('c'),'expires_at'=>time()+max(60,min(3600,$ttl))];
    $file=kicomReplayFile($sessionId,$requestId);
    if(is_file($file)){$existing=kicomAuthJsonRead($file);if(is_array($existing)){
        if(!hash_equals((string)($existing['fingerprint']??''),(string)$row['fingerprint']))return ['ok'=>false,'code'=>'REPLAY_REQUEST_ID_CONFLICT'];
        return ['ok'=>true,'code'=>'REPLAY_ALREADY_STORED'];
    }}
    if(!kicomAuthJsonWrite($file,$row))return ['ok'=>false,'code'=>'REPLAY_WRITE_FAILED'];
    return ['ok'=>true,'code'=>'REPLAY_STORED'];
}
