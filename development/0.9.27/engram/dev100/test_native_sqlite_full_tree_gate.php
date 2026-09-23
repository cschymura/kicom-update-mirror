<?php
declare(strict_types=1);
/**
 * DEV-100 full-tree gate. This file MUST be executed from a staged native tree
 * produced from the SHA-pinned 0.9.37 parent, with its path passed as argv[1].
 * It is deliberately not an isolated module test.
 */
if ($argc !== 2) { fwrite(STDERR,"usage: php test_native_sqlite_full_tree_gate.php <staged-native-root>\n"); exit(2); }
$root=realpath($argv[1]);
if(!is_string($root)||!is_file($root.'/admin.php')||!is_file($root.'/api.php'))throw new RuntimeException('STAGED_NATIVE_ROOT_REQUIRED');
if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('Native PDO SQLite extension required');
require_once $root.'/lib.php';
require_once $root.'/modules/engram/KiComEngramMutationSchema.php';
require_once $root.'/modules/engram/KiComEngramActiveSchemaUpgrade.php';
// The staged OAuth transaction class must be the actual native merged class.
if(!class_exists('KiComEngramOAuthTransactions')) {
    $oauth=$root.'/modules/engram/KiComEngramOAuthTransactions.php';
    if(!is_file($oauth)) throw new RuntimeException('NATIVE_OAUTH_TRANSACTIONS_MISSING');
    require_once $oauth;
}
$n=0; function ok100(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$m);$n++;echo "PASS $m\n";}
function deny100(callable $f,string $m):void{try{$f();}catch(Throwable){ok100(true,$m);return;}throw new RuntimeException('FAIL '.$m);}
$base=sys_get_temp_dir().'/dev100-native-sqlite-'.bin2hex(random_bytes(7));$web=$base.'/public';$priv=$base.'/engram-private';$data=$priv.'/data';$back=$priv.'/backups';
$cleanup=static function()use($base):void{if(!is_dir($base))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $x){$x->isDir()?@rmdir($x->getPathname()):@unlink($x->getPathname());}@rmdir($base);};register_shutdown_function($cleanup);
foreach([$web,$data,$back] as $p){if(!mkdir($p,0700,true)&&!is_dir($p))throw new RuntimeException('fixture');}
// ActiveSchemaUpgrade pins original admin.php/lib.php at the trusted web root.
copy($root.'/admin.php',$web.'/admin.php'); copy($root.'/lib.php',$web.'/lib.php');
$fp=str_repeat('b',64);$runtime=['web_root'=>realpath($web),'data_dir'=>$data,'backups_dir'=>$back,'enabled'=>true,'operator_approved'=>true,'oauth_enabled'=>true,'mcp_connector_enabled'=>true,'rp_id'=>'kicom.rurtalbahn.info','expected_origin'=>'https://kicom.rurtalbahn.info','admin_subject'=>'mirage-owner','owner_binding'=>hash('sha256',"mirage-owner\0".$fp)];
$o=new PDO('sqlite:'.$data.'/mirage-oauth.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$o->exec('CREATE TABLE mirage_oauth_codes(code_hash TEXT PRIMARY KEY,consumed INTEGER NOT NULL DEFAULT 0)');$o->exec("INSERT INTO mirage_oauth_codes VALUES('old-code',0)");
$o->exec('CREATE TABLE mirage_oauth_tokens(token_hash TEXT PRIMARY KEY,scope TEXT NOT NULL)');$o->exec("INSERT INTO mirage_oauth_tokens VALUES('old-read','engram.read')");$o=null;
$e=new PDO('sqlite:'.$data.'/engrams.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$e->exec('CREATE TABLE engram_revisions(subject TEXT NOT NULL,namespace TEXT NOT NULL,id TEXT NOT NULL,revision INTEGER NOT NULL,kind TEXT NOT NULL,body TEXT NOT NULL,source_kind TEXT NOT NULL,source_ref TEXT NOT NULL,entry_state TEXT NOT NULL,previous_hash TEXT NOT NULL,revision_hash TEXT NOT NULL,PRIMARY KEY(subject,namespace,id,revision))');$e->exec("INSERT INTO engram_revisions VALUES('mirage-owner','project','old',1,'technical','synthetic-native-full-tree','synthetic_test','dev100:fixture','active','".str_repeat('0',64)."','".str_repeat('a',64)."')");$e=null;chmod($data.'/mirage-oauth.sqlite',0600);chmod($data.'/engrams.sqlite',0600);
$pre=KiComEngramActiveSchemaUpgrade::preflight($web,$runtime);ok100(($pre['ok']??false)===true,'native merged preflight');
$bad=$runtime;$bad['enabled']=false;deny100(fn()=>KiComEngramActiveSchemaUpgrade::applyAfterVerifiedPasskey($web,$bad),'inactive runtime denied before DDL');ok100(count(glob($back.'/dev95-*.sqlite')?:[])===0,'denied runtime creates no snapshots');
$r=KiComEngramActiveSchemaUpgrade::applyAfterVerifiedPasskey($web,$runtime);ok100(($r['code']??null)==='PRIVATE_SCHEMAS_PREPARED'&&$r['write_scope_activated']===false,'native merged additive upgrade remains non-activating');
$copies=glob($back.'/dev95-*.sqlite')?:[];ok100(count($copies)===2,'both native SQLite pre-DDL snapshots created');foreach($copies as $f){ok100((fileperms($f)&0077)===0,'snapshot private');$s=new PDO('sqlite:'.$f);ok100($s->query('PRAGMA quick_check')->fetchColumn()==='ok','snapshot quick_check');}
$o=new PDO('sqlite:'.$data.'/mirage-oauth.sqlite');$e=new PDO('sqlite:'.$data.'/engrams.sqlite');ok100($o->query("SELECT scope FROM mirage_oauth_tokens WHERE token_hash='old-read'")->fetchColumn()==='engram.read','existing read token not upgraded');ok100($o->query("SELECT requested_scope FROM mirage_oauth_codes WHERE code_hash='old-code'")->fetchColumn()==='engram.read','pending old authorization remains read-only');KiComEngramMutationSchema::assertReady($e);ok100($e->query('SELECT body FROM engram_revisions LIMIT 1')->fetchColumn()==='synthetic-native-full-tree','existing synthetic memory preserved');ok100($o->query('PRAGMA quick_check')->fetchColumn()==='ok'&&$e->query('PRAGMA quick_check')->fetchColumn()==='ok','active databases quick_check after upgrade');
$key=$data.'/mirage-write-signing.key';ok100(is_file($key)&&(fileperms($key)&0077)===0&&preg_match('/\A[a-f0-9]{64}\z/D',(string)file_get_contents($key))===1,'server signing key private and valid');
echo "DEV100_NATIVE_SQLITE_FULL_TREE_ASSERTIONS=$n\n";
