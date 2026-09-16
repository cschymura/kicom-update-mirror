<?php
declare(strict_types=1);
require_once __DIR__.'/session_lock_v2.php';

/* Locked normal-session token consumer. Preserves KiCom rolling-token semantics
 * and the existing 60-second previous-token one-use recovery window. */
function kicomAutonomySessionConsumeResilientV2(string $id,string $token): array {
    $policy=kicomAutonomyPolicy();if(empty($policy['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];
    $id=strtolower(trim($id));$token=strtolower(trim($token));
    if(!preg_match('/^[a-f0-9]{24}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$token))return ['ok'=>false,'code'=>'SESSION_AUTH_INVALID'];
    $file=kicomAutonomySessionFile($id);$lock=@fopen(kicomNormalSessionLockFile($id),'c+');
    if(is_resource($lock))@chmod(kicomNormalSessionLockFile($id),0600);
    if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);return ['ok'=>false,'code'=>'SESSION_LOCK_FAILED'];}
    try{
        $r=kicomAuthJsonRead($file);if(!is_array($r))return ['ok'=>false,'code'=>'SESSION_NOT_FOUND'];$now=time();
        if((int)($r['absolute_expires_at']??0)<$now||(int)($r['idle_expires_at']??0)<$now){@unlink($file);return ['ok'=>false,'code'=>'SESSION_EXPIRED'];}
        $present=hash('sha256',$token);$current=(string)($r['token_hash']??'');$previous=(string)($r['previous_token_hash']??'');$previousUntil=(int)($r['previous_token_until']??0);
        $normal=$current!==''&&hash_equals($current,$present);$recover=!$normal&&$previous!==''&&$previousUntil>=$now&&hash_equals($previous,$present);
        if(!$normal&&!$recover)return ['ok'=>false,'code'=>'SESSION_TOKEN_REJECTED'];
        $next=kicomAuthRandomHex(32);
        if($normal){$r['previous_token_hash']=$current;$r['previous_token_until']=$now+60;}
        else{$r['previous_token_hash']='';$r['previous_token_until']=0;$r['recovered_at']=gmdate('c');}
        $r['token_hash']=hash('sha256',$next);$r['last_used_at']=gmdate('c');$r['idle_expires_at']=min((int)$r['absolute_expires_at'],$now+(int)$policy['idle_ttl']);
        if(!kicomAuthJsonWrite($file,$r))return ['ok'=>false,'code'=>'SESSION_ROTATE_FAILED'];
        return ['ok'=>true,'session_id'=>$id,'next_token'=>$next,'expires_at'=>(int)$r['absolute_expires_at'],'idle_expires_at'=>(int)$r['idle_expires_at'],'recovered'=>$recover];
    }finally{@flock($lock,LOCK_UN);@fclose($lock);}
}
