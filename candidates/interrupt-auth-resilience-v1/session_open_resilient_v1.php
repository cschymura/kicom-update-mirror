<?php
declare(strict_types=1);

/* KiCom idempotent normal-session open candidate v1.
 * A successfully consumed FreeOTP open may be replayed for the same request_id
 * for a short window without storing plaintext TOTP/session/recovery secrets.
 */

function kicomSessionOpenResilienceDir(): string { return kicomAuthDir().'/session_open_resilience'; }
function kicomSessionOpenResilienceKeyFile(): string { return kicomSessionOpenResilienceDir().'/master.key'; }
function kicomSessionOpenResilienceReceiptsDir(): string { return kicomSessionOpenResilienceDir().'/receipts'; }

function kicomSessionOpenResilienceEnsure(): bool {
    foreach([kicomSessionOpenResilienceDir(),kicomSessionOpenResilienceReceiptsDir()] as $d)
        if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;
    $deny=kicomSessionOpenResilienceDir().'/.htaccess';if(!is_file($deny))@file_put_contents($deny,"Require all denied\n",LOCK_EX);
    $kf=kicomSessionOpenResilienceKeyFile();
    if(!is_file($kf)){
        $key=random_bytes(32);if(@file_put_contents($kf,$key,LOCK_EX)===false)return false;@chmod($kf,0600);
    }
    $raw=@file_get_contents($kf);return is_string($raw)&&strlen($raw)===32;
}

function kicomSessionOpenResilienceKey(): ?string {
    if(!kicomSessionOpenResilienceEnsure())return null;$raw=@file_get_contents(kicomSessionOpenResilienceKeyFile());return is_string($raw)&&strlen($raw)===32?$raw:null;
}

function kicomSessionOpenRequestId(string $requestId): ?string {
    $requestId=trim($requestId);return preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$requestId)?$requestId:null;
}

function kicomSessionOpenReceiptFile(string $requestId): ?string {
    $requestId=kicomSessionOpenRequestId($requestId)??'';if($requestId==='')return null;
    return kicomSessionOpenResilienceReceiptsDir().'/'.hash('sha256',$requestId).'.json';
}

function kicomSessionOpenResilienceCleanup(): void {
    $now=time();foreach(glob(kicomSessionOpenResilienceReceiptsDir().'/*.json')?:[] as $f){$r=kicomAuthJsonRead($f);if(!is_array($r)||(int)($r['expires_at']??0)<$now){@unlink($f);@unlink($f.'.lock');}}
}

function kicomSessionOpenDerive(string $master,string $label,string $sessionId,string $requestId,string $nonce): string {
    return hash_hmac('sha256','kicom-open-v1|'.$label.'|'.$sessionId.'|'.$requestId.'|'.$nonce,$master);
}

