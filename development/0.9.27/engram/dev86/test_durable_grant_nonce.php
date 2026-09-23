<?php
declare(strict_types=1);
/**
 * DEV-86 synthetic PDO SQLite regression for durable grant nonce and request replay.
 * No personal memories; does not connect to the live KiCom service.
 */
require __DIR__.'/../dev76/KiComEngramCanonicalMcpMutationController.php';
$n=0;
function check86(bool $v,string $name):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$name);$n++;echo "PASS ".$name."\n";}
function denied86(callable $fn,string $name):void{
  try{$fn();}catch(Throwable){check86(true,$name);return;}
  throw new RuntimeException('FAIL '.$name);
}
if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('ext-pdo_sqlite required');
$sqliteFile=tempnam(sys_get_temp_dir(),'kicom-dev86-');
if($sqliteFile===false)throw new RuntimeException('Cannot create synthetic SQLite fixture');
chmod($sqliteFile,0600);
register_shutdown_function(static function()use($sqliteFile):void{
  foreach([$sqliteFile,$sqliteFile.'-wal',$sqliteFile.'-shm'] as $path)if(is_file($path))@unlink($path);
});
$db=new PDO('sqlite:'.$sqliteFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db2=new PDO('sqlite:'.$sqliteFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE engram_revisions(
  subject TEXT NOT NULL,namespace TEXT NOT NULL,id TEXT NOT NULL,
  revision INTEGER NOT NULL,kind TEXT NOT NULL,body TEXT NOT NULL,
  source_kind TEXT NOT NULL,source_ref TEXT NOT NULL,entry_state TEXT NOT NULL,
  previous_hash TEXT NOT NULL,revision_hash TEXT NOT NULL,
  PRIMARY KEY(subject,namespace,id,revision))');
KiComEngramMutationSchema::prepareNew($db);
$grants=new KiComEngramWriteGrant(str_repeat('s',48));
function instance86(PDO $db,KiComEngramWriteGrant $grants):KiComEngramCanonicalMcpMutationController{
  return new KiComEngramCanonicalMcpMutationController(
    new KiComEngramCanonicalMutationService($grants,new KiComEngramRevisionAdapter($db)));
}
$oauth=[
 'authenticated'=>true,'owner'=>'owner-0001','namespace'=>'project-01',
 'connector_id'=>'connector-01','token_fingerprint'=>'fingerprint-01',
 'scopes'=>['engram.write']
];
$now=1790129000;
$oauth['synthetic_environment']=true;
$oauth['server_provenance']=[
 'source_kind'=>'synthetic_test','source_ref'=>'dev-test:synthetic-001',
 'verified'=>true,'owner'=>$oauth['owner'],'namespace'=>$oauth['namespace'],
 'token_fingerprint'=>$oauth['token_fingerprint']
];
function grant86(KiComEngramWriteGrant $g,array $o,string $op,string $nonce,int $now):string {
  return $g->issueSynthetic([
    'v'=>1,'grant_id'=>'grant-'.$nonce,'owner'=>$o['owner'],'namespace'=>$o['namespace'],
    'connector_id'=>$o['connector_id'],'token_fingerprint'=>$o['token_fingerprint'],
    'operations'=>[$op],'nonce'=>$nonce,'iat'=>$now-1,'exp'=>$now+60
  ]);
}
function call86(KiComEngramCanonicalMcpMutationController $c,array $o,string $tool,string $grant,
    string $idem,array $input,int $now):array {
  return $c->handle($o,[
    'tool'=>$tool,'write_grant'=>$grant,'idempotency_key'=>$idem,'input'=>$input
  ],$now);
}
$first=instance86($db,$grants);
$g=grant86($grants,$oauth,'engram_write','nonce-0001',$now);
$a=call86($first,$oauth,'engram_write',$g,'idem-0001',['body'=>'synthetic memory'], $now);
check86($a['revision']===1&&strlen($a['id'])===32,'first write revision 1');
$independent=instance86($db2,$grants);
check86($db!==$db2,'independent PDO connections to the same private synthetic SQLite file');
$b=call86($independent,$oauth,'engram_write',$g,'idem-0001',['body'=>'synthetic memory'],$now);
check86($a===$b,'same grant plus exact idempotency repeats same receipt in another controller instance');
check86((int)$db->query('SELECT COUNT(*) FROM engram_revisions')->fetchColumn()===1,'idempotent retry does not duplicate revision');
denied86(fn()=>call86($independent,$oauth,'engram_write',$g,'idem-0002',['body'=>'synthetic memory'],$now),'same grant nonce with different idempotency rejected across instances');
denied86(fn()=>call86($independent,$oauth,'engram_write',$g,'idem-0001',['body'=>'tampered content'],$now),'same idempotency with changed content rejected');
$g2=grant86($grants,$oauth,'engram_write','nonce-0002',$now);
denied86(fn()=>call86($independent,$oauth,'engram_write',$g2,'idem-0001',['body'=>'synthetic memory'],$now),'different grant cannot claim existing receipt');
check86((int)$db2->query('SELECT COUNT(*) FROM engram_mutation_receipts')->fetchColumn()===1,'exactly one committed nonce receipt visible across connections');
check86((int)$db->query('SELECT COUNT(*) FROM engram_mutation_audit')->fetchColumn()===1,'one audit event for idempotent write');
$read=$oauth;$read['scopes']=['engram.read'];
denied86(fn()=>call86($independent,$read,'engram_write',grant86($grants,$oauth,'engram_write','nonce-0003',$now),'idem-0003',['body'=>'x'],$now),'read-only OAuth token cannot write');
$other=$oauth;$other['namespace']='project-02';
denied86(fn()=>call86($independent,$other,'engram_write',$g,'idem-0003',['body'=>'x'],$now),'different namespace cannot consume owner grant');
$gu=grant86($grants,$oauth,'engram_update','nonce-0004',$now);
$u=call86($independent,$oauth,'engram_update',$gu,'idem-0004',[
  'id'=>$a['id'],'expected_revision'=>1,'expected_hash'=>$a['revision_hash'],'body'=>'synthetic revised'],$now);
check86($u['revision']===2,'update appended revision 2');
denied86(fn()=>call86($first,$oauth,'engram_update',$gu,'idem-0005',[
  'id'=>$a['id'],'expected_revision'=>1,'expected_hash'=>$a['revision_hash'],'body'=>'stale'],$now),'used update grant rejected on different request');
$ga=grant86($grants,$oauth,'engram_archive','nonce-0005',$now);
$arc=call86($first,$oauth,'engram_archive',$ga,'idem-0006',[
  'id'=>$a['id'],'expected_revision'=>2,'expected_hash'=>$u['revision_hash']],$now);
check86($arc['revision']===3&&$arc['state']==='withdrawn','archive appends withdrawn revision');
check86((int)$db->query('SELECT COUNT(*) FROM engram_revisions')->fetchColumn()===3,'all 3 revisions retained');
check86($db->query('PRAGMA quick_check')->fetchColumn()==='ok','SQLite integrity after replay tests');
echo "DEV86_DURABLE_GRANT_ASSERTIONS=$n\n";
