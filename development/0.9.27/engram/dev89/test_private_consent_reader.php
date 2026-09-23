<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramPrivateWriteConsentReader.php';
require __DIR__.'/../dev88/KiComEngramVerifiedWriteProvenance.php';
if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('ext-pdo_sqlite required');
$n=0;
function ok89(bool $condition,string $label):void{
 global $n;if(!$condition)throw new RuntimeException('FAIL '.$label);
 $n++;echo "PASS $label\n";
}
function refused89(callable $call,string $label):void{
 try{$call();}catch(Throwable){ok89(true,$label);return;}
 throw new RuntimeException('FAIL '.$label);
}
function firstPartyDb():PDO{
 $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 // Actual original KiCom OAuth token row columns and scope semantics.
 $db->exec('CREATE TABLE mirage_oauth_tokens (
   token_hash TEXT PRIMARY KEY,client_id TEXT NOT NULL,connector_id TEXT NOT NULL,
   host_evidence_id TEXT NOT NULL,resource TEXT NOT NULL,scope TEXT NOT NULL,
   owner_binding TEXT NOT NULL,credential_fingerprint TEXT NOT NULL,
   issued_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,
   revoked INTEGER NOT NULL DEFAULT 0)');
 return $db;
}
$db=firstPartyDb();
$now=1790130000;
$owner='mirage-owner';$ns='project';
$client='https://chatgpt.com/oauth/client.json';
$connector='mirage-engram';$credential=str_repeat('b',64);
$ownerBinding=hash('sha256',$owner."\0".$credential);
$tokenHash=hash('sha256','synthetic-only-access-token');
$ref='consent:'.hash('sha256','synthetic-operator-passkey-approved-01');
$claim=['owner'=>$owner,'namespace'=>$ns,'token_fingerprint'=>$tokenHash,
 'source_kind'=>'approved_summary','source_ref'=>$ref];
$reader=new KiComEngramPrivateWriteConsentReader($db);
ok89($reader->verify($claim,$now)===false,'unprepared consent table causes refusal without DDL');
ok89((int)$db->query("SELECT count(*) FROM sqlite_master WHERE name='mirage_oauth_write_consents'")->fetchColumn()===0,'bearer lookup cannot initialize consent DB');
$db->exec('CREATE TABLE mirage_oauth_write_consents(
 consent_ref TEXT PRIMARY KEY,owner TEXT NOT NULL,namespace TEXT NOT NULL,
 client_id TEXT NOT NULL,connector_id TEXT NOT NULL,
 owner_binding TEXT NOT NULL,credential_fingerprint TEXT NOT NULL,
 source_kind TEXT NOT NULL,approved_at INTEGER NOT NULL,revoked_at INTEGER)');
