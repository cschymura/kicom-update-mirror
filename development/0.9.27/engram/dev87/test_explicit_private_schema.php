<?php
declare(strict_types=1);
require __DIR__.'/../dev75/KiComEngramRevisionAdapter.php';
if (!extension_loaded('pdo_sqlite'))throw new RuntimeException('pdo_sqlite required');
$n=0;
function good(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$m);echo 'PASS '.$m."\n";++$n;}
function refuses(callable $fn,string $m):void{
  try{$fn();}catch(Throwable){good(true,$m);return;}
  throw new RuntimeException('FAIL '.$m);
}
function memoryDb():PDO{
  $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $db->exec('CREATE TABLE engram_revisions (
    subject TEXT NOT NULL,namespace TEXT NOT NULL,id TEXT NOT NULL,
    revision INTEGER NOT NULL,kind TEXT NOT NULL,body TEXT NOT NULL,
    source_kind TEXT NOT NULL,source_ref TEXT NOT NULL,
    entry_state TEXT NOT NULL,previous_hash TEXT NOT NULL,
    revision_hash TEXT NOT NULL,
    PRIMARY KEY(subject,namespace,id,revision))');
  return $db;
}
$db=memoryDb();
refuses(fn()=>new KiComEngramRevisionAdapter($db),'MCP adapter rejects unprepared private schema');
good((int)$db->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name LIKE 'engram_mutation_%'")->fetchColumn()===0,'unauthenticated request did not create tables');
KiComEngramMutationSchema::prepareNew($db);
good((int)$db->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name LIKE 'engram_mutation_%'")->fetchColumn()===2,'explicit preparation creates receipt and audit');
KiComEngramMutationSchema::prepareNew($db);
good((int)$db->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name LIKE 'engram_mutation_%'")->fetchColumn()===2,'explicit preparation idempotent');
$adapter=new KiComEngramRevisionAdapter($db);
good($adapter instanceof KiComEngramRevisionAdapter,'prepared schema permits read-only adapter initialization');
good($db->query('PRAGMA quick_check')->fetchColumn()==='ok','prepared schema passes sqlite quick_check');
$db->exec('DROP TABLE engram_mutation_audit');
refuses(fn()=>new KiComEngramRevisionAdapter($db),'adapter refuses partially removed private schema');
refuses(fn()=>KiComEngramMutationSchema::prepareNew($db),'operator setup refuses inconsistent partial prior state');
good((int)$db->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='engram_mutation_audit'")->fetchColumn()===0,'failed partial-schema validation never changes private DB');
$broken=memoryDb();
$broken->exec('CREATE TABLE engram_mutation_receipts(
 subject TEXT,namespace TEXT,idempotency_key TEXT,operation TEXT,
 request_hash TEXT,result_json TEXT,created_at TEXT,grant_nonce TEXT)');
$broken->exec('CREATE TABLE engram_mutation_audit(
 seq INTEGER,subject TEXT,namespace TEXT,operation TEXT,
 engram_id TEXT,revision INTEGER,outcome TEXT,created_at TEXT)');
refuses(fn()=>new KiComEngramRevisionAdapter($broken),'adapter rejects missing UNIQUE nonce guarantee');
refuses(fn()=>KiComEngramMutationSchema::prepareNew($broken),'operator setup does not bless incompatible legacy schema');
good((int)$broken->query('SELECT COUNT(*) FROM engram_mutation_receipts')->fetchColumn()===0,'no forged receipt inserted');
good($broken->query('PRAGMA quick_check')->fetchColumn()==='ok','incompatible database preserved without mutation');
echo 'DEV87_EXPLICIT_SCHEMA_ASSERTIONS='.$n."\n";
