<?php
declare(strict_types=1);

/* KiCom normal-session recovery candidate v1.
 * Candidate-only. This does not authorize RED/production/kernel actions.
 * It only reissues a normal rolling action token for an already-valid autonomy session.
 */

function kicomSessionRecoveryLockFile(string $sessionId): string {
    return kicomAuthSessionsDir().'/'.$sessionId.'.recovery.lock';
}

function kicomSessionRecoveryRequestId(string $requestId): ?string {
    $requestId=trim($requestId);
    return preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$requestId)?$requestId:null;
}

function kicomSessionRecoveryHandle(string $handle): ?string {
    $handle=strtolower(trim($handle));
    return preg_match('/^[a-f0-9]{64}$/',$handle)?$handle:null;
}

function kicomSessionRecoveryProvision(string $sessionId): array {
    $sessionId=strtolower(trim($sessionId));
    if(!preg_match('/^[a-f0-9]{24}$/',$sessionId))return ['ok'=>false,'code'=>'SESSION_AUTH_INVALID'];
    $file=kicomAutonomySessionFile($sessionId);
    $lock=@fopen(kicomSessionRecoveryLockFile($sessionId),'c+');
    if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);return ['ok'=>false,'code'=>'SESSION_RECOVERY_LOCK_FAILED'];}
    try{
        $row=kicomAuthJsonRead($file);if(!is_array($row))return ['ok'=>false,'code'=>'SESSION_NOT_FOUND'];
        $now=time();
        if((int)($row['absolute_expires_at']??0)<$now||(int)($row['idle_expires_at']??0)<$now)return ['ok'=>false,'code'=>'SESSION_EXPIRED'];
        if(is_string($row['recovery_handle_hash']??null)&&(string)$row['recovery_handle_hash']!=='')return ['ok'=>false,'code'=>'SESSION_RECOVERY_ALREADY_PROVISIONED'];
        $handle=kicomAuthRandomHex(32);
        $row['recovery_handle_hash']=hash('sha256',$handle);
        $row['recovery_created_at']=gmdate('c');
        $row['recovery_count']=0;
        $row['recovery_limit']=16;
        $row['recovery_generation']=0;
        $row['recovery_seen']=[];
        $row['recovery_last_request_hash']='';
        $row['recovery_last_generation']=0;
        $row['recovery_last_until']=0;
        if(!kicomAuthJsonWrite($file,$row))return ['ok'=>false,'code'=>'SESSION_RECOVERY_WRITE_FAILED'];
        kicomLivingEvent('autonomy_session_recovery_provisioned','info',['session_id'=>$sessionId]);
        return ['ok'=>true,'code'=>'SESSION_RECOVERY_PROVISIONED','session_id'=>$sessionId,'recovery_handle'=>$handle,'expires_at'=>(int)$row['absolute_expires_at']];
    }finally{@flock($lock,LOCK_UN);@fclose($lock);}
}

function kicomAutonomySessionOpenWithRecovery(string $code): array {
    $opened=kicomAutonomySessionOpen($code);
    if(empty($opened['ok']))return $opened;
    $sid=(string)($opened['session_id']??'');
    $recovery=kicomSessionRecoveryProvision($sid);
    if(empty($recovery['ok'])){
        @unlink(kicomAutonomySessionFile($sid));
        @unlink(kicomSessionRecoveryLockFile($sid));
        kicomLivingEvent('autonomy_session_recovery_provision_failed','warn',['session_id'=>$sid,'code'=>(string)($recovery['code']??'UNKNOWN')]);
        return ['ok'=>false,'code'=>'SESSION_RECOVERY_PROVISION_FAILED'];
    }
    $opened['recovery_handle']=(string)$recovery['recovery_handle'];
    $opened['recovery_expires_at']=(int)$recovery['expires_at'];
    $opened['recovery_limit']=16;
    return $opened;
}

function kicomSessionRecoveryDeriveToken(string $handle,string $sessionId,string $requestId,int $generation): string {
    return hash_hmac('sha256','kicom-session-recover-v1|'.$sessionId.'|'.$requestId.'|'.$generation,$handle);
}