$q=$db->prepare('INSERT INTO mirage_oauth_write_consents VALUES (?,?,?,?,?,?,?,?,?,NULL)');
$q->execute([$ref,$owner,$ns,$client,$connector,$ownerBinding,$credential,'approved_summary',$now-100]);
$q=$db->prepare('INSERT INTO mirage_oauth_tokens
(token_hash,client_id,connector_id,host_evidence_id,resource,scope,
 owner_binding,credential_fingerprint,issued_at,expires_at,revoked)
VALUES (?,?,?,?,?,?,?,?,?,?,0)');
$q->execute([$tokenHash,$client,$connector,str_repeat('c',64),
 'https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP','engram.read',
 $ownerBinding,$credential,$now-100,$now+3600]);
ok89($reader->verify($claim,$now)===false,'old engram.read token is not implicitly upgraded');
$db->prepare('UPDATE mirage_oauth_tokens SET scope=? WHERE token_hash=?')->execute(['engram.write',$tokenHash]);
ok89($reader->verify($claim,$now)===true,'separately authorized unrevoked owner-bound WRITE token and consent accepted');
$copy=$claim;$copy['owner']='other-owner';
ok89($reader->verify($copy,$now)===false,'foreign owner denied');
$copy=$claim;$copy['namespace']='other-project';
ok89($reader->verify($copy,$now)===false,'foreign namespace denied');
$copy=$claim;$copy['source_ref']='consent:'.str_repeat('0',64);
ok89($reader->verify($copy,$now)===false,'fabricated receipt denied');
$copy=$claim;$copy['source_kind']='verified_checkpoint';
ok89($reader->verify($copy,$now)===false,'consent source-kind cannot be switched');
$copy=$claim;$copy['token_fingerprint']=str_repeat('a',64);
ok89($reader->verify($copy,$now)===false,'foreign bearer denied');
$copy=$claim;$copy['is_approved']=true;
ok89($reader->verify($copy,$now)===false,'MCP supplied approval flag denied');
$db->prepare('UPDATE mirage_oauth_tokens SET revoked=1 WHERE token_hash=?')->execute([$tokenHash]);
ok89($reader->verify($claim,$now)===false,'revoked OAuth bearer denied');
$db->prepare('UPDATE mirage_oauth_tokens SET revoked=0,expires_at=? WHERE token_hash=?')->execute([$now,$tokenHash]);
ok89($reader->verify($claim,$now)===false,'expired OAuth bearer denied');
$db->prepare('UPDATE mirage_oauth_tokens SET expires_at=?,owner_binding=? WHERE token_hash=?')
 ->execute([$now+3600,str_repeat('d',64),$tokenHash]);
ok89($reader->verify($claim,$now)===false,'owner-rebound OAuth token denied');
$db->prepare('UPDATE mirage_oauth_tokens SET owner_binding=? WHERE token_hash=?')->execute([$ownerBinding,$tokenHash]);
$db->prepare('UPDATE mirage_oauth_write_consents SET revoked_at=? WHERE consent_ref=?')->execute([$now-1,$ref]);
ok89($reader->verify($claim,$now)===false,'revoked operator consent denied');
$db->prepare('UPDATE mirage_oauth_write_consents SET revoked_at=NULL,credential_fingerprint=? WHERE consent_ref=?')
 ->execute([str_repeat('e',64),$ref]);
ok89($reader->verify($claim,$now)===false,'rebound passkey denied');
$db->prepare('UPDATE mirage_oauth_write_consents SET credential_fingerprint=? WHERE consent_ref=?')->execute([$credential,$ref]);
$db->prepare('UPDATE mirage_oauth_tokens SET scope=? WHERE token_hash=?')->execute(['engram.read engram.write',$tokenHash]);
ok89($reader->verify($claim,$now)===true,'separately approved combined read-write scope accepted');
$server=['authenticated'=>true,'owner'=>$owner,'namespace'=>$ns,
 'token_fingerprint'=>$tokenHash,'scopes'=>['engram.read','engram.write'],
 'server_provenance'=>[
   'source_kind'=>'approved_summary','source_ref'=>$ref,'verified'=>true,
   'owner'=>$owner,'namespace'=>$ns,'token_fingerprint'=>$tokenHash,
 ]];
refused89(fn()=>KiComEngramVerifiedWriteProvenance::resolve($server),'claimed verified=true without private reader is refused');
$lookup=static fn(array $exact):bool=>$reader->verify($exact,$now);
ok89(KiComEngramVerifiedWriteProvenance::resolve($server,$lookup)===[
 'source_kind'=>'approved_summary','source_ref'=>$ref
],'real-memory provenance accepted only after independent SQLite receipt lookup');
$db->prepare('UPDATE mirage_oauth_tokens SET scope=? WHERE token_hash=?')->execute(['engram.read',$tokenHash]);
refused89(fn()=>KiComEngramVerifiedWriteProvenance::resolve($server,$lookup),'revoked write permission in current DB defeats previously claimed OAuth scope');
ok89($db->query('PRAGMA quick_check')->fetchColumn()==='ok','actual SQLite quick_check');
echo "DEV89_PRIVATE_CONSENT_ASSERTIONS=$n\n";
