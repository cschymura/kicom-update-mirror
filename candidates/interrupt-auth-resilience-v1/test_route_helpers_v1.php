<?php
declare(strict_types=1);
const KCL_PROTOCOL='KCL/1';
function kclString(string $v,int $m=1000):string{return '"'.substr($v,0,$m).'"';}
$GLOBALS['peek_calls']=0;$GLOBALS['pending_calls']=0;$GLOBALS['peek_ok']=true;
function kicomAutonomySessionPeek(string $sid,string $tok):array{$GLOBALS['peek_calls']++;return $GLOBALS['peek_ok']?['ok'=>true,'session_id'=>$sid]:['ok'=>false,'code'=>'SESSION_TOKEN_REJECTED'];}
function kicomSelfUpdatePending():?array{$GLOBALS['pending_calls']++;return ['from_version'=>'0.9.14','to_version'=>'0.9.15','zip_sha256'=>str_repeat('a',64),'manifest_sha256'=>str_repeat('b',64),'genome_id'=>'g','genome_sha256'=>str_repeat('c',64),'kernel_update'=>false,'zip_bytes'=>1,'files_count'=>2,'install_files_count'=>2,'source'=>'autonomy:server-build','risk_class'=>'red','risk_reasons'=>['security-boundary-change:index.php'],'changed_paths'=>['index.php'],'created_at'=>'x'];}
function kicomAutonomySessionOpen(string $code):array{return ['ok'=>true,'legacy'=>true];}
function kicomAuthApprovalGet(string $id):?array{return null;}
function kicomAutonomySourcePaths():array{return [];}
function kicomBaseDir():string{return __DIR__;}
function kicomAuthDir():string{return sys_get_temp_dir().'/x';}
function kicomAutonomyPolicy():array{return ['enabled'=>true,'session_ttl'=>1,'idle_ttl'=>1,'session_totp_past_steps'=>0];}
function kicomTotpVerifyConsumeWindow(string $a,string $b,int $c):array{return ['ok'=>false];}
function kicomAuthRandomHex(int $b=24):string{return str_repeat('a',$b*2);}
function kicomAuthJsonRead(string $f):?array{return null;}
function kicomAuthJsonWrite(string $f,array $r):bool{return true;}
function kicomAutonomySessionFile(string $id):string{return '/tmp/x';}
function kicomLivingEvent(string $a,string $b='info',array $c=[]):void{}
require __DIR__.'/route_helpers_v1.php';
function check(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "OK $m\n";}
check(kicomClientRequestIdV1(['request_id'=>'old-0000000000001','client_request_id'=>'client-00000000001'])==='client-00000000001','client_request_id authoritative');
check(kicomClientRequestIdV1(['request_id'=>'old-0000000000001'])==='','legacy server request_id not used as idempotency key');
$GLOBALS['peek_ok']=false;$bad=kicomRoutePendingInspectAuthorizedV1('0123456789abcdef01234567',str_repeat('d',64));check(empty($bad['ok'])&&$GLOBALS['pending_calls']===0,'invalid session blocks pending inspect before read');
$GLOBALS['peek_ok']=true;$ok=kicomRoutePendingInspectAuthorizedV1('0123456789abcdef01234567',str_repeat('d',64));check(!empty($ok['ok'])&&$GLOBALS['peek_calls']===2&&$GLOBALS['pending_calls']===1,'valid session uses nonrotating peek then pending read');
$lines=kicomPendingInspectKclLinesV1($ok,'SERVER123');$joined=implode("\n",$lines);check(str_contains($joined,'authorized-read-only-no-token-rotation'),'pending inspect declares nonrotating authorized read');check(str_contains($joined,'security-boundary-change:index.php'),'risk reason exposed only after auth');check(!str_contains($joined,'package_file'),'internal package path not exposed');
echo "ALL ROUTE HELPER TESTS PASSED\n";
