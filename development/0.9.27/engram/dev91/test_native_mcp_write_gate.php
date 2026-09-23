<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramNativeMcpWriteGate.php';
if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('pdo_sqlite required');
$n=0;
function ck(bool $yes,string $label):void{global $n;if(!$yes)throw new RuntimeException('FAIL '.$label);$n++;echo "PASS $label\n";}
$now=1790130000;$bearer=str_repeat('a',43);$fp=str_repeat('b',64);$host=str_repeat('c',64);
$owner='mirage-owner';$binding=hash('sha256',$owner."\0".$fp);
$client='https://chatgpt.com/oauth/client.json';$connector='mirage-engram';
$resource='https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP';
$ref='consent:'.hash('sha256','synthetic-only-approved-receipt');
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE mirage_oauth_tokens(token_hash TEXT PRIMARY KEY,client_id TEXT,
connector_id TEXT,host_evidence_id TEXT,resource TEXT,scope TEXT,owner_binding TEXT,
credential_fingerprint TEXT,issued_at INTEGER,expires_at INTEGER,revoked INTEGER)');
$db->exec('CREATE TABLE mirage_oauth_write_consents(consent_ref TEXT PRIMARY KEY,owner TEXT,
namespace TEXT,client_id TEXT,connector_id TEXT,owner_binding TEXT,credential_fingerprint TEXT,
source_kind TEXT,approved_at INTEGER,revoked_at INTEGER)');
$db->prepare('INSERT INTO mirage_oauth_tokens VALUES (?,?,?,?,?,?,?,?,?,?,?)')
->execute([hash('sha256',$bearer),$client,$connector,$host,$resource,
'engram.read engram.write',$binding,$fp,$now-1,$now+3600,0]);
$db->prepare('INSERT INTO mirage_oauth_write_consents VALUES (?,?,?,?,?,?,?,?,?,NULL)')
->execute([$ref,$owner,'project',$client,$connector,$binding,$fp,'explicit_user',$now-1]);
$identity=['authenticated'=>true,'connector_id'=>$connector,
'credential_fingerprint'=>$fp,'owner_binding'=>$binding,'host_evidence_id'=>$host];
$approved=['enabled'=>true,'subject'=>$owner,'credential_fingerprint'=>$fp,
'namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']];
$owners=static fn(string $fingerprint):array=>$approved;
$check=static fn(?array $override=null,?callable $registry=null):?array=>
  KiComEngramNativeMcpWriteGate::approved($db,$bearer,$override??$identity,$registry??$owners,$now);
ck($check()===['source_kind'=>'explicit_user','source_ref'=>$ref],'current combined token and private consent eligible');
$db->exec("UPDATE mirage_oauth_tokens SET scope='engram.read'");
ck($check()===null,'read token cannot become write');
$db->exec("UPDATE mirage_oauth_tokens SET scope='engram.read engram.write',revoked=1");
ck($check()===null,'revoked token denied');
$db->exec('UPDATE mirage_oauth_tokens SET revoked=0,expires_at='.$now);
ck($check()===null,'expired token denied');
$db->exec('UPDATE mirage_oauth_tokens SET expires_at='.($now+3600));
$db->exec('UPDATE mirage_oauth_write_consents SET revoked_at='.$now);
ck($check()===null,'revoked consent denied');
$db->exec("UPDATE mirage_oauth_write_consents SET revoked_at=NULL,namespace='foreign'");
ck($check()===null,'foreign namespace denied');
$db->exec("UPDATE mirage_oauth_write_consents SET namespace='project',credential_fingerprint='".str_repeat('f',64)."'");
ck($check()===null,'revoked or rebound passkey fingerprint denied');
$db->prepare('UPDATE mirage_oauth_write_consents SET credential_fingerprint=?,approved_at=?')->execute([$fp,$now+1]);
ck($check()===null,'future-dated consent denied');
$db->exec('UPDATE mirage_oauth_write_consents SET approved_at='.($now-1));
$foreign=$identity;$foreign['host_evidence_id']=str_repeat('d',64);
ck($check($foreign)===null,'foreign host denied');
$foreign=$identity;$foreign['owner_binding']=str_repeat('d',64);
ck($check($foreign)===null,'owner binding mismatch denied');
$disabled=$approved;$disabled['enabled']=false;
ck($check(null,static fn(string $fp):array=>$disabled)===null,'disabled owner denied');
$readonly=$approved;$readonly['engram_rights']=['engram.read'];
ck($check(null,static fn(string $fp):array=>$readonly)===null,'owner without write right denied');
ck($check()!==null,'revalidated original first-party state');
$db->exec('DROP TABLE mirage_oauth_write_consents');
ck($check()===null,'missing private consent table fails closed, never auto-creates');
ck((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name='mirage_oauth_write_consents'")->fetchColumn()===0,'private schema not mutated during bearer request');
ck($db->query('PRAGMA quick_check')->fetchColumn()==='ok','SQLite integrity after negative checks');
echo "DEV91_NATIVE_GATE_ASSERTIONS=$n\n";
