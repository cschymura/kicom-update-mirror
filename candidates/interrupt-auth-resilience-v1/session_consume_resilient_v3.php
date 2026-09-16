<?php
declare(strict_types=1);
require_once __DIR__.'/session_lock_v2.php';

/* Request-bound rolling-token consumer. For resilient requests, the 60-second
 * previous-token fallback is valid only for the same request_id that rotated it. */
function kicomAutonomySessionConsumeResilientV3(string $id,string $token,string $requestId=''): array {
    $policy=kicomAutonomyPolicy();if(empty($policy['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];
    $id=strtolower(trim($id));$token=strtolower(trim($token));$requestId=trim($requestId);
    if(!preg_match('/^[a-f0-9]{24}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$token))return ['ok'=>false,'code'=>'SESSION_AUTH_INVALID'];
    if($requestId!==''&&!preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$requestId))return ['ok'=>false,'code'=>'REQUEST_ID_INVALID'];
    $requestHash=$requestId===''?'':hash('sha256',$requestId);
    $file=kicomAutonomySessionFile($id);$lock=@fopen(kicomNormalSessionLockFile($id),'c+');if(is_resource($lock))@chmod(kicomNormalSessionLockFile($id),0600);
    if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);return ['ok'=>false,'code'=>'SESSION_LOCK_FAILED'];}
    try{
        $r=kicomAuthJsonRead($file);if(!is_array($r))return ['ok'=>false,'code'=>'SESSION_NOT_FOUND'];$now=time();
        if((int)($r['absolute_expires_at']??0)<$now||(int)($r['idle_expires_at']??0)<$now){@unlink($file);return ['ok'=>false,'code'=>'SESSION_EXPIRED'];}
        $present=hash('sha256',$token);$current=(string)($r['token_hash']??'');$previous=(string)($r['previous_token_hash']??'');$previousUntil=(int)($r['previous_token_until']??0);
        $normal=$current!==''&&hash_equals($current,$present);$recover=!$normal&&$previous!==''&&$previousUntil>=$now&&hash_equals($previous,$present);
        if($recover&&$requestHash!==''){$bound=(string)($r['previous_token_request_hash']??'');if($bound===''||!hash_equals($bound,$requestHash))return ['ok'=>false,'code'=>'SESSION_PREVIOUS_TOKEN_REQUEST_MISMATCH'];}
        if(!$normal&&!$recover)return ['ok'=>false,'code'=>'SESSION_TOKEN_REJECTED'];
        $next=kicomAuthRandomHex(32);
        if($normal){$r['previous_token_hash']=$current;$r['previous_token_until']=$now+60;$r['previous_token_request_hash']=$requestHash;}
        else{$r['previous_token_hash']='';$r['previous_token_until']=0;$r['previous_token_request_hash']='';$r['recovered_at']=gmdate('c');}
        $r['token_hash']=hash('sha256',$next);$r['last_used_at']=gmdate('c');$r['idle_expires_at']=min((int)$r['absolute_expires_at'],$now+(int)$policy['idle_ttl']);
        if(!kicomAuthJsonWrite($file,$r))return ['ok'=>false,'code'=>'SESSION_ROTATE_FAILED'];
        return ['ok'=>true,'session_id'=>$id,'next_token'=>$next,'expires_at'=>(int)$r['absolute_expires_at'],'idle_expires_at'=>(int)$r['idle_expires_at'],'recovered'=>$recover,'request_bound'=>$requestHash!==''];
    }finally{@flock($lock,LOCK_UN);@fclose($lock);}
}
