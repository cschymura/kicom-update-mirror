<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramWriteGrant.php';
$n=0; $fail=0;
function ok(bool $v,string $m):void{global $n,$fail;$n++;if(!$v){$fail++;echo "FAIL $m\n";}else echo "PASS $m\n";}
function deny(callable $f,string $m):void{try{$f();ok(false,$m);}catch(Throwable $e){ok(true,$m);}}
$now=1700000000;
$v=new KiComEngramWriteGrant(str_repeat('S',32),300);
$c=['v'=>1,'grant_id'=>'grant-0001','owner'=>'owner-0001','namespace'=>'project-01','connector_id'=>'connector-01','token_fingerprint'=>'fingerprint-0001','operations'=>['engram_write','engram_update','engram_archive'],'nonce'=>'nonce-000001','iat'=>$now-10,'exp'=>$now+120];
$o=['owner'=>'owner-0001','namespace'=>'project-01','connector_id'=>'connector-01','token_fingerprint'=>'fingerprint-0001','scopes'=>['engram.read','engram.write']];
$g=$v->issueSynthetic($c);
$r=$v->verify($g,$o,'engram_write',$now); ok($r['owner']==='owner-0001','positive write grant');
ok($v->verify($g,$o,'engram_update',$now)['namespace']==='project-01','positive update grant');
ok($v->verify($g,$o,'engram_archive',$now)['grant_id']==='grant-0001','positive archive grant');
$read=$o;$read['scopes']=['engram.read']; deny(fn()=>$v->verify($g,$read,'engram_write',$now),'read-only token cannot write');
foreach(['owner','namespace','connector_id','token_fingerprint'] as $k){$x=$o;$x[$k].='x';deny(fn()=>$v->verify($g,$x,'engram_write',$now),'reject '.$k.' mismatch');}
$x=$g; $x[strlen($x)-1]=$x[strlen($x)-1]==='A'?'B':'A'; deny(fn()=>$v->verify($x,$o,'engram_write',$now),'reject signature tamper');
deny(fn()=>$v->verify($g,$o,'engram_delete',$now),'reject unsupported hard delete');
$expired=$c;$expired['iat']=$now-200;$expired['exp']=$now-1;$eg=$v->issueSynthetic($expired);deny(fn()=>$v->verify($eg,$o,'engram_write',$now),'reject expired grant');
$future=$c;$future['iat']=$now+10;$future['exp']=$now+20;$fg=$v->issueSynthetic($future);deny(fn()=>$v->verify($fg,$o,'engram_write',$now),'reject future grant');
$long=$c;$long['exp']=$long['iat']+301;$lg=$v->issueSynthetic($long);deny(fn()=>$v->verify($lg,$o,'engram_write',$now),'reject overlong ttl');
$limited=$c;$limited['operations']=['engram_update'];$ug=$v->issueSynthetic($limited);deny(fn()=>$v->verify($ug,$o,'engram_archive',$now),'reject operation outside grant');
$dup=$o;$dup['scopes'][]='engram.write';deny(fn()=>$v->verify($g,$dup,'engram_write',$now),'reject ambiguous duplicate write scope');
$extra=$c;$extra['admin']=true;deny(fn()=>$v->issueSynthetic($extra),'reject extra authority claim');
deny(fn()=>new KiComEngramWriteGrant('short'),'reject weak signing secret');
echo "RESULT passed=".($n-$fail)." failed=$fail total=$n\n"; exit($fail===0?0:1);
