<?php
declare(strict_types=1);
$GLOBALS['rid']='';$GLOBALS['legacy']=0;$GLOBALS['res']=0;
function kicomDeferredGuardClientRequestIdV1():string{return (string)$GLOBALS['rid'];}
function kicomAutonomySessionConsumeLegacyV5(string $id,string $token):array{$GLOBALS['legacy']++;return ['ok'=>true,'path'=>'legacy'];}
function kicomAutonomySessionConsumeResilientV3(string $id,string $token,string $rid):array{$GLOBALS['res']++;return ['ok'=>true,'path'=>'resilient','rid'=>$rid];}
require __DIR__.'/session_consume_bridge_v1.php';
function check(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "OK $m\n";}
$a=kicomAutonomySessionConsumeBridgeV1('s','t');check($a['path']==='legacy'&&$GLOBALS['legacy']===1&&$GLOBALS['res']===0,'legacy unchanged without client id');
$GLOBALS['rid']='req-0000000000001';$b=kicomAutonomySessionConsumeBridgeV1('s','t');check($b['path']==='resilient'&&$b['rid']===$GLOBALS['rid']&&$GLOBALS['legacy']===1&&$GLOBALS['res']===1,'resilient only with client id');
echo "ALL SESSION CONSUME BRIDGE TESTS PASSED\n";
