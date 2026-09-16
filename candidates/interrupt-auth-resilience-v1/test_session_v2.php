<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/kicom-session-v2-'.bin2hex(random_bytes(4));@mkdir($root.'/sessions',0700,true);@mkdir($root.'/auth',0700,true);
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
require __DIR__.'/session_open_resilient_v2.php';require __DIR__.'/session_consume_resilient_v2.php';
function check(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "OK $m\n";}
$rid='open-req-000000000001';$a=kicomSessionOpenIdempotentV2('123456',$rid);check(!empty($a['ok'])&&!$a['replayed'],'first open');check($GLOBALS['totp_calls']===1,'totp consumed once');$sid=$a['session_id'];$token=$a['token'];
$receipt=(string)file_get_contents(kicomSessionOpenReceiptFileV2($rid));$session=(string)file_get_contents(kicomAutonomySessionFile($sid));check(!str_contains($receipt,'123456'),'totp plaintext absent');check(!str_contains($receipt,$token),'token plaintext absent');check(!str_contains($session,$token),'session token plaintext absent');
$b=kicomSessionOpenIdempotentV2('123456',$rid);check(!empty($b['ok'])&&$b['replayed'],'open replay');check($GLOBALS['totp_calls']===1,'replay no totp consume');check(hash_equals($token,$b['token']),'replay same token');
$c=kicomAutonomySessionConsumeResilientV2($sid,$token);check(!empty($c['ok']),'normal consume');$next=$c['next_token'];
$sup=kicomSessionOpenIdempotentV2('123456',$rid);check(($sup['code']??'')==='SESSION_OPEN_REPLAY_SUPERSEDED','open replay cannot roll token back');
$old=kicomAutonomySessionConsumeResilientV2($sid,$token);check(!empty($old['ok'])&&!empty($old['recovered']),'previous token recovers once');$next2=$old['next_token'];
$old2=kicomAutonomySessionConsumeResilientV2($sid,$token);check(($old2['code']??'')==='SESSION_TOKEN_REJECTED','previous token one use');check(!hash_equals($next,$next2),'recovery rotates new token');
$conf=kicomSessionOpenIdempotentV2('654321',$rid);check(($conf['code']??'')==='SESSION_OPEN_REQUEST_CONFLICT','request id conflict');
$new=kicomSessionOpenIdempotentV2('123456','open-req-000000000002');check(($new['code']??'')==='TOTP_REPLAY','same totp cannot open second session');
echo "ALL SESSION V2 TESTS PASSED\n";
