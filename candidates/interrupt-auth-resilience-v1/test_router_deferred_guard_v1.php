<?php
declare(strict_types=1);
const KCL_PROTOCOL='KCL/1';
function kclString(string $v,int $m=1000):string{return '"'.substr($v,0,$m).'"';}
$GLOBALS['mode']='EXECUTE';$GLOBALS['completed']=[];
function kicomRequestGuardBeginV5(string $sid,string $rid,string $op,array $params,string $tok):array{
    $m=$GLOBALS['mode'];
    if($m==='EXECUTE')return ['ok'=>true,'mode'=>'EXECUTE','claim'=>'abc'];
    if($m==='REPLAY')return ['ok'=>true,'mode'=>'REPLAY','response'=>['status'=>207,'lines'=>['KCL/1','OK old','END']]];
    if($m==='IN_FLIGHT')return ['ok'=>true,'mode'=>'IN_FLIGHT'];
    if($m==='UNCERTAIN')return ['ok'=>true,'mode'=>'UNCERTAIN'];
    return ['ok'=>false,'code'=>'X'];
}
function kicomRequestGuardCompleteV5(string $sid,string $rid,string $op,array $params,string $tok,string $claim,array $resp):array{$GLOBALS['completed']=[$sid,$rid,$op,$params,$tok,$claim,$resp];return ['ok'=>true,'code'=>'REQUEST_COMMITTED'];}
require __DIR__.'/router_deferred_guard_v1.php';
function check(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "OK $m\n";}
check(kicomClientRequestIdV2(['client_request_id'=>'req-0000000000001'])==='req-0000000000001','client id');
$p=kicomDeferredGuardPreflightV1('sid','tok','req-0000000000001','OP',['b'=>2]);check($p['mode']==='EXECUTE','execute preflight');check(kicomDeferredGuardClientRequestIdV1()==='req-0000000000001','context visible');
$c=kicomDeferredGuardCompleteV1(['KCL/1','OK x','END'],200);check($c['ok'],'complete');check($GLOBALS['completed'][6]['status']===200,'status stored');check(kicomDeferredGuardContextV1()===null,'context cleared');
$GLOBALS['mode']='REPLAY';$p=kicomDeferredGuardPreflightV1('sid','tok','req-0000000000001','OP',[]);check($p['mode']==='REPLAY'&&$p['status']===207&&$p['lines'][1]==='OK old','exact replay');
$GLOBALS['mode']='IN_FLIGHT';$p=kicomDeferredGuardPreflightV1('sid','tok','req-0000000000001','OP',[]);check($p['mode']==='IN_FLIGHT','inflight');
$GLOBALS['mode']='UNCERTAIN';$p=kicomDeferredGuardPreflightV1('sid','tok','req-0000000000001','OP',[]);check($p['mode']==='UNCERTAIN','uncertain');
$e=kicomDeferredGuardErrorLinesV1('SERVER','req-0000000000001','REQUEST_UNCERTAIN');check(str_contains(implode("\n",$e),'do-not-reexecute-blindly'),'error rule');
echo "ALL ROUTER DEFERRED GUARD TESTS PASSED\n";
