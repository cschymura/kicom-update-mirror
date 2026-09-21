<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/kicom-resilience-test-'.bin2hex(random_bytes(4));
@mkdir($root,0700,true);
function kicomVarDir():string{global $root;return $root;}
require __DIR__.'/interrupt_resilience_v1.php';
function ok(bool $x,string $m):void{if(!$x){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "OK $m\n";}
$j=kicomResilienceJobCreate('continue safely after stream interruption','BUILD',['token'=>'MUST_NOT_STORE','build_id'=>'abc']);
ok(!empty($j['ok']),'job create'); $id=$j['job']['id'];
ok(!isset($j['job']['meta']['token'])&&($j['job']['meta']['build_id']??'')==='abc','secret metadata filtered');
$c=kicomResilienceJobCheckpoint($id,1,'VERIFY','PATCH_1','PATCH_2','RUNNING',['freeotp_code'=>'NO','sha'=>'123']);
ok(!empty($c['ok'])&&$c['job']['revision']===2,'checkpoint CAS');
$conf=kicomResilienceJobCheckpoint($id,1,'VERIFY','x','y'); ok(empty($conf['ok'])&&$conf['code']==='JOB_REVISION_CONFLICT','stale checkpoint rejected');
$sha=hash('sha256','payload');$b=kicomResilienceReceiptBegin('abcdefabcdefabcdef','req-123456','PATCH',$sha);ok(($b['code']??'')==='REQUEST_CLAIMED','receipt claim');
$r=kicomResilienceReceiptBegin('abcdefabcdefabcdef','req-123456','PATCH',$sha);ok(($r['code']??'')==='REQUEST_REPLAY','idempotent replay before commit');
$cm=kicomResilienceReceiptCommit('abcdefabcdefabcdef','req-123456',$b['claim'],['ok'=>true,'next_token'=>'MUST_NOT_STORE','sha256'=>'abc']);ok(!empty($cm['ok']),'receipt commit');
ok(!isset($cm['receipt']['result']['next_token']),'result secret filtered');
$r2=kicomResilienceReceiptBegin('abcdefabcdefabcdef','req-123456','PATCH',$sha);ok(($r2['code']??'')==='REQUEST_REPLAY'&&($r2['receipt']['status']??'')==='DONE','replay returns completed receipt');
$bad=kicomResilienceReceiptBegin('abcdefabcdefabcdef','req-123456','PATCH',hash('sha256','different'));ok(empty($bad['ok'])&&$bad['code']==='REQUEST_ID_CONFLICT','request id tamper rejected');
echo "ALL TESTS PASSED\n";
