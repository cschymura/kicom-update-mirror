<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/kicom-delegated-job-'.bin2hex(random_bytes(4));@mkdir($root,0700,true);
function kicomVarDir():string{global $root;return $root;}
function kicomAuthRandomHex(int $bytes=24):string{return bin2hex(random_bytes($bytes));}
function kicomAuthJsonRead(string $f):?array{if(!is_file($f))return null;$r=json_decode((string)file_get_contents($f),true);return is_array($r)?$r:null;}
function kicomAuthJsonWrite(string $f,array $r):bool{@mkdir(dirname($f),0700,true);return file_put_contents($f,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false;}
function kicomSafeUpdatePath(string $p):?string{$p=trim($p);return preg_match('~^[A-Za-z0-9_./-]+\.php$~',$p)&&!str_contains($p,'..')?$p:null;}
$GLOBALS['events']=[];function kicomLivingEvent(string $t,string $s='info',array $d=[]):void{$GLOBALS['events'][]=[$t,$s,$d];}
$GLOBALS['builds']=[];
function kicomFastBuildPatch(string $sid,string $bid,string $path,string $base,string $find,string $replace):array{
    $k=$bid.'|'.$path;if(!array_key_exists($k,$GLOBALS['builds']))return ['ok'=>false,'code'=>'BUILD_PATH_NOT_FOUND'];$raw=(string)$GLOBALS['builds'][$k];$cur=hash('sha256',$raw);if(!hash_equals($cur,$base))return ['ok'=>false,'code'=>'BASE_CONFLICT','sha256'=>$cur];if(substr_count($raw,$find)!==1)return ['ok'=>false,'code'=>'PATCH_MATCH_COUNT'];$new=str_replace($find,$replace,$raw,$n);if($n!==1)return ['ok'=>false,'code'=>'PATCH_FAILED'];$GLOBALS['builds'][$k]=$new;return ['ok'=>true,'code'=>'OK','sha256'=>hash('sha256',$new)];
}
require __DIR__.'/delegated_build_jobs_v1.php';
function check(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "OK $m\n";}
$sid='0123456789abcdef01234567';$bid='0123456789abcdef0123';$session=['session_id'=>$sid,'expires_at'=>time()+7200];
$GLOBALS['builds'][$bid.'|living.php']='alpha beta';$sha0=hash('sha256','alpha beta');$sha1=hash('sha256','ALPHA beta');$sha2=hash('sha256','ALPHA BETA');
$plan=['build_id'=>$bid,'steps'=>[
 ['kind'=>'build_patch','path'=>'living.php','base_sha256'=>$sha0,'find'=>'alpha','replace'=>'ALPHA','expected_sha256'=>$sha1],
 ['kind'=>'build_patch','path'=>'living.php','base_sha256'=>$sha1,'find'=>'beta','replace'=>'BETA','expected_sha256'=>$sha2],
]];
$j=kicomDelegatedBuildJobCreateV1($session,$plan);check(!empty($j['ok'])&&$j['steps']===2,'job created');$id=$j['job_id'];check($j['expires_in']<=3600&&$j['expires_in']>3500,'delegation ttl bounded');
$raw=(string)file_get_contents(kicomDelegatedBuildJobFileV1($id));check(!str_contains($raw,'token')&&!str_contains($raw,'totp')&&!str_contains($raw,'freeotp'),'no auth secret fields');
$s=kicomDelegatedBuildJobStatusV1($id);check($s['state']==='READY'&&$s['steps']===2,'sanitized status');$statusJson=json_encode($s);check(!str_contains($statusJson,'alpha')&&!str_contains($statusJson,'replace')&&!str_contains($statusJson,$sid),'status hides patch and session id');
$t1=kicomDelegatedBuildJobTickV1($id);check(!empty($t1['ok'])&&$t1['state']==='READY'&&$t1['next_step']===1,'one patch per tick');check($GLOBALS['builds'][$bid.'|living.php']==='ALPHA beta','first patch only');
$t2=kicomDelegatedBuildJobTickV1($id);check(!empty($t2['ok'])&&$t2['state']==='COMPLETED','second tick completes');check($GLOBALS['builds'][$bid.'|living.php']==='ALPHA BETA','second patch applied');
$t3=kicomDelegatedBuildJobTickV1($id);check(!empty($t3['ok'])&&$t3['code']==='JOB_TERMINAL','terminal job retained/idempotent');check(is_file(kicomDelegatedBuildJobFileV1($id)),'no hard delete');
$bad=kicomDelegatedBuildJobCreateV1($session,['build_id'=>$bid,'steps'=>[['kind'=>'deploy','path'=>'living.php','base_sha256'=>$sha2,'find'=>'x','replace'=>'y','expected_sha256'=>$sha2]]]);check(($bad['code']??'')==='JOB_STEP_KIND_REJECTED','deploy rejected');
$GLOBALS['builds'][$bid.'|index.php']='one';$b0=hash('sha256','one');$wrong=str_repeat('0',64);$jm=kicomDelegatedBuildJobCreateV1($session,['build_id'=>$bid,'steps'=>[['path'=>'index.php','base_sha256'=>$b0,'find'=>'one','replace'=>'two','expected_sha256'=>$wrong]]]);check(!empty($jm['ok']),'mismatch job created');$mx=kicomDelegatedBuildJobTickV1($jm['job_id']);check(($mx['code']??'')==='JOB_RESULT_SHA_MISMATCH','unexpected result sha stops job');$ms=kicomDelegatedBuildJobStatusV1($jm['job_id']);check($ms['state']==='FAILED','mismatch job terminal failed');
$GLOBALS['builds'][$bid.'|api.php']='x';$e0=hash('sha256','x');$e1=hash('sha256','y');$je=kicomDelegatedBuildJobCreateV1(['session_id'=>$sid,'expires_at'=>time()+2],['build_id'=>$bid,'steps'=>[['path'=>'api.php','base_sha256'=>$e0,'find'=>'x','replace'=>'y','expected_sha256'=>$e1]]]);check(!empty($je['ok']),'short-lived job created');$er=kicomDelegatedBuildJobReadV1($je['job_id']);$er['expires_at']=time()-1;kicomAuthJsonWrite(kicomDelegatedBuildJobFileV1($je['job_id']),$er);$ex=kicomDelegatedBuildJobTickV1($je['job_id']);check(($ex['code']??'')==='JOB_EXPIRED','expired delegation cannot execute');check($GLOBALS['builds'][$bid.'|api.php']==='x','expired patch not applied');
echo "ALL DELEGATED BUILD JOB TESTS PASSED\n";