function kicomSessionOpenIdempotent(string $code,string $requestId): array {
    $requestId=kicomSessionOpenRequestId($requestId)??'';if($requestId==='')return ['ok'=>false,'code'=>'SESSION_OPEN_REQUEST_ID_INVALID'];
    $master=kicomSessionOpenResilienceKey();if($master===null)return ['ok'=>false,'code'=>'SESSION_OPEN_RESILIENCE_UNAVAILABLE'];
    kicomSessionOpenResilienceCleanup();
    $receiptFile=kicomSessionOpenReceiptFile($requestId);if($receiptFile===null)return ['ok'=>false,'code'=>'SESSION_OPEN_REQUEST_ID_INVALID'];
    $lock=@fopen($receiptFile.'.lock','c+');if(is_resource($lock))@chmod($receiptFile.'.lock',0600);if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);return ['ok'=>false,'code'=>'SESSION_OPEN_LOCK_FAILED'];}
    try{
        $now=time();$codeTag=hash_hmac('sha256',$code,$master);$old=kicomAuthJsonRead($receiptFile);
        if(is_array($old)&&(int)($old['expires_at']??0)>=$now){
            if(!hash_equals((string)($old['code_tag']??''),$codeTag))return ['ok'=>false,'code'=>'SESSION_OPEN_REQUEST_CONFLICT'];
            $sid=(string)($old['session_id']??'');$nonce=(string)($old['nonce']??'');
            if(!preg_match('/^[a-f0-9]{24}$/',$sid))return ['ok'=>false,'code'=>'SESSION_OPEN_REPLAY_SESSION_MISSING'];
            $sessionLock=@fopen(kicomSessionRecoveryLockFile($sid),'c+');if(is_resource($sessionLock))@chmod(kicomSessionRecoveryLockFile($sid),0600);
            if($sessionLock===false||!@flock($sessionLock,LOCK_EX)){if(is_resource($sessionLock))@fclose($sessionLock);return ['ok'=>false,'code'=>'SESSION_LOCK_FAILED'];}
            try{
                $row=kicomAuthJsonRead(kicomAutonomySessionFile($sid));
                if(!is_array($row))return ['ok'=>false,'code'=>'SESSION_OPEN_REPLAY_SESSION_MISSING'];
                if((int)($row['absolute_expires_at']??0)<$now||(int)($row['idle_expires_at']??0)<$now)return ['ok'=>false,'code'=>'SESSION_EXPIRED'];
                $token=kicomSessionOpenDerive($master,'token',$sid,$requestId,$nonce);$handle=kicomSessionOpenDerive($master,'recovery',$sid,$requestId,$nonce);
                if(!hash_equals((string)($row['token_hash']??''),hash('sha256',$token)))return ['ok'=>false,'code'=>'SESSION_OPEN_REPLAY_SUPERSEDED'];
                if(!hash_equals((string)($row['recovery_handle_hash']??''),hash('sha256',$handle)))return ['ok'=>false,'code'=>'SESSION_OPEN_REPLAY_RECOVERY_MISMATCH'];
                return ['ok'=>true,'code'=>'SESSION_OPEN_REPLAY','session_id'=>$sid,'token'=>$token,'recovery_handle'=>$handle,
                        'expires_in'=>max(0,(int)$row['absolute_expires_at']-$now),'idle_expires_in'=>max(0,(int)$row['idle_expires_at']-$now),
                        'replayed'=>true,'recovery_limit'=>(int)($row['recovery_limit']??16)];
            }finally{@flock($sessionLock,LOCK_UN);@fclose($sessionLock);}
        }
        $policy=kicomAutonomyPolicy();if(empty($policy['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];
        $grace=max(0,min(4,(int)($policy['session_totp_past_steps']??2)));$v=kicomTotpVerifyConsumeWindow($code,'autonomy_session_open',$grace);if(empty($v['ok']))return $v;
        $sid=substr(kicomAuthRandomHex(12),0,24);$nonce=kicomAuthRandomHex(16);
        $token=kicomSessionOpenDerive($master,'token',$sid,$requestId,$nonce);$handle=kicomSessionOpenDerive($master,'recovery',$sid,$requestId,$nonce);
        $row=['schema'=>2,'id'=>$sid,'token_hash'=>hash('sha256',$token),'created_at'=>gmdate('c'),'created_epoch'=>$now,'last_used_at'=>gmdate('c'),
              'absolute_expires_at'=>$now+(int)$policy['session_ttl'],'idle_expires_at'=>$now+(int)$policy['idle_ttl'],'scope'=>'autonomy',
              'recovery_handle_hash'=>hash('sha256',$handle),'recovery_created_at'=>gmdate('c'),'recovery_count'=>0,'recovery_limit'=>16,
              'recovery_generation'=>0,'recovery_seen'=>[],'recovery_last_request_hash'=>'','recovery_last_generation'=>0,'recovery_last_until'=>0];
        if(!kicomAuthJsonWrite(kicomAutonomySessionFile($sid),$row))return ['ok'=>false,'code'=>'SESSION_WRITE_FAILED'];
        $receipt=['schema'=>1,'request_hash'=>hash('sha256',$requestId),'code_tag'=>$codeTag,'session_id'=>$sid,'nonce'=>$nonce,
                  'created_at'=>gmdate('c'),'expires_at'=>$now+180];
        if(!kicomAuthJsonWrite($receiptFile,$receipt)){@unlink(kicomAutonomySessionFile($sid));return ['ok'=>false,'code'=>'SESSION_OPEN_RECEIPT_WRITE_FAILED'];}
        kicomLivingEvent('autonomy_session_opened','info',['session_id'=>$sid,'totp_age_steps'=>(int)($v['age_steps']??0),'idempotent_open'=>true]);
        return ['ok'=>true,'code'=>'SESSION_OPENED','session_id'=>$sid,'token'=>$token,'recovery_handle'=>$handle,
                'expires_in'=>(int)$policy['session_ttl'],'idle_expires_in'=>(int)$policy['idle_ttl'],'totp_age_steps'=>(int)($v['age_steps']??0),
                'totp_period'=>(int)($v['period']??30),'replayed'=>false,'recovery_limit'=>16];
    }finally{@flock($lock,LOCK_UN);@fclose($lock);}
}
