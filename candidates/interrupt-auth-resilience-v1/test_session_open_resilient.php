<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/kicom-session-open-'.bin2hex(random_bytes(4));@mkdir($root.'/sessions',0700,true);@mkdir($root.'/auth',0700,true);
function kicomAuthDir():string{global $root;return $root.'/auth';}
function kicomAuthSessionsDir():string{global $root;return $root.'/sessions';}
function kicomAutonomySessionFile(string $id):string{return kicomAuthSessionsDir().'/'.$id.'.json';}
function kicomSessionRecoveryLockFile(string $id):string{return kicomAuthSessionsDir().'/'.$id.'.recovery.lock';}
function kicomAuthRandomHex(int $bytes=24):string{return bin2hex(random_bytes($bytes));}
function kicomAuthJsonRead(string $f):?array{if(!is_file($f))return null;$r=json_decode((string)file_get_contents($f),true);return is_array($r)?$r:null;}
function kicomAuthJsonWrite(string $f,array $r):bool{@mkdir(dirname($f),0700,true);return file_put_contents($f,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false;}
function kicomAutonomyPolicy():array{return ['enabled'=>true,'session_ttl'=>28800,'idle_ttl'=>1800,'session_totp_past_steps'=>2];}
$GLOBALS['totp_used']=[];$GLOBALS['totp_calls']=0;
function kicomTotpVerifyConsumeWindow(string $code,string $purpose,int $grace):array{
    $GLOBALS['totp_calls']++;if($code!=='123456')return ['ok'=>false,'code'=>'TOTP_INVALID'];
    if(isset($GLOBALS['totp_used'][$code]))return ['ok'=>false,'code'=>'TOTP_REPLAY'];$GLOBALS['totp_used'][$code]=true;
    return ['ok'=>true,'age_steps'=>0,'period'=>30];
}
$GLOBALS['events']=[];function kicomLivingEvent(string $t,string $s='info',array $d=[]):void{$GLOBALS['events'][]=[$t,$s,$d];}
require __DIR__.'/session_open_resilient_v1.php';
function check(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "OK $m\n";}

$rid='open-req-000000000001';$a=kicomSessionOpenIdempotent('123456',$rid);check(!empty($a['ok'])&&!$a['replayed'],'first open');check($GLOBALS['totp_calls']===1,'totp consumed once');
$sid=$a['session_id'];$token=$a['token'];$handle=$a['recovery_handle'];
$receipt=(string)file_get_contents(kicomSessionOpenReceiptFile($rid));$session=(string)file_get_contents(kicomAutonomySessionFile($sid));
check(!str_contains($receipt,'123456'),'totp plaintext absent from receipt');check(!str_contains($receipt,$token),'token plaintext absent from receipt');check(!str_contains($receipt,$handle),'recovery handle plaintext absent from receipt');check(!str_contains($session,$token)&&!str_contains($session,$handle),'session stores only hashes');

$b=kicomSessionOpenIdempotent('123456',$rid);check(!empty($b['ok'])&&$b['replayed'],'same open request replayed');check($GLOBALS['totp_calls']===1,'replay does not reconsume totp');check(hash_equals($token,$b['token'])&&hash_equals($handle,$b['recovery_handle']),'replay returns same secrets');

$conf=kicomSessionOpenIdempotent('654321',$rid);check(($conf['code']??'')==='SESSION_OPEN_REQUEST_CONFLICT','same request id different code rejected');
$new=kicomSessionOpenIdempotent('123456','open-req-000000000002');check(($new['code']??'')==='TOTP_REPLAY','same consumed totp cannot open another session');

$row=kicomAuthJsonRead(kicomAutonomySessionFile($sid));$row['token_hash']=hash('sha256','later-token');kicomAuthJsonWrite(kicomAutonomySessionFile($sid),$row);
$sup=kicomSessionOpenIdempotent('123456',$rid);check(($sup['code']??'')==='SESSION_OPEN_REPLAY_SUPERSEDED','open replay cannot roll later token backwards');

$row=kicomAuthJsonRead(kicomAutonomySessionFile($sid));$row['token_hash']=hash('sha256',$token);$row['idle_expires_at']=time()-1;kicomAuthJsonWrite(kicomAutonomySessionFile($sid),$row);
$exp=kicomSessionOpenIdempotent('123456',$rid);check(($exp['code']??'')==='SESSION_EXPIRED','expired session not revived by open replay');

$key=kicomSessionOpenResilienceKey();check(is_string($key)&&strlen($key)===32,'server master key created');
echo "ALL IDEMPOTENT SESSION OPEN TESTS PASSED\n";
