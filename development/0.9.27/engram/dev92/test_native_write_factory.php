<?php
declare(strict_types=1);
require __DIR__.'/../dev72/KiComEngramWriteGrant.php';
require __DIR__.'/../dev87/KiComEngramMutationSchema.php';
require __DIR__.'/../dev75/KiComEngramRevisionAdapter.php';
require __DIR__.'/../dev88/KiComEngramVerifiedWriteProvenance.php';
require __DIR__.'/../dev76/KiComEngramCanonicalMutationService.php';
require __DIR__.'/../dev76/KiComEngramCanonicalMcpMutationController.php';
require __DIR__.'/../dev89/KiComEngramPrivateWriteConsentReader.php';
require __DIR__.'/KiComEngramNativeWriteFactory.php';

if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('pdo_sqlite required');
$n=0;
function pass92(bool $ok,string $msg):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$msg);++$n;echo "PASS $msg\n";}
function deny92(callable $f,string $msg):void{try{$r=$f();if($r===null||$r===false){pass92(true,$msg);return;}}catch(Throwable){pass92(true,$msg);return;}throw new RuntimeException('FAIL '.$msg);}
$root=sys_get_temp_dir().'/kicom-dev92-'.bin2hex(random_bytes(8));
$web=$root.'/public';$data=$root.'/engram-private/data';
if(!mkdir($web,0700,true)||!mkdir($data,0700,true))throw new RuntimeException('Cannot provision isolated synthetic private fixture');
register_shutdown_function(static function()use($root):void{
  $iter=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($iter as $entry){$entry->isDir()?@rmdir($entry->getPathname()):@unlink($entry->getPathname());}
  @rmdir($root);
});
$secret=str_repeat('a',64);
file_put_contents($data.'/mirage-write-signing.key',$secret);
chmod($data.'/mirage-write-signing.key',0600);
$engram=new PDO('sqlite:'.$data.'/engrams.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$engram->exec('CREATE TABLE engram_revisions(
    subject TEXT NOT NULL, namespace TEXT NOT NULL, id TEXT NOT NULL,
    revision INTEGER NOT NULL, kind TEXT NOT NULL, body TEXT NOT NULL,
    source_kind TEXT NOT NULL, source_ref TEXT NOT NULL,
    entry_state TEXT NOT NULL, previous_hash TEXT NOT NULL,
    revision_hash TEXT NOT NULL, recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(subject,namespace,id,revision))');
KiComEngramMutationSchema::prepareNew($engram);
unset($engram);chmod($data.'/engrams.sqlite',0600);
$oauth=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$oauth->exec('CREATE TABLE mirage_oauth_tokens(
token_hash TEXT PRIMARY KEY,client_id TEXT NOT NULL,connector_id TEXT NOT NULL,
host_evidence_id TEXT NOT NULL,resource TEXT NOT NULL,scope TEXT NOT NULL,
owner_binding TEXT NOT NULL,credential_fingerprint TEXT NOT NULL,
issued_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,revoked INTEGER NOT NULL DEFAULT 0)');
$oauth->exec('CREATE TABLE mirage_oauth_write_consents(
consent_ref TEXT PRIMARY KEY,owner TEXT NOT NULL,namespace TEXT NOT NULL,
client_id TEXT NOT NULL,connector_id TEXT NOT NULL,owner_binding TEXT NOT NULL,
credential_fingerprint TEXT NOT NULL,source_kind TEXT NOT NULL,
approved_at INTEGER NOT NULL,revoked_at INTEGER,token_hash TEXT UNIQUE)');
$now=time();
$bearer=str_repeat('a',43);$tokenHash=hash('sha256',$bearer);
$fp=str_repeat('b',64);$binding=hash('sha256',"mirage-owner\0".$fp);
$client='https://chatgpt.com/oauth/client.json';$connector='mirage-engram';
$host=str_repeat('c',64);$resource='https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP';
$ref='consent:'.hash('sha256','synthetic first-party passkey-reviewed grant');
$oauth->prepare('INSERT INTO mirage_oauth_tokens VALUES(?,?,?,?,?,?,?,?,?,?,0)')
 ->execute([$tokenHash,$client,$connector,$host,$resource,'engram.read engram.write',
  $binding,$fp,$now-10,$now+3600]);
$oauth->prepare('INSERT INTO mirage_oauth_write_consents VALUES (?,?,?,?,?,?,?,?,?,NULL,?)')
 ->execute([$ref,'mirage-owner','project',$client,$connector,$binding,$fp,'explicit_user',$now-10,$tokenHash]);
$identity=['authenticated'=>true,'connector_id'=>$connector,'credential_fingerprint'=>$fp,
  'owner_binding'=>$binding,'host_evidence_id'=>$host];
$approved=['source_kind'=>'explicit_user','source_ref'=>$ref];
$runtime=['engram_write_enabled'=>true,'operator_approved'=>true,
 'web_root'=>realpath($web),'data_dir'=>$data];
$build=static fn():?callable=>KiComEngramNativeWriteFactory::build(
    $oauth,$bearer,$identity,$approved,$runtime,$web,$now
);
$dispatch=$build();
pass92(is_callable($dispatch),'original-host approved native factory instantiated with pre-provisioned private files');
$written=$dispatch('engram_write',['body'=>'synthetic green locomotive'],'idem-dev92-001',$now);
pass92($written['revision']===1&&$written['state']==='active','native factory writes first real-canonical SQLite revision');
$second=$build();
pass92(is_callable($second),'independent factory opens the same existing private SQLite');
pass92($second('engram_write',['body'=>'synthetic green locomotive'],'idem-dev92-001',$now)===$written,
 'repeated idempotency key after new PHP-like factory returns original result');
$checkDb=new PDO('sqlite:'.$data.'/engrams.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
pass92((int)$checkDb->query('SELECT COUNT(*) FROM engram_revisions')->fetchColumn()===1,
 'retry does not duplicate the first memory');
pass92($checkDb->query('SELECT source_kind FROM engram_revisions LIMIT 1')->fetchColumn()==='explicit_user',
 'native memory provenance comes from private approval, never synthetic label');
deny92(fn()=>$second('engram_write',['body'=>'different request'],'idem-dev92-001',$now),'same idempotency cannot change memory body');
$updated=$dispatch('engram_update',['id'=>$written['id'],'expected_revision'=>1,
 'expected_hash'=>$written['revision_hash'],'body'=>'synthetic green locomotive revised'],'idem-dev92-002',$now);
pass92($updated['revision']===2,'native factory updates revision 2');
$archived=$second('engram_archive',['id'=>$written['id'],'expected_revision'=>2,
 'expected_hash'=>$updated['revision_hash']],'idem-dev92-003',$now);
pass92($archived['revision']===3&&$archived['state']==='withdrawn','native factory archives without hard delete');
pass92((int)$checkDb->query('SELECT COUNT(*) FROM engram_revisions')->fetchColumn()===3,
 'complete original canonical revision chain retained');
$oauth->exec("UPDATE mirage_oauth_tokens SET scope='engram.read'");
deny92(fn()=>$second('engram_write',['body'=>'not approved'],'idem-dev92-004',$now),
 'read-only OAuth token cannot write with previously constructed callback');
$oauth->exec("UPDATE mirage_oauth_tokens SET scope='engram.read engram.write'");
$oauth->exec('UPDATE mirage_oauth_write_consents SET revoked_at='.($now-1));
deny92(fn()=>$second('engram_write',['body'=>'not approved'],'idem-dev92-005',$now),
 'revoked first-party approval denies later requests without new Passkey prompt');
$oauth->exec('UPDATE mirage_oauth_write_consents SET revoked_at=NULL');
$disabled=$runtime;$disabled['engram_write_enabled']=false;
pass92(KiComEngramNativeWriteFactory::build($oauth,$bearer,$identity,$approved,$disabled,$web,$now)===null,
 'unconfigured host stays native read-only');
@unlink($data.'/mirage-write-signing.key');
pass92($build()===null,'missing private server signing key cannot auto-provision writer');
pass92($checkDb->query('PRAGMA quick_check')->fetchColumn()==='ok','original private SQLite integrity check');
echo "DEV92_NATIVE_FACTORY_ASSERTIONS=$n\n";
