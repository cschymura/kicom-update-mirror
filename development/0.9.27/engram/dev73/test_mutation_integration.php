<?php
declare(strict_types=1); require __DIR__.'/KiComEngramMutationService.php';
$n=0;$f=0; function ok(bool $v,string $m):void{global$n,$f;$n++;if(!$v){$f++;echo"FAIL $m\n";}else echo"PASS $m\n";} function deny(callable $c,string $m):void{try{$c();ok(false,$m);}catch(Throwable $e){ok(true,$m);}}
$db=new PDO('sqlite::memory:'); $g=new KiComEngramWriteGrant(str_repeat('K',32)); $s=new KiComEngramMutationService($db,$g); $now=1700000000;
function grant(KiComEngramWriteGrant $g,string $gid,string $nonce,int $now):string{return $g->issueSynthetic(['v'=>1,'grant_id'=>$gid,'owner'=>'owner-0001','namespace'=>'project-01','connector_id'=>'connector-01','token_fingerprint'=>'fingerprint-0001','operations'=>['engram_write','engram_update','engram_archive'],'nonce'=>$nonce,'iat'=>$now-1,'exp'=>$now+120]);}
$o=['owner'=>'owner-0001','namespace'=>'project-01','connector_id'=>'connector-01','token_fingerprint'=>'fingerprint-0001','scopes'=>['engram.read','engram.write']];
$w=$s->mutate(grant($g,'grant-0001','nonce-000001',$now),$o,'engram_write',['body'=>'synthetic alpha'],'idem-0001',$now); ok($w['revision']===1&&$w['state']==='active','write commits revision 1');
$re=$s->mutate(grant($g,'grant-0002','nonce-000002',$now),$o,'engram_write',['body'=>'synthetic alpha'],'idem-0001',$now); ok($re===$w,'same idempotency request returns same result');
deny(fn()=>$s->mutate(grant($g,'grant-0003','nonce-000003',$now),$o,'engram_write',['body'=>'different'],'idem-0001',$now),'idempotency key payload conflict denied');
$u=$s->mutate(grant($g,'grant-0004','nonce-000004',$now),$o,'engram_update',['id'=>$w['id'],'body'=>'synthetic beta','expected_revision'=>1,'expected_hash'=>$w['revision_hash']],'idem-0002',$now); ok($u['revision']===2&&$s->current('owner-0001','project-01',$w['id'])['body']==='synthetic beta','update creates revision 2');
deny(fn()=>$s->mutate(grant($g,'grant-0005','nonce-000005',$now),$o,'engram_update',['id'=>$w['id'],'body'=>'stale','expected_revision'=>1,'expected_hash'=>$w['revision_hash']],'idem-0003',$now),'stale update denied');
$a=$s->mutate(grant($g,'grant-0006','nonce-000006',$now),$o,'engram_archive',['id'=>$w['id'],'expected_revision'=>2,'expected_hash'=>$u['revision_hash']],'idem-0004',$now); ok($a['revision']===3&&$a['state']==='archived','archive appends revision without delete');
$c=(int)$db->query('SELECT count(*) FROM dev73_engrams')->fetchColumn(); ok($c===3,'history retained after archive');
deny(fn()=>$s->mutate(grant($g,'grant-0007','nonce-000007',$now),$o,'engram_update',['id'=>$w['id'],'body'=>'resurrect','expected_revision'=>3,'expected_hash'=>$a['revision_hash']],'idem-0005',$now),'archived engram cannot be updated');
$read=$o;$read['scopes']=['engram.read']; deny(fn()=>$s->mutate(grant($g,'grant-0008','nonce-000008',$now),$read,'engram_write',['body'=>'forbidden'],'idem-0006',$now),'read token cannot write');
$other=$o;$other['owner']='owner-0002'; deny(fn()=>$s->mutate(grant($g,'grant-0009','nonce-000009',$now),$other,'engram_write',['body'=>'forbidden'],'idem-0007',$now),'owner mismatch denied');
$other=$o;$other['namespace']='project-02'; deny(fn()=>$s->mutate(grant($g,'grant-0010','nonce-000010',$now),$other,'engram_write',['body'=>'forbidden'],'idem-0008',$now),'namespace mismatch denied');
$reuse=grant($g,'grant-0011','nonce-000011',$now);$x=$s->mutate($reuse,$o,'engram_write',['body'=>'one'],'idem-0009',$now);deny(fn()=>$s->mutate($reuse,$o,'engram_write',['body'=>'two'],'idem-0010',$now),'grant nonce replay denied across mutation keys');
ok($s->auditCount()===4,'audit contains only four committed mutations');
$cols=$db->query('PRAGMA table_info(dev73_audit)')->fetchAll(PDO::FETCH_COLUMN,1); ok(!in_array('body',$cols,true)&&!in_array('token_fingerprint',$cols,true)&&!in_array('nonce',$cols,true),'audit schema excludes body token fingerprint and nonce');
$qc=$db->query('PRAGMA quick_check')->fetchColumn();ok($qc==='ok','SQLite quick_check ok');
echo"RESULT passed=".($n-$f)." failed=$f total=$n\n";exit($f?1:0);