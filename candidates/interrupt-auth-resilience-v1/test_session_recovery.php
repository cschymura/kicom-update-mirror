<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/kicom-session-recovery-'.bin2hex(random_bytes(4));@mkdir($root.'/sessions',0700,true);
function kicomAuthSessionsDir():string{global $root;return $root.'/sessions';}
function kicomAutonomySessionFile(string $id):string{return kicomAuthSessionsDir().'/'.$id.'.json';}
function kicomAuthRandomHex(int $bytes=24):string{return bin2hex(random_bytes($bytes));}
function kicomAuthJsonRead(string $f):?array{if(!is_file($f))return null;$r=json_decode((string)file_get_contents($f),true);return is_array($r)?$r:null;}
function kicomAuthJsonWrite(string $f,array $r):bool{return file_put_contents($f,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false;}
function kicomAutonomyPolicy():array{return ['enabled'=>true,'session_ttl'=>28800,'idle_ttl'=>1800];}
$GLOBALS['events']=[];function kicomLivingEvent(string $t,string $s='info',array $d=[]):void{$GLOBALS['events'][]=[$t,$s,$d];}
function kicomAutonomySessionOpen(string $code):array{
    if($code!=='123456')return ['ok'=>false,'code'=>'TOTP_INVALID'];
    $id=bin2hex(random_bytes(12));$token=bin2hex(random_bytes(32));$now=time();
    $row=['schema'=>1,'id'=>$id,'token_hash'=>hash('sha256',$token),'created_epoch'=>$now,'absolute_expires_at'=>$now+28800,'idle_expires_at'=>$now+1800,'scope'=>'autonomy'];
    kicomAuthJsonWrite(kicomAutonomySessionFile($id),$row);return ['ok'=>true,'session_id'=>$id,'token'=>$token,'expires_in'=>28800,'idle_expires_in'=>1800];
}
require __DIR__.'/session_recovery_v1.php';
function check(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "OK $m\n";}
$o=kicomAutonomySessionOpenWithRecovery('123456');check(!empty($o['ok']),'open with recovery');
$sid=$o['session_id'];$handle=$o['recovery_handle'];$row=kicomAuthJsonRead(kicomAutonomySessionFile($sid));
check(strlen($handle)===64,'handle returned once');check(($row['recovery_handle_hash']??'')===hash('sha256',$handle),'handle hash stored');
check(!str_contains((string)file_get_contents(kicomAutonomySessionFile($sid)),$handle),'handle plaintext not stored');
$rid='req-000000000001';$r1=kicomSessionRecoveryRecover($sid,$handle,$rid);check(($r1['code']??'')==='SESSION_RECOVERED','first recovery');$t1=$r1['next_token'];
$row=kicomAuthJsonRead(kicomAutonomySessionFile($sid));check(hash_equals($row['token_hash'],hash('sha256',$t1)),'recovered token activated');
$r1b=kicomSessionRecoveryRecover($sid,$handle,$rid);check(($r1b['code']??'')==='SESSION_RECOVERY_REPLAY','identical retry replay');check(hash_equals($t1,$r1b['next_token']),'retry returns same token');
$rid2='req-000000000002';$r2=kicomSessionRecoveryRecover($sid,$handle,$rid2);check(($r2['code']??'')==='SESSION_RECOVERED','second recovery');check(!hash_equals($t1,$r2['next_token']),'new recovery rotates token');
$old=kicomSessionRecoveryRecover($sid,$handle,$rid);check(($old['code']??'')==='SESSION_RECOVERY_REQUEST_REUSED','old request cannot roll token backwards');
$bad=kicomSessionRecoveryRecover($sid,str_repeat('0',64),'req-000000000003');check(($bad['code']??'')==='SESSION_RECOVERY_REJECTED','wrong handle rejected');
$row=kicomAuthJsonRead(kicomAutonomySessionFile($sid));$row['token_hash']=hash('sha256','external-normal-use');kicomAuthJsonWrite(kicomAutonomySessionFile($sid),$row);
$sup=kicomSessionRecoveryRecover($sid,$handle,$rid2);check(($sup['code']??'')==='SESSION_RECOVERY_SUPERSEDED','replay after later normal use cannot overwrite token');
$rid3='req-000000000003';$r3=kicomSessionRecoveryRecover($sid,$handle,$rid3);check(($r3['code']??'')==='SESSION_RECOVERED','fresh recovery after normal use');
$status=kicomSessionRecoveryStatus($sid);check(!empty($status['ok'])&&$status['configured']===true,'status configured');check(!array_key_exists('recovery_handle',$status),'status never returns handle');
$row=kicomAuthJsonRead(kicomAutonomySessionFile($sid));$row['recovery_count']=$row['recovery_limit'];kicomAuthJsonWrite(kicomAutonomySessionFile($sid),$row);
$lim=kicomSessionRecoveryRecover($sid,$handle,'req-000000000004');check(($lim['code']??'')==='SESSION_RECOVERY_LIMIT_REACHED','recovery limit enforced');
$row=kicomAuthJsonRead(kicomAutonomySessionFile($sid));$row['recovery_count']=0;$row['idle_expires_at']=time()-1;kicomAuthJsonWrite(kicomAutonomySessionFile($sid),$row);
$exp=kicomSessionRecoveryRecover($sid,$handle,'req-000000000005');check(($exp['code']??'')==='SESSION_EXPIRED','expired session not revived');
echo "ALL SESSION RECOVERY TESTS PASSED\n";
