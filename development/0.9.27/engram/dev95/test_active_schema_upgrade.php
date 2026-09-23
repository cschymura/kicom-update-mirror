<?php
declare(strict_types=1);
/**
 * DEV-95: synthetic ORIGINAL-SCHEMA SHAPE using actual ext-pdo_sqlite and
 * the actual DEV-87 private Engram mutation table validator.
 * This is NOT a native Passkey or actual 0.9.37 OAuth class E2E test.
 */
require __DIR__.'/../dev87/KiComEngramMutationSchema.php';
final class KiComEngramOAuthTransactions {
    public static function prepareWriteSchema(PDO $db):void {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $cols=$db->query('PRAGMA table_info(mirage_oauth_codes)')->fetchAll(PDO::FETCH_COLUMN,1);
            if(!in_array('requested_scope',$cols,true))
                $db->exec("ALTER TABLE mirage_oauth_codes ADD COLUMN requested_scope TEXT NOT NULL DEFAULT 'engram.read'");
            if(!in_array('write_consent_ref',$cols,true))
                $db->exec("ALTER TABLE mirage_oauth_codes ADD COLUMN write_consent_ref TEXT NOT NULL DEFAULT ''");
            $db->exec('CREATE TABLE IF NOT EXISTS mirage_oauth_write_consents(
                consent_ref TEXT PRIMARY KEY,owner TEXT NOT NULL,
                namespace TEXT NOT NULL,client_id TEXT NOT NULL,
                connector_id TEXT NOT NULL,owner_binding TEXT NOT NULL,
                credential_fingerprint TEXT NOT NULL,source_kind TEXT NOT NULL,
                approved_at INTEGER NOT NULL,revoked_at INTEGER,token_hash TEXT UNIQUE)');
            $db->exec('COMMIT');
        }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}
    }
    public static function prepareRefreshSchema(PDO $db):void {
        $db->exec('CREATE TABLE IF NOT EXISTS mirage_oauth_refresh_tokens (
            refresh_hash TEXT PRIMARY KEY,client_id TEXT NOT NULL,
            connector_id TEXT NOT NULL,host_evidence_id TEXT NOT NULL,
            resource TEXT NOT NULL,scope TEXT NOT NULL,
            owner_binding TEXT NOT NULL,credential_fingerprint TEXT NOT NULL,
            issued_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,
            consumed INTEGER NOT NULL DEFAULT 0,revoked INTEGER NOT NULL DEFAULT 0,
            parent_access_hash TEXT NOT NULL UNIQUE)');
    }
}
require __DIR__.'/KiComEngramActiveSchemaUpgrade.php';
if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('Native PDO SQLite extension required');
$n=0;
function yes95(bool $v,string $label):void {global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function no95(callable $f,string $label):void {
    try{$f();}catch(Throwable){yes95(true,$label);return;}
    throw new RuntimeException('FAIL '.$label);
}
$root=sys_get_temp_dir().'/dev95-'.bin2hex(random_bytes(8));
$web=$root.'/public';$private=$root.'/engram-private';$data=$private.'/data';
$backup=$private.'/backups';
$cleanup=static function()use($root):void {
    if(!is_dir($root))return;
    $iter=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iter as $item) {
        if($item->isDir())@rmdir($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($root);
};
register_shutdown_function($cleanup);
foreach([$web,$data,$backup] as $path) {
    if(!mkdir($path,0700,true)&&!is_dir($path))throw new RuntimeException('Cannot build fixture');
}
file_put_contents($web.'/admin.php','<?php /* synthetic only */');file_put_contents($web.'/lib.php','<?php /* synthetic only */');
$fp=str_repeat('b',64);
$runtime=['web_root'=>realpath($web),'data_dir'=>$data,'backups_dir'=>$backup,
'enabled'=>true,'operator_approved'=>true,'oauth_enabled'=>true,'mcp_connector_enabled'=>true,
'rp_id'=>'kicom.rurtalbahn.info','expected_origin'=>'https://kicom.rurtalbahn.info',
'admin_subject'=>'mirage-owner','owner_binding'=>hash('sha256',"mirage-owner\0".$fp)];
$o=new PDO('sqlite:'.$data.'/mirage-oauth.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$o->exec('CREATE TABLE mirage_oauth_codes(code_hash TEXT PRIMARY KEY,consumed INTEGER NOT NULL DEFAULT 0)');
$o->exec("INSERT INTO mirage_oauth_codes VALUES('old-code-1',0)");
$o->exec('CREATE TABLE mirage_oauth_tokens(token_hash TEXT PRIMARY KEY,scope TEXT NOT NULL)');
$o->exec("INSERT INTO mirage_oauth_tokens VALUES('old-read-1','engram.read')");
$o=null;
$e=new PDO('sqlite:'.$data.'/engrams.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$e->exec('CREATE TABLE engram_revisions(
  subject TEXT NOT NULL,namespace TEXT NOT NULL,id TEXT NOT NULL,
  revision INTEGER NOT NULL,kind TEXT NOT NULL,body TEXT NOT NULL,
  source_kind TEXT NOT NULL,source_ref TEXT NOT NULL,entry_state TEXT NOT NULL,
  previous_hash TEXT NOT NULL,revision_hash TEXT NOT NULL,
  PRIMARY KEY(subject,namespace,id,revision))');
$e->exec("INSERT INTO engram_revisions VALUES('mirage-owner','project','old-memory',1,
 'technical','synthetic-only-original-memory','synthetic_test','dev-test:fixture',
 'active','".str_repeat('0',64)."','".str_repeat('a',64)."')");
$e=null;
chmod($data.'/mirage-oauth.sqlite',0600);chmod($data.'/engrams.sqlite',0600);
$before=KiComEngramActiveSchemaUpgrade::preflight($web,$runtime);
yes95($before['ok']===true,'original active private DB preflight succeeds');
$o=new PDO('sqlite:'.$data.'/mirage-oauth.sqlite');$e=new PDO('sqlite:'.$data.'/engrams.sqlite');
yes95((int)$o->query("SELECT COUNT(*) FROM sqlite_master WHERE name='mirage_oauth_write_consents'")->fetchColumn()===0,'read-only preflight does not create OAuth write table');
yes95((int)$e->query("SELECT COUNT(*) FROM sqlite_master WHERE name='engram_mutation_receipts'")->fetchColumn()===0,'read-only preflight does not create Engram mutation receipts');
$o=null;$e=null;
$bad=$runtime;$bad['web_root']=$data;
no95(fn()=>KiComEngramActiveSchemaUpgrade::applyAfterVerifiedPasskey($web,$bad),'caller cannot substitute web/private path');
yes95(count(glob($backup.'/dev95-*.sqlite')?:[])===0,'denied runtime does not create backup or run DDL');
$result=KiComEngramActiveSchemaUpgrade::applyAfterVerifiedPasskey($web,$runtime);
yes95(($result['code']??null)==='PRIVATE_SCHEMAS_PREPARED'
   &&$result['write_scope_activated']===false
   &&$result['oauth_backup_created']===true
   &&$result['engram_backup_created']===true,'operator upgrade completes without activation');
$copies=glob($backup.'/dev95-*.sqlite')?:[];
yes95(count($copies)===2,'both private SQLite backups completed BEFORE upgrade');
foreach($copies as $file){
    yes95((fileperms($file)&0077)===0,'backup file remains mode 0600');
    $snapshot=new PDO('sqlite:'.$file);
    yes95($snapshot->query('PRAGMA quick_check')->fetchColumn()==='ok','backup snapshot SQLite integrity');
}
$ob=array_values(array_filter($copies,fn($f)=>str_contains($f,'mirage-oauth.sqlite')))[0];
$eb=array_values(array_filter($copies,fn($f)=>str_contains($f,'engrams.sqlite')))[0];
$s=new PDO('sqlite:'.$ob);
yes95((int)$s->query("SELECT COUNT(*) FROM sqlite_master WHERE name='mirage_oauth_write_consents'")->fetchColumn()===0,'OAuth backup is pre-DDL');
yes95($s->query("SELECT scope FROM mirage_oauth_tokens WHERE token_hash='old-read-1'")->fetchColumn()==='engram.read','OAuth backup retains original read token');
$s=null;$s=new PDO('sqlite:'.$eb);
yes95((int)$s->query("SELECT COUNT(*) FROM sqlite_master WHERE name='engram_mutation_receipts'")->fetchColumn()===0,'Engram backup is pre-DDL');
yes95($s->query('SELECT body FROM engram_revisions LIMIT 1')->fetchColumn()==='synthetic-only-original-memory','original memory retained in backup');
$s=null;
$o=new PDO('sqlite:'.$data.'/mirage-oauth.sqlite');$e=new PDO('sqlite:'.$data.'/engrams.sqlite');
yes95($o->query("SELECT scope FROM mirage_oauth_tokens WHERE token_hash='old-read-1'")->fetchColumn()==='engram.read','original read bearer unchanged after migration');
yes95($o->query("SELECT requested_scope FROM mirage_oauth_codes WHERE code_hash='old-code-1'")->fetchColumn()==='engram.read','existing pending authorization remains read-only');
yes95((int)$o->query("SELECT COUNT(*) FROM sqlite_master WHERE name='mirage_oauth_refresh_tokens'")->fetchColumn()===1,'read/combined refresh schema operator provisioned');
yes95((int)$o->query("SELECT COUNT(*) FROM sqlite_master WHERE name='mirage_oauth_write_consents'")->fetchColumn()===1,'private write-consent schema operator provisioned');
KiComEngramMutationSchema::assertReady($e);
yes95($e->query('SELECT body FROM engram_revisions LIMIT 1')->fetchColumn()==='synthetic-only-original-memory','original canonical memory retained after upgrade');
$key=$data.'/mirage-write-signing.key';
yes95(is_file($key)&&(fileperms($key)&0077)===0
    &&preg_match('/\A[a-f0-9]{64}\z/D',(string)file_get_contents($key))===1,'new server signing key private and valid');
$stableKey=file_get_contents($key);$o=null;$e=null;
$result=KiComEngramActiveSchemaUpgrade::applyAfterVerifiedPasskey($web,$runtime);
yes95($result['ok']===true,'explicit repeat is additive and idempotent');
yes95(file_get_contents($key)===$stableKey,'repeat never rotates existing signing key');
yes95(count(glob($backup.'/dev95-*.sqlite')?:[])===4,'repeat preserves previous backup and creates new snapshots');
$bad=$runtime;$bad['enabled']=false;
no95(fn()=>KiComEngramActiveSchemaUpgrade::applyAfterVerifiedPasskey($web,$bad),'inactive/spoofed runtime denied');
yes95((new PDO('sqlite:'.$data.'/engrams.sqlite'))->query('PRAGMA quick_check')->fetchColumn()==='ok','SQLite integrity after repeat');
// SAME column names are not enough: corrupted preexisting tables MUST retain
// both original single-use refresh and bearer-bound consent UNIQUE constraints.
$o=new PDO('sqlite:'.$data.'/mirage-oauth.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$o->exec('DROP TABLE mirage_oauth_refresh_tokens');
$o->exec('CREATE TABLE mirage_oauth_refresh_tokens(
 refresh_hash TEXT PRIMARY KEY,client_id TEXT NOT NULL,connector_id TEXT NOT NULL,
 host_evidence_id TEXT NOT NULL,resource TEXT NOT NULL,scope TEXT NOT NULL,
 owner_binding TEXT NOT NULL,credential_fingerprint TEXT NOT NULL,
 issued_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,
 consumed INTEGER NOT NULL DEFAULT 0,revoked INTEGER NOT NULL DEFAULT 0,
 parent_access_hash TEXT NOT NULL)');
$o=null;
no95(fn()=>KiComEngramActiveSchemaUpgrade::applyAfterVerifiedPasskey($web,$runtime),
 'existing refresh table with identical columns but MISSING parent-access uniqueness rejected');
$o=new PDO('sqlite:'.$data.'/mirage-oauth.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$o->exec('DROP TABLE mirage_oauth_refresh_tokens');
KiComEngramOAuthTransactions::prepareRefreshSchema($o);
$o->exec('DROP TABLE mirage_oauth_write_consents');
$o->exec('CREATE TABLE mirage_oauth_write_consents(
 consent_ref TEXT PRIMARY KEY,owner TEXT NOT NULL,namespace TEXT NOT NULL,
 client_id TEXT NOT NULL,connector_id TEXT NOT NULL,owner_binding TEXT NOT NULL,
 credential_fingerprint TEXT NOT NULL,source_kind TEXT NOT NULL,
 approved_at INTEGER NOT NULL,revoked_at INTEGER,token_hash TEXT)');
$o=null;
no95(fn()=>KiComEngramActiveSchemaUpgrade::applyAfterVerifiedPasskey($web,$runtime),
 'existing consent table with identical columns but MISSING bearer uniqueness rejected');
$o=new PDO('sqlite:'.$data.'/mirage-oauth.sqlite');
yes95($o->query("SELECT scope FROM mirage_oauth_tokens WHERE token_hash='old-read-1'")->fetchColumn()==='engram.read',
 'rejected malformed additions do not rewrite original read token');
yes95(file_get_contents($key)===$stableKey,'rejected malformed additions do not rotate original signing key');
$o=null;
echo "DEV95_ACTIVE_SCHEMA_ASSERTIONS=$n\n";
