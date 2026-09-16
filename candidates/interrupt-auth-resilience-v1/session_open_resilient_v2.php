<?php
declare(strict_types=1);
require_once __DIR__.'/session_lock_v2.php';

/* Idempotent normal-session open. A successfully consumed FreeOTP open can be
 * replayed briefly for the same request_id/code only while its initial action
 * token is still current. No secondary recovery bearer is created. */
function kicomSessionOpenResilienceDirV2(): string { return kicomAuthDir().'/session_open_resilience_v2'; }
function kicomSessionOpenResilienceKeyFileV2(): string { return kicomSessionOpenResilienceDirV2().'/master.key'; }
function kicomSessionOpenResilienceReceiptsDirV2(): string { return kicomSessionOpenResilienceDirV2().'/receipts'; }
function kicomSessionOpenResilienceEnsureV2(): bool {
    foreach([kicomSessionOpenResilienceDirV2(),kicomSessionOpenResilienceReceiptsDirV2()] as $d)if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;
    $deny=kicomSessionOpenResilienceDirV2().'/.htaccess';if(!is_file($deny))@file_put_contents($deny,"Require all denied\n",LOCK_EX);
    $kf=kicomSessionOpenResilienceKeyFileV2();if(!is_file($kf)){$key=random_bytes(32);if(@file_put_contents($kf,$key,LOCK_EX)===false)return false;@chmod($kf,0600);} $raw=@file_get_contents($kf);return is_string($raw)&&strlen($raw)===32;
}
function kicomSessionOpenResilienceKeyV2(): ?string { if(!kicomSessionOpenResilienceEnsureV2())return null;$raw=@file_get_contents(kicomSessionOpenResilienceKeyFileV2());return is_string($raw)&&strlen($raw)===32?$raw:null; }
function kicomSessionOpenRequestIdV2(string $requestId): ?string {$requestId=trim($requestId);return preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$requestId)?$requestId:null;}
function kicomSessionOpenReceiptFileV2(string $requestId): ?string {$requestId=kicomSessionOpenRequestIdV2($requestId)??'';return $requestId===''?null:kicomSessionOpenResilienceReceiptsDirV2().'/'.hash('sha256',$requestId).'.json';}
function kicomSessionOpenCleanupV2(): void {$now=time();foreach(glob(kicomSessionOpenResilienceReceiptsDirV2().'/*.json')?:[] as $f){$r=kicomAuthJsonRead($f);if(!is_array($r)||(int)($r['expires_at']??0)<$now){@unlink($f);@unlink($f.'.lock');}}}
function kicomSessionOpenDeriveTokenV2(string $master,string $sessionId,string $requestId,string $nonce): string {return hash_hmac('sha256','kicom-open-v2|token|'.$sessionId.'|'.$requestId.'|'.$nonce,$master);}
function kicomSessionOpenIdempotentV2(string $code,string $requestId): array {
    $requestId=kicomSessionOpenRequestIdV2($requestId)??'';if($requestId==='')return ['ok'=>false,'code'=>'SESSION_OPEN_REQUEST_ID_INVALID'];
    $master=kicomSessionOpenResilienceKeyV2();if($master===null)return ['ok'=>false,'code'=>'SESSION_OPEN_RESILIENCE_UNAVAILABLE'];kicomSessionOpenCleanupV2();
    $rf=kicomSessionOpenReceiptFileV2($requestId);if($rf===null)return ['ok'=>false,'code'=>'SESSION_OPEN_REQUEST_ID_INVALID'];$lock=@fopen($rf.'.lock','c+');if(is_resource($lock))@chmod($rf.'.lock',0600);
    if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);return ['ok'=>false,'code'=>'SESSION_OPEN_LOCK_FAILED'];}
    try{
        $now=time();$codeTag=hash_hmac('sha256',$code,$master);$old=kicomAuthJsonRead($rf);
        if(is_array($old)&&(int)($old['expires_at']??0)>=$now){
            if(!hash_equals((string)($old['code_tag']??''),$codeTag))return ['ok'=>false,'code'=>'SESSION_OPEN_REQUEST_CONFLICT'];
            $sid=(string)($old['session_id']??'');$nonce=(string)($old['nonce']??'');if(!preg_match('/^[a-f0-9]{24}$/',$sid))return ['ok'=>false,'code'=>'SESSION_OPEN_REPLAY_SESSION_MISSING'];
            $sl=@fopen(kicomNormalSessionLockFile($sid),'c+');if(is_resource($sl))@chmod(kicomNormalSessionLockFile($sid),0600);if($sl===false||!@flock($sl,LOCK_EX)){if(is_resource($sl))@fclose($sl);return ['ok'=>false,'code'=>'SESSION_LOCK_FAILED'];}
            try{$row=kicomAuthJsonRead(kicomAutonomySessionFile($sid));if(!is_array($row))return ['ok'=>false,'code'=>'SESSION_OPEN_REPLAY_SESSION_MISSING'];if((int)($row['absolute_expires_at']??0)<$now||(int)($row['idle_expires_at']??0)<$now)return ['ok'=>false,'code'=>'SESSION_EXPIRED'];$token=kicomSessionOpenDeriveTokenV2($master,$sid,$requestId,$nonce);if(!hash_equals((string)($row['token_hash']??''),hash('sha256',$token)))return ['ok'=>false,'code'=>'SESSION_OPEN_REPLAY_SUPERSEDED'];return ['ok'=>true,'code'=>'SESSION_OPEN_REPLAY','session_id'=>$sid,'token'=>$token,'expires_in'=>max(0,(int)$row['absolute_expires_at']-$now),'idle_expires_in'=>max(0,(int)$row['idle_expires_at']-$now),'replayed'=>true];}finally{@flock($sl,LOCK_UN);@fclose($sl);}
        }
        $policy=kicomAutonomyPolicy();if(empty($policy['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];$grace=max(0,min(4,(int)($policy['session_totp_past_steps']??2)));$v=kicomTotpVerifyConsumeWindow($code,'autonomy_session_open',$grace);if(empty($v['ok']))return $v;
        $sid=substr(kicomAuthRandomHex(12),0,24);$nonce=kicomAuthRandomHex(16);$token=kicomSessionOpenDeriveTokenV2($master,$sid,$requestId,$nonce);
        $row=['schema'=>1,'id'=>$sid,'token_hash'=>hash('sha256',$token),'created_at'=>gmdate('c'),'created_epoch'=>$now,'last_used_at'=>gmdate('c'),'absolute_expires_at'=>$now+(int)$policy['session_ttl'],'idle_expires_at'=>$now+(int)$policy['idle_ttl'],'scope'=>'autonomy'];
        if(!kicomAuthJsonWrite(kicomAutonomySessionFile($sid),$row))return ['ok'=>false,'code'=>'SESSION_WRITE_FAILED'];
        $receipt=['schema'=>2,'request_hash'=>hash('sha256',$requestId),'code_tag'=>$codeTag,'session_id'=>$sid,'nonce'=>$nonce,'created_at'=>gmdate('c'),'expires_at'=>$now+180];
        if(!kicomAuthJsonWrite($rf,$receipt)){@unlink(kicomAutonomySessionFile($sid));return ['ok'=>false,'code'=>'SESSION_OPEN_RECEIPT_WRITE_FAILED'];}
        kicomLivingEvent('autonomy_session_opened','info',['session_id'=>$sid,'totp_age_steps'=>(int)($v['age_steps']??0),'idempotent_open_v2'=>true]);
        return ['ok'=>true,'code'=>'SESSION_OPENED','session_id'=>$sid,'token'=>$token,'expires_in'=>(int)$policy['session_ttl'],'idle_expires_in'=>(int)$policy['idle_ttl'],'totp_age_steps'=>(int)($v['age_steps']??0),'totp_period'=>(int)($v['period']??30),'replayed'=>false];
    }finally{@flock($lock,LOCK_UN);@fclose($lock);}
}