function kicomSessionRecoveryRecover(string $sessionId,string $handle,string $requestId): array {
    $sessionId=strtolower(trim($sessionId));
    $handle=kicomSessionRecoveryHandle($handle)??'';
    $requestId=kicomSessionRecoveryRequestId($requestId)??'';
    if(!preg_match('/^[a-f0-9]{24}$/',$sessionId)||$handle===''||$requestId==='')return ['ok'=>false,'code'=>'SESSION_RECOVERY_INPUT_INVALID'];
    $file=kicomAutonomySessionFile($sessionId);
    $lock=@fopen(kicomSessionRecoveryLockFile($sessionId),'c+');
    if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);return ['ok'=>false,'code'=>'SESSION_RECOVERY_LOCK_FAILED'];}
    try{
        $row=kicomAuthJsonRead($file);if(!is_array($row))return ['ok'=>false,'code'=>'SESSION_NOT_FOUND'];
        $policy=kicomAutonomyPolicy();if(empty($policy['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];
        $now=time();
        if((int)($row['absolute_expires_at']??0)<$now||(int)($row['idle_expires_at']??0)<$now)return ['ok'=>false,'code'=>'SESSION_EXPIRED'];
        $stored=(string)($row['recovery_handle_hash']??'');
        if($stored===''||!hash_equals($stored,hash('sha256',$handle)))return ['ok'=>false,'code'=>'SESSION_RECOVERY_REJECTED'];
        $requestHash=hash('sha256',$requestId);
        $lastHash=(string)($row['recovery_last_request_hash']??'');
        $lastUntil=(int)($row['recovery_last_until']??0);
        $lastGeneration=(int)($row['recovery_last_generation']??0);
        if($lastHash!==''&&hash_equals($lastHash,$requestHash)){
            if($lastUntil<$now)return ['ok'=>false,'code'=>'SESSION_RECOVERY_REPLAY_EXPIRED'];
            $same=kicomSessionRecoveryDeriveToken($handle,$sessionId,$requestId,$lastGeneration);
            if(!hash_equals((string)($row['token_hash']??''),hash('sha256',$same)))return ['ok'=>false,'code'=>'SESSION_RECOVERY_SUPERSEDED'];
            return ['ok'=>true,'code'=>'SESSION_RECOVERY_REPLAY','session_id'=>$sessionId,'next_token'=>$same,
                    'expires_at'=>(int)$row['absolute_expires_at'],'idle_expires_at'=>(int)$row['idle_expires_at'],'replayed'=>true];
        }
        $seen=is_array($row['recovery_seen']??null)?$row['recovery_seen']:[];
        foreach($seen as $seenHash)if(is_string($seenHash)&&hash_equals($seenHash,$requestHash))return ['ok'=>false,'code'=>'SESSION_RECOVERY_REQUEST_REUSED'];
        $count=(int)($row['recovery_count']??0);$limit=max(1,min(32,(int)($row['recovery_limit']??16)));
        if($count>=$limit)return ['ok'=>false,'code'=>'SESSION_RECOVERY_LIMIT_REACHED'];
        $generation=(int)($row['recovery_generation']??0)+1;
        $next=kicomSessionRecoveryDeriveToken($handle,$sessionId,$requestId,$generation);
        $row['token_hash']=hash('sha256',$next);
        $row['previous_token_hash']='';$row['previous_token_until']=0;
        $row['recovery_generation']=$generation;
        $row['recovery_count']=$count+1;
        $seen[]=$requestHash;$row['recovery_seen']=array_slice($seen,-16);
        $row['recovery_last_request_hash']=$requestHash;
        $row['recovery_last_generation']=$generation;
        $row['recovery_last_until']=$now+180;
        $row['recovered_at']=gmdate('c');
        $row['last_used_at']=gmdate('c');
        $row['idle_expires_at']=min((int)$row['absolute_expires_at'],$now+(int)$policy['idle_ttl']);
        if(!kicomAuthJsonWrite($file,$row))return ['ok'=>false,'code'=>'SESSION_RECOVERY_WRITE_FAILED'];
        kicomLivingEvent('autonomy_session_recovered','info',['session_id'=>$sessionId,'recovery_count'=>$row['recovery_count']]);
        return ['ok'=>true,'code'=>'SESSION_RECOVERED','session_id'=>$sessionId,'next_token'=>$next,
                'expires_at'=>(int)$row['absolute_expires_at'],'idle_expires_at'=>(int)$row['idle_expires_at'],'replayed'=>false];
    }finally{@flock($lock,LOCK_UN);@fclose($lock);}
}

function kicomSessionRecoveryStatus(string $sessionId): array {
    $sessionId=strtolower(trim($sessionId));if(!preg_match('/^[a-f0-9]{24}$/',$sessionId))return ['ok'=>false,'code'=>'SESSION_AUTH_INVALID'];
    $row=kicomAuthJsonRead(kicomAutonomySessionFile($sessionId));if(!is_array($row))return ['ok'=>false,'code'=>'SESSION_NOT_FOUND'];
    return ['ok'=>true,'code'=>'OK','session_id'=>$sessionId,'configured'=>(string)($row['recovery_handle_hash']??'')!=='',
            'recovery_count'=>(int)($row['recovery_count']??0),'recovery_limit'=>(int)($row['recovery_limit']??0),
            'absolute_expires_at'=>(int)($row['absolute_expires_at']??0),'idle_expires_at'=>(int)($row['idle_expires_at']??0)];
}
