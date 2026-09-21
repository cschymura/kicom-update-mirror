<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramMcpContract.php';
$n=0; function ok(bool $x,string $m):void{global $n;if(!$x)throw new RuntimeException('FAIL '.$m);$n++;echo "PASS $m\n";}
function deny(callable $f,string $want,string $m):void{try{$f();}catch(RuntimeException $e){ok($e->getMessage()===$want,$m);return;}throw new RuntimeException('FAIL '.$m);}
$db=new PDO('sqlite::memory:'); $c=new KiComEngramMcpContract($db,'mirage-owner'); $now=1800000000;
$id=['authenticated'=>true,'owner'=>'mirage-owner','connector_id'=>'chatgpt-mcp-dev'];
$base=['v'=>1,'op'=>'read','owner'=>'mirage-owner','namespace'=>'collaboration','nonce'=>'nonce-001','issued_at'=>$now,'limit'=>3,'query'=>'synthetic'];
deny(fn()=>$c->accept($base,$id,'inactive',$now),'PRIVATE_API_INACTIVE','inactive API fails closed');
$r=$c->accept($base,$id,'active',$now); ok($r['status']==='ACCEPTED_SYNTHETIC_TRANSPORT_ONLY','valid authenticated synthetic envelope accepted');
deny(fn()=>$c->accept($base,$id,'active',$now),'REPLAY_DENIED','nonce replay denied');
$x=$base;$x['nonce']='nonce-002';$bad=$id;$bad['owner']='foreign-owner';deny(fn()=>$c->accept($x,$bad,'active',$now),'IDENTITY_DENIED','foreign identity denied');
$x['owner']='foreign-owner';deny(fn()=>$c->accept($x,$id,'active',$now),'REQUEST_SCOPE_DENIED','foreign request owner denied');
$x=$base;$x['nonce']='nonce-003';$x['issued_at']=$now-61;deny(fn()=>$c->accept($x,$id,'active',$now),'REQUEST_STALE','stale request denied');
$x=$base;$x['nonce']='nonce-004';$x['issued_at']=$now+61;deny(fn()=>$c->accept($x,$id,'active',$now),'REQUEST_STALE','future request denied');
$x=$base;$x['nonce']='nonce-005';$x['limit']=11;deny(fn()=>$c->accept($x,$id,'active',$now),'LIMIT_DENIED','excess disclosure limit denied');
$x=$base;$x['nonce']='nonce-006';$x['secret']='x';deny(fn()=>$c->accept($x,$id,'active',$now),'UNKNOWN_OR_MISSING_FIELDS','secret extra field denied');
$x=$base;$x['nonce']='nonce-007';$x['op']='admin';deny(fn()=>$c->accept($x,$id,'active',$now),'OP_DENIED','admin operation denied');
$bad=$id;$bad['token']='secret';$x=$base;$x['nonce']='nonce-008';deny(fn()=>$c->accept($x,$bad,'active',$now),'UNKNOWN_OR_MISSING_FIELDS','identity token field denied');
$rows=[['id'=>'e1','revision'=>2,'body'=>'synthetic one','source_kind'=>'synthetic_test','private_path'=>'/no'],];
deny(fn()=>$c->projectRead($rows,3),'UNKNOWN_OR_MISSING_FIELDS','private row metadata denied');
$rows=[['id'=>'e1','revision'=>2,'body'=>'synthetic one','source_kind'=>'synthetic_test'],['id'=>'e2','revision'=>1,'body'=>'synthetic two','source_kind'=>'synthetic_test']];
$p=$c->projectRead($rows,1);ok($p['count']===1 && array_keys($p['items'][0])===['id','revision','body','source_kind'],'read projection bounded and minimal');
$x=$base;$x['nonce']='nonce-009';$x['op']='append';ok($c->accept($x,$id,'active',$now)['op']==='append','append envelope accepted without storing content');
echo "KICOM_ENGRAM_MCP_CONTRACT_TESTS_PASSED=$n\n";
