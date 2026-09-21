<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/kicom-consume-'.bin2hex(random_bytes(4));@mkdir($root.'/sessions',0700,true);
function kicomAuthSessionsDir():string{global $root;return $root.'/sessions';}
function kicomAutonomySessionFile(string $id):string{return kicomAuthSessionsDir().'/'.$id.'.json';}
function kicomSessionRecoveryLockFile(string $id):string{return kicomAuthSessionsDir().'/'.$id.'.recovery.lock';}
function kicomAuthRandomHex(int $bytes=24):string{return bin2hex(random_bytes($bytes));}
function kicomAuthJsonRead(string $f):?array{if(!is_file($f))return null;$r=json_decode((string)file_get_contents($f),true);return is_array($r)?$r:null;}
function kicomAuthJsonWrite(string $f,array $r):bool{return file_put_contents($f,json_encode($r),LOCK_EX)!==false;}
function kicomAutonomyPolicy():array{return ['enabled'=>true,'idle_ttl'=>1800];}
require __DIR__.'/session_consume_resilient_v1.php';
function check(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "OK $m\n";}
$sid=bin2hex(random_bytes(12));$token=bin2hex(random_bytes(32));$now=time();kicomAuthJsonWrite(kicomAutonomySessionFile($sid),['id'=>$sid,'token_hash'=>hash('sha256',$token),'absolute_expires_at'=>$now+3600,'idle_expires_at'=>$now+1800,'recovery_handle_hash'=>hash('sha256','kept')]);
$a=kicomAutonomySessionConsumeResilient($sid,$token);check(!empty($a['ok'])&&!$a['recovered'],'normal consume');$next=$a['next_token'];
$b=kicomAutonomySessionConsumeResilient($sid,$token);check(!empty($b['ok'])&&$b['recovered'],'previous token 60s recovery retained');
$row=kicomAuthJsonRead(kicomAutonomySessionFile($sid));check(($row['recovery_handle_hash']??'')===hash('sha256','kept'),'recovery metadata preserved');
$bad=kicomAutonomySessionConsumeResilient($sid,str_repeat('0',64));check(($bad['code']??'')==='SESSION_TOKEN_REJECTED','invalid token rejected');
$row['idle_expires_at']=time()-1;kicomAuthJsonWrite(kicomAutonomySessionFile($sid),$row);$exp=kicomAutonomySessionConsumeResilient($sid,$b['next_token']);check(($exp['code']??'')==='SESSION_EXPIRED','expired session rejected');
echo "ALL LOCKED SESSION CONSUME TESTS PASSED\n";
