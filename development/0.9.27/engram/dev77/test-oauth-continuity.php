<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramOAuthContinuity.php';
$n=0; function ok(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$m);$n++;echo "PASS $m\n";}
function denied(callable $f,string $m):void{$x=false;try{$f();}catch(RuntimeException){$x=true;}ok($x,$m);}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE mirage_oauth_tokens (token_hash TEXT PRIMARY KEY,client_id TEXT NOT NULL,connector_id TEXT NOT NULL,host_evidence_id TEXT NOT NULL,resource TEXT NOT NULL,scope TEXT NOT NULL,owner_binding TEXT NOT NULL,credential_fingerprint TEXT NOT NULL,issued_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,revoked INTEGER NOT NULL DEFAULT 0)');
$now=1790139600;$access=rtrim(strtr(base64_encode(random_bytes(32)),"+/","-_"),"=");$owner=str_repeat('a',64);$fp=str_repeat('b',64);
$client=['client_id'=>'https://chatgpt.com/oauth/client.json','connector_id'=>'mirage-engram','host_evidence_id'=>'host-approved'];
$q=$db->prepare('INSERT INTO mirage_oauth_tokens VALUES(?,?,?,?,?,?,?,?,?,?,0)');$q->execute([hash('sha256',$access),$client['client_id'],$client['connector_id'],$client['host_evidence_id'],'https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP','engram.read',$owner,$fp,$now-10,$now+100]);
$missingSchemaDenied=false;
try {KiComEngramOAuthContinuity::mintForVerifiedRead($db,$access,$now);} catch(Throwable) {$missingSchemaDenied=true;}
ok($missingSchemaDenied,'missing explicitly prepared refresh schema rejected without implicit install');
ok((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mirage_oauth_refresh_tokens'")->fetchColumn()===0,'anonymous-like token call cannot create refresh schema');
KiComEngramOAuthContinuity::install($db);
$r=KiComEngramOAuthContinuity::mintForVerifiedRead($db,$access,$now);ok($r['scope']==='engram.read','mint keeps read-only scope');ok(!isset($r['access_token']),'mint does not replace current access token');
$out=KiComEngramOAuthContinuity::rotate($db,$r['refresh_token'],$client,$now+101);ok($out['scope']==='engram.read','refresh remains read-only');ok(isset($out['access_token'],$out['refresh_token']),'refresh rotates both tokens');
$row=$db->query("SELECT scope,owner_binding,credential_fingerprint FROM mirage_oauth_tokens ORDER BY issued_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);ok($row['scope']==='engram.read'&&$row['owner_binding']===$owner&&$row['credential_fingerprint']===$fp,'owner binding and credential preserved');
denied(fn()=>KiComEngramOAuthContinuity::rotate($db,$r['refresh_token'],$client,$now+102),'old refresh replay denied');
$wrong=$client;$wrong['connector_id']='other';denied(fn()=>KiComEngramOAuthContinuity::rotate($db,$out['refresh_token'],$wrong,$now+102),'connector mismatch denied');
$wrong=$client;$wrong['client_id']='evil';denied(fn()=>KiComEngramOAuthContinuity::rotate($db,$out['refresh_token'],$wrong,$now+102),'client mismatch denied');
$wrong=$client;$wrong['host_evidence_id']='other-host';denied(fn()=>KiComEngramOAuthContinuity::rotate($db,$out['refresh_token'],$wrong,$now+102),'host evidence mismatch denied');
$expiredAccess=rtrim(strtr(base64_encode(random_bytes(32)),"+/","-_"),"=");$q->execute([hash('sha256',$expiredAccess),$client['client_id'],$client['connector_id'],$client['host_evidence_id'],'https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP','engram.read',$owner,$fp,$now-100,$now-1]);denied(fn()=>KiComEngramOAuthContinuity::mintForVerifiedRead($db,$expiredAccess,$now),'expired access cannot mint refresh');
$writeAccess=rtrim(strtr(base64_encode(random_bytes(32)),"+/","-_"),"=");$q->execute([hash('sha256',$writeAccess),$client['client_id'],$client['connector_id'],$client['host_evidence_id'],'https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP','engram.write',$owner,$fp,$now-1,$now+100]);denied(fn()=>KiComEngramOAuthContinuity::mintForVerifiedRead($db,$writeAccess,$now),'write scope cannot enter read continuity path');
$db->exec("UPDATE mirage_oauth_refresh_tokens SET expires_at=".($now+101)." WHERE refresh_hash='".hash('sha256',$out['refresh_token'])."'");denied(fn()=>KiComEngramOAuthContinuity::rotate($db,$out['refresh_token'],$client,$now+102),'expired refresh denied');
$revokableAccess=rtrim(strtr(base64_encode(random_bytes(32)),"+/","-_"),"=");
$q->execute([hash('sha256',$revokableAccess),$client['client_id'],$client['connector_id'],$client['host_evidence_id'],'https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP','engram.read',$owner,$fp,$now-1,$now+100]);
$revokableRefresh=KiComEngramOAuthContinuity::mintForVerifiedRead($db,$revokableAccess,$now);
$db->prepare('UPDATE mirage_oauth_tokens SET revoked=1 WHERE token_hash=?')->execute([hash('sha256',$revokableAccess)]);
denied(fn()=>KiComEngramOAuthContinuity::rotate($db,$revokableRefresh['refresh_token'],$client,$now+1),'refresh linked to revoked access token denied');
ok($db->query('PRAGMA quick_check')->fetchColumn()==='ok','SQLite quick_check ok');
echo "KICOM_DEV77_ASSERTIONS=$n\n";
