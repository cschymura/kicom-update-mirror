<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/kicom-session-v3-'.bin2hex(random_bytes(4));@mkdir($root.'/sessions',0700,true);@mkdir($root.'/auth',0700,true);
function kicomAuthDir():string{global $root;return $root.'/auth';}
function kicomAuthSessionsDir():string{global $root;return $root.'/sessions';}
function kicomAutonomySessionFile(string $id):string{return kicomAuthSessionsDir().'/'.$id.'.json';}
function kicomAuthRandomHex(int $bytes=24):string{return bin2hex(random_bytes($bytes));}
function kicomAuthJsonRead(string $f):?array{if(!is_file($f))return null;$r=json_decode((string)file_get_contents($f),true);return is_array($r)?$r:null;}
function kicomAuthJsonWrite(string $f,array $r):bool{@mkdir(dirname($f),0700,true);return file_put_contents($f,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false;}
function kicomAutonomyPolicy():array{return ['enabled'=>true,'session_ttl'=>28800,'idle_ttl'=>1800,'session_totp_past_steps'=>2];}
$GLOBALS['totp_used']=[];$GLOBALS['totp_calls']=0;
function kicomTotpVerifyConsumeWindow(string $code,string $purpose,int $grace):array{$GLOBALS['totp_calls']++;if($code!=='123456')return ['ok'=>false,'code'=>'TOTP_INVALID'];if(isset($GLOBALS['totp_used'][$code]))return ['ok'=>false,'code'=>'TOTP_REPLAY'];$GLOBALS['totp_used'][$code]=true;return ['ok'=>true,'age_steps'=>0,'period'=>30];}
$GLOBALS['events']=[];function kicomLivingEvent(string $t,string $s='info',array $d=[]):void{$GLOBALS['events'][]=[$t,$s,$d];}
require __DIR__.'/session_open_resilient_v2.php';require __DIR__.'/session_consume_resilient_v3.php';
function check(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "OK $m\n";}
$openRid='open-req-000000000001';$a=kicomSessionOpenIdempotentV2('123456',$openRid);check(!empty($a['ok'])&&!$a['replayed'],'first open');check($GLOBALS['totp_calls']===1,'totp consumed once');$sid=$a['session_id'];$token=$a['token'];
$b=kicomSessionOpenIdempotentV2('123456',$openRid);check(!empty($b['ok'])&&$b['replayed']&&hash_equals($token,$b['token']),'open replay exact');check($GLOBALS['totp_calls']===1,'open replay no second totp');
$req1='req-action-000000000001';$req2='req-action-000000000002';
$c=kicomAutonomySessionConsumeResilientV3($sid,$token,$req1);check(!empty($c['ok'])&&!empty($c['request_bound']),'request-bound normal consume');$next=$c['next_token'];
$wrong=kicomAutonomySessionConsumeResilientV3($sid,$token,$req2);check(($wrong['code']??'')==='SESSION_PREVIOUS_TOKEN_REQUEST_MISMATCH','old token cannot authorize different request');
$recover=kicomAutonomySessionConsumeResilientV3($sid,$token,$req1);check(!empty($recover['ok'])&&!empty($recover['recovered']),'old token can recover same request once');$next2=$recover['next_token'];check(!hash_equals($next,$next2),'same-request recovery rotates forward');
$again=kicomAutonomySessionConsumeResilientV3($sid,$token,$req1);check(($again['code']??'')==='SESSION_TOKEN_REJECTED','old token recovery one use');
$sup=kicomSessionOpenIdempotentV2('123456',$openRid);check(($sup['code']??'')==='SESSION_OPEN_REPLAY_SUPERSEDED','open replay cannot roll later action token backward');
$legacyId=substr(kicomAuthRandomHex(12),0,24);$legacyToken=kicomAuthRandomHex(32);$now=time();kicomAuthJsonWrite(kicomAutonomySessionFile($legacyId),['schema'=>1,'id'=>$legacyId,'token_hash'=>hash('sha256',$legacyToken),'created_at'=>gmdate('c'),'created_epoch'=>$now,'last_used_at'=>gmdate('c'),'absolute_expires_at'=>$now+28800,'idle_expires_at'=>$now+1800,'scope'=>'autonomy']);
$legacy=kicomAutonomySessionConsumeResilientV3($legacyId,$legacyToken,'');check(!empty($legacy['ok'])&&!$legacy['request_bound'],'empty request id preserves legacy-style unbound consume');
echo "ALL SESSION V3 TESTS PASSED\n";
