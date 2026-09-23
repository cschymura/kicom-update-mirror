<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramCanonicalMcpMutationController.php';
$n=0; function ok($v,$m){global $n;if(!$v)throw new RuntimeException('FAIL '.$m);$n++;echo "PASS $m\n";} function deny($f,$m){try{$f();}catch(Throwable $e){ok(true,$m);return;}throw new RuntimeException('FAIL '.$m);}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE engram_revisions(subject TEXT,namespace TEXT,id TEXT,revision INTEGER,kind TEXT,body TEXT,source_kind TEXT,source_ref TEXT,entry_state TEXT,previous_hash TEXT,revision_hash TEXT,PRIMARY KEY(subject,namespace,id,revision))");
KiComEngramMutationSchema::prepareNew($db);
$secret=str_repeat('s',48);$wg=new KiComEngramWriteGrant($secret);$adapter=new KiComEngramRevisionAdapter($db);$svc=new KiComEngramCanonicalMutationService($wg,$adapter);$ctl=new KiComEngramCanonicalMcpMutationController($svc);
$now=1790129000;$oauth=['authenticated'=>true,'owner'=>'owner-0001','namespace'=>'project-01','connector_id'=>'connector-01','token_fingerprint'=>'fingerprint-01','scopes'=>['engram.write']];
$oauth['synthetic_environment']=true;
$oauth['server_provenance']=[
 'source_kind'=>'synthetic_test','source_ref'=>'dev-test:synthetic-001',
 'verified'=>true,'owner'=>$oauth['owner'],'namespace'=>$oauth['namespace'],
 'token_fingerprint'=>$oauth['token_fingerprint']
];
function grant($wg,$oauth,$ops,$nonce,$now){return $wg->issueSynthetic(['v'=>1,'grant_id'=>'grant-'.$nonce,'owner'=>$oauth['owner'],'namespace'=>$oauth['namespace'],'connector_id'=>$oauth['connector_id'],'token_fingerprint'=>$oauth['token_fingerprint'],'operations'=>$ops,'nonce'=>$nonce,'iat'=>$now-1,'exp'=>$now+60]);}
$g=grant($wg,$oauth,['engram_write'],'nonce-0001',$now);$w=$ctl->handle($oauth,['tool'=>'engram_write','write_grant'=>$g,'idempotency_key'=>'idem-0001','input'=>['body'=>'synthetic canonical memory']],$now);ok($w['revision']===1&&$w['state']==='active','write through OAuth/MCP/grant/canonical SQLite');
deny(fn()=>$ctl->handle(array_merge($oauth,['scopes'=>['engram.read']]),['tool'=>'engram_write','write_grant'=>grant($wg,$oauth,['engram_write'],'nonce-0002',$now),'idempotency_key'=>'idem-0002','input'=>['body'=>'x']],$now),'read token cannot write');
deny(fn()=>$ctl->handle($oauth,['tool'=>'engram_write','owner'=>'owner-evil','write_grant'=>grant($wg,$oauth,['engram_write'],'nonce-0003',$now),'idempotency_key'=>'idem-0003','input'=>['body'=>'x']],$now),'MCP owner claim denied');
$other=$oauth;$other['namespace']='project-02';deny(fn()=>$ctl->handle($other,['tool'=>'engram_write','write_grant'=>grant($wg,$oauth,['engram_write'],'nonce-0004',$now),'idempotency_key'=>'idem-0004','input'=>['body'=>'x']],$now),'grant namespace mismatch denied');
$g2=grant($wg,$oauth,['engram_update'],'nonce-0005',$now);$u=$ctl->handle($oauth,['tool'=>'engram_update','write_grant'=>$g2,'idempotency_key'=>'idem-0005','input'=>['id'=>$w['id'],'expected_revision'=>1,'expected_hash'=>$w['revision_hash'],'body'=>'synthetic revision two']],$now);ok($u['revision']===2,'update revision appended');
deny(fn()=>$ctl->handle($oauth,['tool'=>'engram_update','write_grant'=>grant($wg,$oauth,['engram_update'],'nonce-0006',$now),'idempotency_key'=>'idem-0006','input'=>['id'=>$w['id'],'expected_revision'=>1,'expected_hash'=>$w['revision_hash'],'body'=>'stale']],$now),'stale update denied');
$g3=grant($wg,$oauth,['engram_archive'],'nonce-0007',$now);$a=$ctl->handle($oauth,['tool'=>'engram_archive','write_grant'=>$g3,'idempotency_key'=>'idem-0007','input'=>['id'=>$w['id'],'expected_revision'=>2,'expected_hash'=>$u['revision_hash']]],$now);ok($a['revision']===3&&$a['state']==='withdrawn','archive append-only withdrawn');
$c=(int)$db->query("SELECT COUNT(*) FROM engram_revisions")->fetchColumn();ok($c===3,'history retained');
deny(fn()=>$ctl->handle($oauth,['tool'=>'engram_archive','write_grant'=>$g3,'idempotency_key'=>'idem-0008','input'=>['id'=>$w['id'],'expected_revision'=>3,'expected_hash'=>$a['revision_hash']]],$now),'grant nonce replay denied');
$qc=$db->query('PRAGMA quick_check')->fetchColumn();ok($qc==='ok','SQLite quick_check');
echo "KICOM_DEV76_ASSERTIONS=$n\n";
