<?php
declare(strict_types=1);

const KICOM_VERSION = '0.9.25';
const KICOM_GATEWAY = 'KiCom';
const KICOM_MAX_ECHO_LEN = 200;
const KICOM_MAX_PROPOSAL_BYTES = 4096;
const KICOM_PROPOSAL_TTL = 1800;
const KICOM_MAX_PENDING = 20;
const KICOM_MAX_HISTORY_PER_FILE = 50;
const KICOM_MAX_READ_BYTES = 32768;
const KICOM_MAX_MEMORY_PROPOSAL_BYTES = 32768;
const KICOM_MAX_POST_BYTES = 4194304;
const KICOM_MAX_MEMORY_ARCHIVE_SNAPSHOT_BYTES = 262144;
const KICOM_INTENT_TTL = 120;
const KICOM_MAX_INTENTS = 20;
const KICOM_MAX_DEPLOY_HISTORY = 100;
const KICOM_HEALTH_TIMEOUT = 5;
const KICOM_MAX_DEPLOY_BACKUP_BYTES = 262144;
const KICOM_MAX_PACKAGE_FILES = 20;
const KICOM_MAX_PACKAGE_BYTES = 1048576;
const KICOM_MAX_PACKAGE_BACKUP_BYTES = 2097152;
const KICOM_MAX_SELF_UPDATE_ZIP_BYTES = 8388608;
const KICOM_MAX_SELF_UPDATE_FILES = 240;
const KICOM_MAX_SELF_UPDATE_UNCOMPRESSED_BYTES = 16777216;
const KICOM_MAX_SELF_UPDATE_BACKUP_BYTES = 16777216;
const KICOM_MAX_SELF_UPDATE_HISTORY = 20;
const KICOM_UPDATE_FEED_MAX_BYTES = 262144;
const KICOM_UPDATE_CHANNEL_MIN_INTERVAL = 60;
const KICOM_UPDATE_PUSH_MAX_ATTEMPTS_PER_MINUTE = 12;

function kicomBaseDir(): string { return __DIR__; }
function kicomVarDir(): string { return kicomBaseDir() . '/var'; }
function kicomPendingDir(): string { return kicomVarDir() . '/pending'; }
function kicomHistoryDir(): string { return kicomVarDir() . '/history'; }
function kicomTempDir(): string { return kicomVarDir() . '/tmp'; }
function kicomStageDir(): string { return kicomBaseDir() . '/stage'; }
function kicomMemorySeedDir(): string { return kicomBaseDir() . '/memory'; }
function kicomMemoryStateDir(): string { return kicomVarDir() . '/project_memory'; }
function kicomMemoryHistoryDir(): string { return kicomVarDir() . '/memory_history'; }
function kicomIntentDir(): string { return kicomVarDir() . '/intents'; }
function kicomDeployBackupDir(): string { return kicomVarDir() . '/deploy_backups'; }
function kicomDeployHistoryDir(): string { return kicomVarDir() . '/deployments'; }
function kicomDeployTargetsFile(): string { return kicomVarDir() . '/deploy_targets.php'; }
function kicomMemoryDir(): string { return kicomMemoryStateDir(); }
function kicomConfigFile(): string { return kicomVarDir() . '/config.php'; }
function kicomSelfUpdateDir(): string { return kicomVarDir() . '/self_update'; }
function kicomSelfUpdatePackagesDir(): string { return kicomSelfUpdateDir() . '/packages'; }
function kicomSelfUpdateWorkDir(): string { return kicomSelfUpdateDir() . '/work'; }
function kicomSelfUpdateHistoryDir(): string { return kicomSelfUpdateDir() . '/history'; }
function kicomSelfUpdateSupersededDir(): string { return kicomSelfUpdateDir() . '/superseded'; }
function kicomSelfUpdatePendingFile(): string { return kicomSelfUpdateDir() . '/pending.json'; }
function kicomUpdateChannelsFile(): string { return kicomSelfUpdateDir() . '/channels.json'; }
function kicomUpdateChannelStateFile(): string { return kicomSelfUpdateDir() . '/channel_state.json'; }
function kicomUpdatePushRateFile(): string { return kicomSelfUpdateDir() . '/push_rate.json'; }

if(!defined('KICOM_GUARDIAN_EMBEDDED')) define('KICOM_GUARDIAN_EMBEDDED',true);
require_once __DIR__ . '/guardian.php';
require_once __DIR__ . '/living.php';
kicomLoadTrustedModules();

function kicomTextLen(string $value): int {
    return function_exists('mb_strlen') ? (int)mb_strlen($value, 'UTF-8') : strlen($value);
}
function kicomTextSubstr(string $value, int $start, int $length): string {
    return function_exists('mb_substr') ? (string)mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
}
function kicomRequestId(): string {
    try { return strtoupper(bin2hex(random_bytes(4))); }
    catch (Throwable $e) { return strtoupper(substr(hash('sha256', uniqid('', true)), 0, 8)); }
}
function kicomSafeId(string $id): ?string {
    $id = strtolower(trim($id));
    return preg_match('/^[a-f0-9]{8,80}$/', $id) ? $id : null;
}
function kicomDenyRules(): string {
    return "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n";
}

/* KiCom SQLite operational memory 1 */
function kicomSqliteDir(): string { return kicomVarDir().'/db'; }
function kicomSqliteFile(): string { return kicomSqliteDir().'/kicom.sqlite'; }
function kicomSqliteSnapshotDir(): string { return kicomSqliteDir().'/snapshots'; }
function kicomSqliteQuarantineDir(): string { return kicomSqliteDir().'/quarantine'; }
function kicomSqliteShadowDir(): string { return kicomSqliteDir().'/shadow'; }
function kicomSqliteMaintenanceFile(): string { return kicomSqliteDir().'/maintenance.json'; }
function kicomSqliteSupport(): array {$pdo=extension_loaded('pdo_sqlite')&&class_exists('PDO')&&in_array('sqlite',PDO::getAvailableDrivers(),true);return ['pdo_sqlite'=>$pdo,'sqlite3'=>extension_loaded('sqlite3')&&class_exists('SQLite3'),'available'=>$pdo];}
function kicomSqliteEnsureDirs(): bool {foreach([kicomSqliteDir(),kicomSqliteSnapshotDir(),kicomSqliteQuarantineDir(),kicomSqliteShadowDir()] as $d){if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;@chmod($d,0700);$deny=$d.'/.htaccess';if(!is_file($deny)){@file_put_contents($deny,kicomDenyRules(),LOCK_EX);@chmod($deny,0600);}}return true;}
function kicomSqliteMigrate(PDO $db): bool {
 try{$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations(version INTEGER PRIMARY KEY,name TEXT NOT NULL,checksum TEXT NOT NULL,applied_at TEXT NOT NULL)');
 $m=[1=>['operational_memory_v1',[
 'CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY,value TEXT NOT NULL,updated_at TEXT NOT NULL)',
 'CREATE TABLE IF NOT EXISTS kv_state(namespace TEXT NOT NULL,state_key TEXT NOT NULL,json TEXT NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(namespace,state_key))',
 'CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY AUTOINCREMENT,stream TEXT NOT NULL,at TEXT NOT NULL,type TEXT NOT NULL,severity TEXT NOT NULL,payload_json TEXT NOT NULL,sha256 TEXT NOT NULL UNIQUE,created_at TEXT NOT NULL)',
 'CREATE INDEX IF NOT EXISTS idx_events_stream_at ON events(stream,at DESC)',
 'CREATE TABLE IF NOT EXISTS observations(id INTEGER PRIMARY KEY AUTOINCREMENT,subject TEXT NOT NULL,state TEXT NOT NULL,evidence_json TEXT NOT NULL,confidence REAL NOT NULL DEFAULT 0,source TEXT NOT NULL,observed_at TEXT NOT NULL,stale_after TEXT)',
 'CREATE INDEX IF NOT EXISTS idx_observations_subject_at ON observations(subject,observed_at DESC)',
 'CREATE TABLE IF NOT EXISTS capabilities(name TEXT PRIMARY KEY,state TEXT NOT NULL,confidence REAL NOT NULL DEFAULT 0,evidence_count INTEGER NOT NULL DEFAULT 0,last_verified_at TEXT,details_json TEXT NOT NULL)',
 'CREATE TABLE IF NOT EXISTS actions(id INTEGER PRIMARY KEY AUTOINCREMENT,action TEXT NOT NULL,target TEXT,result_json TEXT NOT NULL,success INTEGER NOT NULL,started_at TEXT NOT NULL,finished_at TEXT NOT NULL)',
 'CREATE TABLE IF NOT EXISTS peers(peer_id TEXT PRIMARY KEY,state TEXT NOT NULL,last_seen_at TEXT,metadata_json TEXT NOT NULL)',
 'CREATE TABLE IF NOT EXISTS jobs(id TEXT PRIMARY KEY,kind TEXT NOT NULL,status TEXT NOT NULL,payload_json TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL)',
 'CREATE TABLE IF NOT EXISTS artifacts(id TEXT PRIMARY KEY,sha256 TEXT NOT NULL UNIQUE,filename TEXT NOT NULL,mime_type TEXT,size INTEGER NOT NULL,path TEXT NOT NULL,source TEXT NOT NULL,trust_state TEXT NOT NULL,created_at TEXT NOT NULL)',
 'CREATE TABLE IF NOT EXISTS health_history(id INTEGER PRIMARY KEY AUTOINCREMENT,at TEXT NOT NULL,status TEXT NOT NULL,quick_check TEXT NOT NULL,integrity_check TEXT,schema_version INTEGER NOT NULL,detail_json TEXT NOT NULL)',
 'CREATE TABLE IF NOT EXISTS snapshots(id TEXT PRIMARY KEY,created_at TEXT NOT NULL,reason TEXT NOT NULL,file_name TEXT NOT NULL,sha256 TEXT NOT NULL,bytes INTEGER NOT NULL,health TEXT NOT NULL)',
 'CREATE TABLE IF NOT EXISTS evolution_experiments(id TEXT PRIMARY KEY,kind TEXT NOT NULL,spec_json TEXT NOT NULL,status TEXT NOT NULL,fitness_before REAL,fitness_after REAL,health TEXT NOT NULL,created_at TEXT NOT NULL,promoted_at TEXT,artifact_file TEXT)'
 ]]];
 foreach($m as $ver=>$x){$q=$db->prepare('SELECT 1 FROM schema_migrations WHERE version=?');$q->execute([$ver]);if($q->fetchColumn())continue;$sum=hash('sha256',json_encode($x,JSON_UNESCAPED_SLASHES)?:'');$db->beginTransaction();try{foreach($x[1] as $sql)$db->exec($sql);$q=$db->prepare('INSERT INTO schema_migrations(version,name,checksum,applied_at) VALUES(?,?,?,?)');$q->execute([$ver,$x[0],$sum,gmdate('c')]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();return false;}}
 return true;}catch(Throwable $e){return false;}
}
function kicomSqliteOpenFile(string $file,bool $migrate=true,bool $wal=true): ?PDO {if(empty(kicomSqliteSupport()['available'])||!kicomSqliteEnsureDirs())return null;try{$db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec('PRAGMA foreign_keys=ON');$db->exec('PRAGMA busy_timeout=5000');$db->exec('PRAGMA synchronous=NORMAL');if($wal&&strtolower((string)$db->query('PRAGMA journal_mode=WAL')->fetchColumn())!=='wal')return null;if($migrate&&!kicomSqliteMigrate($db))return null;return $db;}catch(Throwable $e){return null;}}
function kicomSqliteDb(bool $reset=false): ?PDO {static $db=null;if($reset)$db=null;if($db instanceof PDO)return $db;$db=kicomSqliteOpenFile(kicomSqliteFile(),true,true);if($db)@chmod(kicomSqliteFile(),0600);return $db;}
function kicomSqliteMirrorJson(string $file,array $row): bool {$d=dirname($file);if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;$j=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($j===false)return false;$t=$file.'.tmp-'.substr(hash('sha256',uniqid('',true)),0,10);if(@file_put_contents($t,$j."\n",LOCK_EX)===false)return false;@chmod($t,0600);if(!@rename($t,$file)){@unlink($t);return false;}@chmod($file,0600);return true;}
function kicomSqliteManagedFileKey(string $file): ?array {$p=[];if(function_exists('kicomEvolutionStateFile'))$p[]=[kicomEvolutionStateFile(),'evolution','state'];if(function_exists('kicomEvolutionAutonomyFile'))$p[]=[kicomEvolutionAutonomyFile(),'evolution','autonomy'];if(function_exists('kicomEvolutionGoalsFile'))$p[]=[kicomEvolutionGoalsFile(),'evolution','goals'];if(function_exists('kicomGoalRegistryFile'))$p[]=[kicomGoalRegistryFile(),'goals','registry'];if(function_exists('kicomTransportProfilesFile'))$p[]=[kicomTransportProfilesFile(),'transport','profiles'];foreach($p as $x)if($file===$x[0])return ['namespace'=>$x[1],'key'=>$x[2]];return null;}
function kicomSqliteKvRead(string $ns,string $key,?string $fallback=null,array $default=[]): array {$db=kicomSqliteDb();if($db){try{$q=$db->prepare('SELECT json FROM kv_state WHERE namespace=? AND state_key=?');$q->execute([$ns,$key]);$j=$q->fetchColumn();if(is_string($j)){$r=json_decode($j,true);if(is_array($r))return $r;}}catch(Throwable $e){}}if($fallback!==null&&is_file($fallback)){$r=json_decode((string)@file_get_contents($fallback),true);if(is_array($r)){kicomSqliteKvWrite($ns,$key,$r,$fallback);return $r;}}return $default;}
function kicomSqliteKvWrite(string $ns,string $key,array $row,?string $mirror=null): bool {$j=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($j===false)return false;$ok=false;$db=kicomSqliteDb();if($db){try{$q=$db->prepare('INSERT INTO kv_state(namespace,state_key,json,updated_at) VALUES(?,?,?,?) ON CONFLICT(namespace,state_key) DO UPDATE SET json=excluded.json,updated_at=excluded.updated_at');$ok=$q->execute([$ns,$key,$j,gmdate('c')]);}catch(Throwable $e){}}$m=$mirror!==null?kicomSqliteMirrorJson($mirror,$row):false;return $ok||$m;}
function kicomSqliteEventAppend(string $stream,array $row,?string $mirror=null): bool {if(!isset($row['at']))$row['at']=gmdate('c');$j=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($j===false)return false;$type=(string)($row['type']??'event');$sev=(string)($row['severity']??'info');$sum=hash('sha256',$stream."\n".$j);$ok=false;$db=kicomSqliteDb();if($db){try{$q=$db->prepare('INSERT OR IGNORE INTO events(stream,at,type,severity,payload_json,sha256,created_at) VALUES(?,?,?,?,?,?,?)');$ok=$q->execute([$stream,(string)$row['at'],$type,$sev,$j,$sum,gmdate('c')]);}catch(Throwable $e){}}$m=false;if($mirror!==null){$d=dirname($mirror);if(is_dir($d)||@mkdir($d,0700,true)){$m=@file_put_contents($mirror,$j."\n",FILE_APPEND|LOCK_EX)!==false;if($m)@chmod($mirror,0600);}}return $ok||$m;}
function kicomSqliteEvents(string $stream,int $limit=50): array {$limit=max(1,min(2000,$limit));$db=kicomSqliteDb();if(!$db)return [];$a=[];try{$q=$db->prepare('SELECT payload_json FROM events WHERE stream=? ORDER BY id DESC LIMIT ?');$q->bindValue(1,$stream,PDO::PARAM_STR);$q->bindValue(2,$limit,PDO::PARAM_INT);$q->execute();foreach($q->fetchAll() as $x){$r=json_decode((string)$x['payload_json'],true);if(is_array($r))$a[]=$r;}}catch(Throwable $e){}return $a;}
function kicomSqliteMetaGet(string $key,string $default=''): string {$db=kicomSqliteDb();if(!$db)return $default;try{$q=$db->prepare('SELECT value FROM meta WHERE key=?');$q->execute([$key]);$v=$q->fetchColumn();return is_string($v)?$v:$default;}catch(Throwable $e){return $default;}}
function kicomSqliteMetaSet(string $key,string $value): bool {$db=kicomSqliteDb();if(!$db)return false;try{$q=$db->prepare('INSERT INTO meta(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at');return $q->execute([$key,$value,gmdate('c')]);}catch(Throwable $e){return false;}}
function kicomSqliteImportJsonl(string $stream,string $file): int {$n=0;if(!is_file($file))return 0;foreach(file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $l){$r=json_decode($l,true);if(is_array($r)&&kicomSqliteEventAppend($stream,$r,null))$n++;}return $n;}
function kicomSqliteImportStateFile(string $ns,string $key,string $file,array $default=[]): int {$r=is_file($file)?json_decode((string)@file_get_contents($file),true):null;if(!is_array($r))$r=$default;return kicomSqliteKvWrite($ns,$key,$r,null)?1:0;}
function kicomSqliteImportLegacy(): array {$n=0;if(function_exists('kicomSlackStateFile'))$n+=kicomSqliteImportStateFile('slack','state',kicomSlackStateFile(),['seen'=>[]]);if(function_exists('kicomSlackRateFile'))$n+=kicomSqliteImportStateFile('slack','rate',kicomSlackRateFile(),['schema'=>1,'events'=>[]]);if(function_exists('kicomMailInboxRoot'))$n+=kicomSqliteImportStateFile('mail','inbox_state',kicomMailInboxRoot().'/state.json',['seen'=>[],'last_poll_at'=>null,'uidvalidity'=>null]);if(function_exists('kicomMailOutboundRateFile'))$n+=kicomSqliteImportStateFile('mail','outbound_rate',kicomMailOutboundRateFile(),['events'=>[]]);if(function_exists('kicomEvolutionStateFile'))$n+=kicomSqliteImportStateFile('evolution','state',kicomEvolutionStateFile(),[]);if(function_exists('kicomEvolutionAutonomyFile'))$n+=kicomSqliteImportStateFile('evolution','autonomy',kicomEvolutionAutonomyFile(),[]);if(function_exists('kicomEvolutionGoalsFile'))$n+=kicomSqliteImportStateFile('evolution','goals',kicomEvolutionGoalsFile(),['schema'=>1,'goals'=>[]]);if(function_exists('kicomGoalRegistryFile'))$n+=kicomSqliteImportStateFile('goals','registry',kicomGoalRegistryFile(),['schema'=>1,'goals'=>[]]);if(function_exists('kicomTransportProfilesFile'))$n+=kicomSqliteImportStateFile('transport','profiles',kicomTransportProfilesFile(),['schema'=>1,'profiles'=>[]]);if(function_exists('kicomLivingEventsFile'))$n+=kicomSqliteImportJsonl('living',kicomLivingEventsFile());if(function_exists('kicomSlackRoot'))foreach(glob(kicomSlackRoot().'/events-*.jsonl')?:[] as $f)$n+=kicomSqliteImportJsonl('slack',$f);if(function_exists('kicomMailInboxRoot'))foreach(glob(kicomMailInboxRoot().'/events-*.jsonl')?:[] as $f)$n+=kicomSqliteImportJsonl('mail_inbox',$f);if(function_exists('kicomGoalEventsFile'))$n+=kicomSqliteImportJsonl('goals',kicomGoalEventsFile());kicomSqliteMetaSet('legacy_import_complete','1');kicomSqliteMetaSet('legacy_import_at',gmdate('c'));return ['ok'=>true,'items'=>$n];}
function kicomSqliteVerifyFile(string $file,bool $full=false): array {if(!is_file($file)||!class_exists('SQLite3'))return ['ok'=>false,'code'=>'SQLITE_FILE_UNAVAILABLE'];try{$db=new SQLite3($file,SQLITE3_OPEN_READONLY);$q=(string)$db->querySingle('PRAGMA quick_check');$i=$full?(string)$db->querySingle('PRAGMA integrity_check'):null;$v=(string)$db->querySingle('SELECT sqlite_version()');$db->close();return ['ok'=>strtolower($q)==='ok'&&(!$full||strtolower((string)$i)==='ok'),'quick_check'=>$q,'integrity_check'=>$i,'sqlite_version'=>$v];}catch(Throwable $e){return ['ok'=>false,'code'=>'SQLITE_VERIFY_EXCEPTION'];}}
function kicomSqliteHealth(bool $full=false): array {$s=kicomSqliteSupport();$db=kicomSqliteDb();if(!$db)return ['ok'=>false,'available'=>!empty($s['available']),'code'=>'SQLITE_OPEN_FAILED'];try{$quick=strtolower((string)$db->query('PRAGMA quick_check')->fetchColumn());$integrity=$full?strtolower((string)$db->query('PRAGMA integrity_check')->fetchColumn()):null;$journal=strtolower((string)$db->query('PRAGMA journal_mode')->fetchColumn());$ver=(string)$db->query('SELECT sqlite_version()')->fetchColumn();$schema=(int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn();$events=(int)$db->query('SELECT COUNT(*) FROM events')->fetchColumn();$kv=(int)$db->query('SELECT COUNT(*) FROM kv_state')->fetchColumn();$ok=$quick==='ok'&&(!$full||$integrity==='ok')&&$journal==='wal';$detail=['sqlite_version'=>$ver,'journal_mode'=>$journal,'events'=>$events,'kv_rows'=>$kv,'bytes'=>is_file(kicomSqliteFile())?(int)filesize(kicomSqliteFile()):0];try{$q=$db->prepare('INSERT INTO health_history(at,status,quick_check,integrity_check,schema_version,detail_json) VALUES(?,?,?,?,?,?)');$q->execute([gmdate('c'),$ok?'ok':'error',$quick,$integrity,$schema,json_encode($detail,JSON_UNESCAPED_SLASHES)?:'{}']);}catch(Throwable $e){}return ['ok'=>$ok,'available'=>true,'quick_check'=>$quick,'integrity_check'=>$integrity,'journal_mode'=>$journal,'sqlite_version'=>$ver,'schema_version'=>$schema,'events'=>$events,'kv_rows'=>$kv,'bytes'=>$detail['bytes']];}catch(Throwable $e){return ['ok'=>false,'available'=>true,'code'=>'SQLITE_HEALTH_EXCEPTION'];}}
function kicomSqliteBackupFile(string $dest): bool {if(!class_exists('SQLite3')||!is_file(kicomSqliteFile()))return false;try{$src=new SQLite3(kicomSqliteFile(),SQLITE3_OPEN_READONLY);$dst=new SQLite3($dest,SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE);$ok=$src->backup($dst);$src->close();$dst->close();if($ok)@chmod($dest,0600);return (bool)$ok;}catch(Throwable $e){return false;}}
function kicomSqliteSnapshotMetaFiles(): array {$a=glob(kicomSqliteSnapshotDir().'/snapshot-*.json')?:[];rsort($a,SORT_STRING);return $a;}
function kicomSqliteSnapshot(string $reason='automatic'): array {$db=kicomSqliteDb();if(!$db||!kicomSqliteEnsureDirs())return ['ok'=>false,'code'=>'SQLITE_OPEN_FAILED'];try{$db->exec('PRAGMA wal_checkpoint(FULL)');}catch(Throwable $e){}$id=gmdate('YmdHis').'-'.substr(hash('sha256',uniqid('',true)),0,10);$name='snapshot-'.$id.'.sqlite';$file=kicomSqliteSnapshotDir().'/'.$name;if(!kicomSqliteBackupFile($file))return ['ok'=>false,'code'=>'SQLITE_SNAPSHOT_COPY_FAILED'];if(empty(kicomSqliteVerifyFile($file,true)['ok']))return ['ok'=>false,'code'=>'SQLITE_SNAPSHOT_VERIFY_FAILED'];$sum=hash_file('sha256',$file)?:'';$bytes=(int)filesize($file);$m=['id'=>$id,'created_at'=>gmdate('c'),'reason'=>substr($reason,0,120),'file_name'=>$name,'sha256'=>$sum,'bytes'=>$bytes,'health'=>'ok'];if(!kicomSqliteMirrorJson(kicomSqliteSnapshotDir().'/snapshot-'.$id.'.json',$m))return ['ok'=>false,'code'=>'SQLITE_SNAPSHOT_META_FAILED'];try{$q=$db->prepare('INSERT OR REPLACE INTO snapshots(id,created_at,reason,file_name,sha256,bytes,health) VALUES(?,?,?,?,?,?,?)');$q->execute([$id,$m['created_at'],$m['reason'],$name,$sum,$bytes,'ok']);}catch(Throwable $e){}return ['ok'=>true,'code'=>'SQLITE_SNAPSHOT_CREATED','id'=>$id,'sha256'=>$sum,'bytes'=>$bytes];}
function kicomSqliteLatestSnapshot(): ?array {foreach(kicomSqliteSnapshotMetaFiles() as $mf){$m=json_decode((string)@file_get_contents($mf),true);if(!is_array($m))continue;$f=kicomSqliteSnapshotDir().'/'.basename((string)($m['file_name']??''));if(!is_file($f))continue;if(!hash_equals((string)($m['sha256']??''),hash_file('sha256',$f)?:''))continue;if(empty(kicomSqliteVerifyFile($f,true)['ok']))continue;$m['full']=$f;return $m;}return null;}
function kicomSqliteRecover(string $reason='health_failure'): array {if(!kicomSqliteEnsureDirs())return ['ok'=>false,'code'=>'SQLITE_STORAGE_FAILED'];$lock=@fopen(kicomSqliteDir().'/repair.lock','c+');if($lock===false||!@flock($lock,LOCK_EX|LOCK_NB))return ['ok'=>false,'code'=>'SQLITE_REPAIR_BUSY'];try{$snap=kicomSqliteLatestSnapshot();kicomSqliteDb(true);$stamp=gmdate('YmdHis').'-'.substr(hash('sha256',uniqid('',true)),0,8);foreach(['','-wal','-shm'] as $x){$s=kicomSqliteFile().$x;if(is_file($s))@rename($s,kicomSqliteQuarantineDir().'/quarantine-'.$stamp.'.sqlite'.$x);}$restored=false;if(is_array($snap)&&is_file((string)$snap['full'])){$restored=@copy((string)$snap['full'],kicomSqliteFile());if($restored)@chmod(kicomSqliteFile(),0600);}$db=kicomSqliteDb(true);if(!$db){if(is_file(kicomSqliteFile()))@rename(kicomSqliteFile(),kicomSqliteQuarantineDir().'/bad-restore-'.$stamp.'.sqlite');$db=kicomSqliteDb(true);}if(!$db)return ['ok'=>false,'code'=>'SQLITE_REBUILD_OPEN_FAILED'];$imp=kicomSqliteImportLegacy();$hl=kicomSqliteHealth(true);if(empty($hl['ok']))return ['ok'=>false,'code'=>'SQLITE_REBUILD_VERIFY_FAILED'];kicomSqliteMetaSet('last_recovery',gmdate('c').'|'.$reason);return ['ok'=>true,'code'=>'SQLITE_RECOVERED','snapshot_used'=>$restored,'imported'=>(int)($imp['items']??0)];}finally{@flock($lock,LOCK_UN);@fclose($lock);}}
function kicomSqliteBenchmarkEvents(PDO $db,int $loops=30): float {try{$s=$db->query("SELECT stream,type FROM events GROUP BY stream,type ORDER BY COUNT(*) DESC LIMIT 1")->fetch();if(!is_array($s))return 0.0;$q=$db->prepare('SELECT id FROM events WHERE stream=? AND type=? ORDER BY at DESC LIMIT 20');$t=microtime(true);for($i=0;$i<$loops;$i++){$q->execute([(string)$s['stream'],(string)$s['type']]);$q->fetchAll();}return (microtime(true)-$t)*1000;}catch(Throwable $e){return 999999.0;}}
function kicomSqliteEvolutionStatus(): array {$db=kicomSqliteDb();if(!$db)return ['ok'=>false,'experiments'=>[]];try{$r=$db->query('SELECT id,kind,status,fitness_before,fitness_after,health,created_at,promoted_at,artifact_file FROM evolution_experiments ORDER BY created_at DESC LIMIT 20')->fetchAll();return ['ok'=>true,'experiments'=>is_array($r)?$r:[]];}catch(Throwable $e){return ['ok'=>false,'experiments'=>[]];}}
function kicomSqliteEvolutionTick(bool $force=false): array {$db=kicomSqliteDb();if(!$db)return ['ok'=>false,'code'=>'SQLITE_OPEN_FAILED'];try{$count=(int)$db->query('SELECT COUNT(*) FROM events')->fetchColumn();if($count<100)return ['ok'=>true,'code'=>'SQLITE_EVOLUTION_NO_EVIDENCE','events'=>$count];$idx='idx_events_stream_type_at';if((bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='index' AND name='idx_events_stream_type_at'")->fetchColumn())return ['ok'=>true,'code'=>'SQLITE_EVOLUTION_STABLE','events'=>$count];$id='idx-events-stream-type-at-v1';$q=$db->prepare('SELECT status FROM evolution_experiments WHERE id=?');$q->execute([$id]);if($q->fetchColumn()&&!$force)return ['ok'=>true,'code'=>'SQLITE_EVOLUTION_ALREADY_TESTED'];$artifact='shadow-'.$id.'-'.gmdate('YmdHis').'.sqlite';$shadow=kicomSqliteShadowDir().'/'.$artifact;try{$db->exec('PRAGMA wal_checkpoint(FULL)');}catch(Throwable $e){}if(!kicomSqliteBackupFile($shadow))return ['ok'=>false,'code'=>'SQLITE_EVOLUTION_SHADOW_FAILED'];$sd=kicomSqliteOpenFile($shadow,false,true);if(!$sd)return ['ok'=>false,'code'=>'SQLITE_EVOLUTION_SHADOW_OPEN_FAILED'];$before=kicomSqliteBenchmarkEvents($sd);$sd->exec('CREATE INDEX IF NOT EXISTS '.$idx.' ON events(stream,type,at DESC)');$quick=strtolower((string)$sd->query('PRAGMA quick_check')->fetchColumn());$after=kicomSqliteBenchmarkEvents($sd);$sd=null;$promote=$quick==='ok'&&$after<=max($before*1.75,$before+1.0);$pre=null;if($promote)$pre=kicomSqliteSnapshot('pre-evolution-'.$id);if($promote){$db->beginTransaction();try{$db->exec('CREATE INDEX IF NOT EXISTS '.$idx.' ON events(stream,type,at DESC)');if(strtolower((string)$db->query('PRAGMA quick_check')->fetchColumn())!=='ok')throw new RuntimeException('health');$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();$promote=false;}}$status=$promote?'promoted':'rejected';$spec=json_encode(['kind'=>'create_index','table'=>'events','columns'=>['stream','type','at DESC'],'evidence_events'=>$count],JSON_UNESCAPED_SLASHES)?:'{}';$q=$db->prepare('INSERT INTO evolution_experiments(id,kind,spec_json,status,fitness_before,fitness_after,health,created_at,promoted_at,artifact_file) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET status=excluded.status,fitness_before=excluded.fitness_before,fitness_after=excluded.fitness_after,health=excluded.health,promoted_at=excluded.promoted_at,artifact_file=excluded.artifact_file');$q->execute([$id,'create_index',$spec,$status,$before,$after,$quick,gmdate('c'),$promote?gmdate('c'):null,$artifact]);if($promote)kicomSqliteSnapshot('post-evolution-'.$id);return ['ok'=>true,'code'=>$promote?'SQLITE_EVOLUTION_PROMOTED':'SQLITE_EVOLUTION_REJECTED','candidate'=>$id,'before_ms'=>$before,'after_ms'=>$after,'health'=>$quick,'pre_snapshot'=>is_array($pre)?($pre['id']??''):null];}catch(Throwable $e){return ['ok'=>false,'code'=>'SQLITE_EVOLUTION_EXCEPTION'];}}
function kicomSqliteStatus(): array {$s=kicomSqliteSupport();$hl=kicomSqliteHealth(false);$last=kicomSqliteLatestSnapshot();$ev=kicomSqliteEvolutionStatus();return ['ok'=>!empty($hl['ok']),'primary'=>true,'compatibility_mirror'=>true,'support'=>$s,'health'=>$hl,'last_snapshot'=>is_array($last)?['id'=>$last['id']??'','created_at'=>$last['created_at']??'','sha256'=>$last['sha256']??'','bytes'=>$last['bytes']??0]:null,'evolution_experiments'=>count($ev['experiments']??[])];}
function kicomSqliteMaintenanceTick(bool $force=false): array {static $running=false;if($running)return ['ok'=>true,'code'=>'SQLITE_MAINTENANCE_REENTRANT'];$running=true;try{if(!kicomSqliteEnsureDirs())return ['ok'=>false,'code'=>'SQLITE_STORAGE_FAILED'];$mf=kicomSqliteMaintenanceFile();if(!$force&&is_file($mf)&&(time()-(int)filemtime($mf))<300)return ['ok'=>true,'code'=>'SQLITE_MAINTENANCE_THROTTLED'];$hl=kicomSqliteHealth(false);if(empty($hl['ok'])){$r=kicomSqliteRecover('automatic-health');if(empty($r['ok']))return $r;$hl=kicomSqliteHealth(true);}if(kicomSqliteMetaGet('legacy_import_complete','')!=='1'){kicomSqliteImportLegacy();$hl=kicomSqliteHealth(true);}$last=kicomSqliteLatestSnapshot();$at=is_array($last)?(int)strtotime((string)($last['created_at']??'')):0;if($at<=0||time()-$at>=86400)kicomSqliteSnapshot('automatic-daily');$evAt=(int)kicomSqliteMetaGet('last_evolution_tick','0');if($force||time()-$evAt>=3600){kicomSqliteEvolutionTick(false);kicomSqliteMetaSet('last_evolution_tick',(string)time());}kicomSqliteMirrorJson($mf,['at'=>gmdate('c'),'ok'=>!empty($hl['ok']),'schema_version'=>$hl['schema_version']??0,'events'=>$hl['events']??0,'kv_rows'=>$hl['kv_rows']??0]);@touch($mf);return ['ok'=>!empty($hl['ok']),'code'=>!empty($hl['ok'])?'SQLITE_MAINTENANCE_OK':'SQLITE_MAINTENANCE_FAILED']+$hl;}finally{$running=false;}}

function kicomEnsureStorage(): bool {
    foreach ([kicomVarDir(), kicomPendingDir(), kicomHistoryDir(), kicomTempDir(), kicomStageDir(), kicomMemoryStateDir(), kicomMemoryHistoryDir(), kicomIntentDir(), kicomDeployBackupDir(), kicomDeployHistoryDir(), kicomSelfUpdateDir(), kicomSelfUpdatePackagesDir(), kicomSelfUpdateWorkDir(), kicomSelfUpdateHistoryDir(), kicomSelfUpdateSupersededDir()] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;
    }
    foreach ([kicomVarDir(), kicomStageDir()] as $dir) {
        $file = $dir . '/.htaccess';
        if (!is_file($file)) @file_put_contents($file, kicomDenyRules(), LOCK_EX);
    }
    /* Seed canonical runtime memory only once. Future code-package uploads must not overwrite it. */
    $resources=kicomMemoryResources();
    $stateFile=kicomMemoryStateDir().'/'.$resources['PROJECT_STATE'];
    if (is_file($stateFile)) {
        /* Never silently mix a persistent memory set with seed files from a later package. */
        foreach ($resources as $filename) if (!is_file(kicomMemoryStateDir().'/'.$filename)) return false;
        return kicomLivingEnsure();
    }
    $seedState=kicomMemorySeedDir().'/'.$resources['PROJECT_STATE'];
    $seedStateRaw=@file_get_contents($seedState);
    if ($seedStateRaw===false || !str_contains((string)$seedStateRaw,'VERSION "'.KICOM_VERSION.'"')) return false;
    foreach ($resources as $filename) {
        $seed = kicomMemorySeedDir() . '/' . $filename;
        if (!is_file($seed)) return false;
        $raw = @file_get_contents($seed);
        $target = kicomMemoryStateDir() . '/' . $filename;
        if ($raw === false || @file_put_contents($target, $raw, LOCK_EX) === false) return false;
        @chmod($target, 0600);
    }
    return kicomLivingEnsure();
}
function kicomCleanupPending(): void {
    $now = time();
    foreach (glob(kicomPendingDir() . '/*.json') ?: [] as $file) {
        if (is_file($file) && ($now - (int)@filemtime($file)) > KICOM_PROPOSAL_TTL) @unlink($file);
    }
}

function kicomCleanupIntents(): void {
    $now=time();
    foreach(glob(kicomIntentDir().'/*.json')?:[] as $file){
        $row=json_decode((string)@file_get_contents($file),true);
        $expired=!is_array($row) || (int)($row['expires_at']??0)<$now;
        if($expired) @unlink($file);
    }
}
function kicomCreateIntent(string $operation,string $target): array {
    if(!kicomEnsureStorage()) return ['ok'=>false,'code'=>'STORAGE_UNAVAILABLE'];
    kicomCleanupIntents();
    $files=glob(kicomIntentDir().'/*.json')?:[];
    if(count($files)>=KICOM_MAX_INTENTS) return ['ok'=>false,'code'=>'INTENT_LIMIT'];
    $operation=strtoupper(trim($operation)); $target=trim($target);
    $allowed=['STAGE_PROPOSE','WORKSPACE_PROPOSE','WORKSPACE_ROLLBACK','MEMORY_PROPOSE','MEMORY_PATCH_PROPOSE','MEMORY_ROLLBACK','MEMORY_ARCHIVE_BEGIN','DEPLOY_PROPOSE','DEPLOY_ROLLBACK','DEPLOY_PACKAGE_PROPOSE','DEPLOY_PACKAGE_ROLLBACK'];
    if(!in_array($operation,$allowed,true) || $target==='' || strlen($target)>180) return ['ok'=>false,'code'=>'INVALID_INTENT'];
    try{$token=bin2hex(random_bytes(16));}catch(Throwable $e){$token=strtolower(hash('sha256',uniqid('',true)));}
    $row=['token'=>$token,'operation'=>$operation,'target'=>$target,'created_at'=>gmdate('c'),'expires_at'=>time()+KICOM_INTENT_TTL];
    $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if($json===false || @file_put_contents(kicomIntentDir().'/'.$token.'.json',$json,LOCK_EX)===false) return ['ok'=>false,'code'=>'INTENT_WRITE_FAILED'];
    @chmod(kicomIntentDir().'/'.$token.'.json',0600);
    return ['ok'=>true,'token'=>$token,'expires_in'=>KICOM_INTENT_TTL];
}
function kicomConsumeIntent(string $token,string $operation,string $target): array {
    if(!preg_match('/^[a-f0-9]{32,64}$/',$token)) return ['ok'=>false,'code'=>'INTENT_REQUIRED'];
    $file=kicomIntentDir().'/'.$token.'.json'; if(!is_file($file)) return ['ok'=>false,'code'=>'INTENT_NOT_FOUND'];
    $row=json_decode((string)@file_get_contents($file),true); @unlink($file);
    if(!is_array($row)) return ['ok'=>false,'code'=>'INTENT_INVALID'];
    if((int)($row['expires_at']??0)<time()) return ['ok'=>false,'code'=>'INTENT_EXPIRED'];
    if(!hash_equals((string)($row['operation']??''),strtoupper(trim($operation))) || !hash_equals((string)($row['target']??''),trim($target))) return ['ok'=>false,'code'=>'INTENT_MISMATCH'];
    return ['ok'=>true];
}
function kicomMemoryPatchCandidate(string $name,string $baseSha,string $find,string $replace): array {
    $name=strtoupper(trim($name)); $res=kicomReadMemoryResource($name);
    if($res===null) return ['ok'=>false,'code'=>'UNKNOWN_MEMORY_RESOURCE'];
    $baseSha=strtolower(trim($baseSha));
    if(!preg_match('/^[a-f0-9]{64}$/',$baseSha)) return ['ok'=>false,'code'=>'BASE_REQUIRED','current_sha256'=>$res['sha256']];
    if(!hash_equals((string)$res['sha256'],$baseSha)) return ['ok'=>false,'code'=>'BASE_CONFLICT','current_sha256'=>$res['sha256']];
    if($find==='' || strlen($find)>2048 || strlen($replace)>4096) return ['ok'=>false,'code'=>'PATCH_SIZE_INVALID'];
    $count=substr_count((string)$res['raw'],$find);
    if($count!==1) return ['ok'=>false,'code'=>'PATCH_MATCH_COUNT','matches'=>$count];
    $candidate=str_replace($find,$replace,(string)$res['raw'],$replaced);
    if($replaced!==1) return ['ok'=>false,'code'=>'PATCH_APPLY_FAILED'];
    if(strlen($candidate)>KICOM_MAX_MEMORY_PROPOSAL_BYTES) return ['ok'=>false,'code'=>'CONTENT_TOO_LARGE'];
    $v=kicomValidateMemoryContent($name,$candidate);
    if($v['status']==='error') return ['ok'=>false,'code'=>'VALIDATION_FAILED','validation'=>$v['message']];
    return ['ok'=>true,'resource'=>$name,'content'=>$candidate,'base_sha256'=>$baseSha,'sha256'=>hash('sha256',$candidate),'validation'=>$v];
}

function kicomSafeRelativePath(string $path): ?string {
    $path = trim(str_replace('\\', '/', $path));
    $path = ltrim($path, '/');
    if ($path === '' || strlen($path) > 180) return null;
    if (str_contains($path, "\0") || str_contains($path, '..')) return null;
    if (preg_match('~(^|/)[.]~', $path)) return null;
    if (!preg_match('~^[A-Za-z0-9_./-]+$~', $path)) return null;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $allowed = ['txt','md','json','php','html','htm','css','js'];
    return in_array($ext, $allowed, true) ? $path : null;
}
function kicomDecodeBase64Url(string $value, bool $allowEmpty = false): ?string {
    if ($value === '') return $allowEmpty ? '' : null;
    $value = strtr($value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad) $value .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode($value, true);
    return $decoded === false ? null : $decoded;
}
function kicomEncodeBase64Url(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
function kicomCurrentHash(string $path): string {
    $full = kicomStageDir() . '/' . $path;
    if (!is_file($full)) return 'NEW';
    return hash_file('sha256', $full) ?: '';
}
function kicomReadStage(string $path): ?string {
    $full = kicomStageDir() . '/' . $path;
    if (!is_file($full)) return null;
    $raw = @file_get_contents($full);
    return $raw === false ? null : (string)$raw;
}
function kicomListStageFiles(): array {
    $root = kicomStageDir();
    $result = [];
    if (!is_dir($root)) return $result;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $path = str_replace('\\','/', substr($file->getPathname(), strlen($root)+1));
        if ($path === '.htaccess') continue;
        $result[] = [
            'path'=>$path,
            'bytes'=>$file->getSize(),
            'sha256'=>hash_file('sha256',$file->getPathname()) ?: '',
            'revisions'=>kicomHistoryCount($path),
        ];
        if (count($result) >= 100) break;
    }
    usort($result, fn($a,$b)=>strcmp($a['path'],$b['path']));
    return $result;
}
function kicomValidateContent(string $path, string $content): array {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'json') {
        json_decode($content, true);
        return json_last_error() === JSON_ERROR_NONE
            ? ['status'=>'ok','message'=>'JSON_VALID']
            : ['status'=>'error','message'=>'JSON_INVALID:' . json_last_error_msg()];
    }
    if ($ext === 'php') {
        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) return ['status'=>'warn','message'=>'PHP_LINT_UNAVAILABLE'];
        if (!kicomEnsureStorage()) return ['status'=>'error','message'=>'STORAGE_UNAVAILABLE'];
        $tmp = kicomTempDir() . '/lint-' . strtolower(kicomRequestId()) . '.php';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) return ['status'=>'error','message'=>'TEMP_WRITE_FAILED'];
        $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1';
        $lines = []; $code = 1; @exec($cmd, $lines, $code); @unlink($tmp);
        if ($code === 0) return ['status'=>'ok','message'=>'PHP_SYNTAX_OK'];
        $diag=implode("\n",array_map('strval',$lines));
        if (preg_match('/(?:parse error|syntax error|errors parsing)/i',$diag)) return ['status'=>'error','message'=>'PHP_SYNTAX_ERROR'];
        return ['status'=>'warn','message'=>'PHP_LINT_UNAVAILABLE'];
    }
    return ['status'=>'ok','message'=>'NO_PARSER_REQUIRED'];
}
function kicomValidateStage(string $path): array {
    $raw = kicomReadStage($path);
    return $raw === null ? ['status'=>'error','message'=>'FILE_NOT_FOUND'] : kicomValidateContent($path, $raw);
}

function kicomHistoryKey(string $path): string { return hash('sha256', $path); }
function kicomHistoryPathDir(string $path): string { return kicomHistoryDir() . '/' . kicomHistoryKey($path); }
function kicomHistoryFiles(string $path): array {
    $dir = kicomHistoryPathDir($path);
    if (!is_dir($dir)) return [];
    $rows = [];
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $row = json_decode((string)@file_get_contents($file), true);
        if (is_array($row) && (($row['path'] ?? null) === $path)) $rows[] = $row;
    }
    usort($rows, fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')) ?: strcmp((string)($b['revision']??''),(string)($a['revision']??'')));
    return $rows;
}
function kicomHistoryCount(string $path): int { return count(kicomHistoryFiles($path)); }
function kicomHistoryGet(string $path, string $revision): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{8,100}$/', $revision)) return null;
    $file = kicomHistoryPathDir($path) . '/' . $revision . '.json';
    if (!is_file($file)) return null;
    $row = json_decode((string)@file_get_contents($file), true);
    return is_array($row) && (($row['path'] ?? null) === $path) ? $row : null;
}
function kicomRecordRevision(string $path, ?string $content, string $action): ?string {
    if (!kicomEnsureStorage()) return null;
    $dir = kicomHistoryPathDir($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
    $sha = $content === null ? 'deleted' : hash('sha256', $content);
    $revision = gmdate('YmdHis') . '-' . substr($sha,0,12) . '-' . strtolower(kicomRequestId());
    $row = [
        'revision'=>$revision,
        'created_at'=>gmdate('c'),
        'path'=>$path,
        'action'=>$action,
        'bytes'=>$content === null ? 0 : strlen($content),
        'sha256'=>$content === null ? null : $sha,
        'content_b64'=>$content === null ? null : base64_encode($content),
    ];
    $json = json_encode($row, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($dir . '/' . $revision . '.json', $json, LOCK_EX) === false) return null;
    $files = glob($dir . '/*.json') ?: [];
    if (count($files) > KICOM_MAX_HISTORY_PER_FILE) {
        usort($files, fn($a,$b)=>(int)@filemtime($a) <=> (int)@filemtime($b));
        foreach (array_slice($files, 0, count($files)-KICOM_MAX_HISTORY_PER_FILE) as $old) @unlink($old);
    }
    return $revision;
}
function kicomEnsureBaseline(string $path): void {
    if (kicomHistoryCount($path) > 0) return;
    $current = kicomReadStage($path);
    if ($current !== null) kicomRecordRevision($path, $current, 'baseline');
}
function kicomAtomicStageWrite(string $path, string $content, string $action, ?string $expectedBase = null): array {
    $path = kicomSafeRelativePath($path);
    if ($path === null) return ['ok'=>false,'code'=>'INVALID_PATH'];
    $currentHash = kicomCurrentHash($path);
    if ($expectedBase !== null && $expectedBase !== '' && !hash_equals($currentHash, $expectedBase)) {
        return ['ok'=>false,'code'=>'BASE_CONFLICT','current_sha256'=>$currentHash];
    }
    kicomEnsureBaseline($path);
    $target = kicomStageDir() . '/' . $path;
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) return ['ok'=>false,'code'=>'TARGET_DIR_FAILED'];
    $tmp = $target . '.tmp-' . strtolower(kicomRequestId());
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) return ['ok'=>false,'code'=>'WRITE_FAILED'];
    @chmod($tmp,0600);
    if (!@rename($tmp,$target)) { @unlink($tmp); return ['ok'=>false,'code'=>'RENAME_FAILED']; }
    @chmod($target,0600);
    $revision = kicomRecordRevision($path, $content, $action);
    return ['ok'=>true,'revision'=>$revision ?? '','sha256'=>hash('sha256',$content),'bytes'=>strlen($content)];
}
function kicomDeleteStageWithHistory(string $path): array {
    $path = kicomSafeRelativePath($path);
    if ($path === null) return ['ok'=>false,'code'=>'INVALID_PATH'];
    $target = kicomStageDir() . '/' . $path;
    if (!is_file($target)) return ['ok'=>false,'code'=>'FILE_NOT_FOUND'];
    kicomEnsureBaseline($path);
    if (!@unlink($target)) return ['ok'=>false,'code'=>'DELETE_FAILED'];
    $revision = kicomRecordRevision($path, null, 'delete');
    return ['ok'=>true,'revision'=>$revision ?? ''];
}
function kicomCreateProposal(array $proposal): array {
    kicomCleanupPending();
    $pending = glob(kicomPendingDir().'/*.json') ?: [];
    if (count($pending) >= KICOM_MAX_PENDING) return ['ok'=>false,'code'=>'PENDING_LIMIT'];
    $id = strtolower(kicomRequestId());
    $proposal['id'] = $id;
    $proposal['created_at'] = gmdate('c');
    $json = json_encode($proposal, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents(kicomPendingDir().'/'.$id.'.json', $json, LOCK_EX) === false) {
        return ['ok'=>false,'code'=>'PENDING_WRITE_FAILED'];
    }
    return ['ok'=>true,'id'=>$id];
}

function kicomUnifiedDiff(string $old, string $new, int $maxLines = 260): array {
    $a = preg_split('/\r?\n/', $old) ?: [];
    $b = preg_split('/\r?\n/', $new) ?: [];
    if (count($a) > $maxLines || count($b) > $maxLines) return ['ok'=>false,'code'=>'DIFF_LINE_LIMIT'];
    $n=count($a); $m=count($b); $dp=array_fill(0,$n+1,array_fill(0,$m+1,0));
    for($i=$n-1;$i>=0;$i--) for($j=$m-1;$j>=0;$j--) $dp[$i][$j]=($a[$i]===$b[$j])?1+$dp[$i+1][$j+1]:max($dp[$i+1][$j],$dp[$i][$j+1]);
    $i=0;$j=0;$lines=['--- current','+++ proposed'];
    while($i<$n && $j<$m){
        if($a[$i]===$b[$j]){ $lines[]=' '.$a[$i]; $i++;$j++; }
        elseif($dp[$i+1][$j] >= $dp[$i][$j+1]){ $lines[]='-'.$a[$i]; $i++; }
        else { $lines[]='+'.$b[$j]; $j++; }
    }
    while($i<$n){$lines[]='-'.$a[$i++];}
    while($j<$m){$lines[]='+'.$b[$j++];}
    return ['ok'=>true,'lines'=>$lines,'changed'=>($old!==$new)];
}

function kicomMemoryResources(): array {
    return [
        'PROJECT_STATE'=>'project_state.kcl','ARCHITECTURE'=>'architecture.kcl','PROTOCOL'=>'protocol.kcl',
        'DECISIONS'=>'decisions.kcl','CHANGELOG'=>'changelog.kcl','NEXT'=>'next.kcl',
    ];
}
function kicomReadMemoryResource(string $name): ?array {
    $name=strtoupper(trim($name));
    $resources=kicomMemoryResources(); if(!isset($resources[$name])) return null;
    if(!kicomEnsureStorage()) return null;
    $file=kicomMemoryStateDir().'/'.$resources[$name]; if(!is_file($file)) return null;
    $raw=@file_get_contents($file); if($raw===false||$raw===''||strlen($raw)>65536)return null;
    return ['name'=>$name,'content'=>rtrim((string)$raw,"\r\n"),'raw'=>(string)$raw,'sha256'=>hash('sha256',(string)$raw),'bytes'=>strlen((string)$raw)];
}
function kicomMemoryCurrentHash(string $name): string {
    $res=kicomReadMemoryResource($name); return $res===null?'':(string)$res['sha256'];
}
function kicomValidateMemoryContent(string $name, string $content): array {
    $name=strtoupper(trim($name));
    if(!isset(kicomMemoryResources()[$name])) return ['status'=>'error','message'=>'UNKNOWN_MEMORY_RESOURCE'];
    if($content===''||strlen($content)>KICOM_MAX_MEMORY_PROPOSAL_BYTES) return ['status'=>'error','message'=>'MEMORY_SIZE_INVALID'];
    if(str_contains($content,"\0") || preg_match('//u',$content)!==1) return ['status'=>'error','message'=>'MEMORY_ENCODING_INVALID'];
    if(str_contains($content,'<?') || str_contains($content,'?>')) return ['status'=>'error','message'=>'MEMORY_CODE_MARKER_FORBIDDEN'];
    $markers=[
        'PROJECT_STATE'=>['PROJECT kicom','END_PROJECT kicom'],
        'ARCHITECTURE'=>['ARCHITECTURE kicom','END_ARCHITECTURE kicom'],
        'PROTOCOL'=>['PROTOCOL KCL/1','END_PROTOCOL KCL/1'],
        'DECISIONS'=>['DECISIONS kicom','END_DECISIONS kicom'],
        'CHANGELOG'=>['CHANGELOG kicom','END_CHANGELOG kicom'],
        'NEXT'=>['NEXT kicom','END_NEXT kicom'],
    ];
    $trim=trim($content); [$start,$end]=$markers[$name];
    if(!str_starts_with($trim,$start) || !str_ends_with($trim,$end)) return ['status'=>'error','message'=>'KCL_STRUCTURE_INVALID'];
    foreach(preg_split('/\r?\n/',$content)?:[] as $line) if(strlen($line)>2048) return ['status'=>'error','message'=>'KCL_LINE_TOO_LONG'];
    if($name==='PROJECT_STATE') {
        foreach(['FACT direct_shell=false','FACT arbitrary_remote_fetch=false','FACT canonical_memory_via="?q=BOOTSTRAP"'] as $required) {
            if(!str_contains($content,$required)) return ['status'=>'error','message'=>'PROJECT_STATE_SAFETY_SENTINEL_MISSING'];
        }
        if(!str_contains($content,'FACT production_write=false') && !str_contains($content,'FACT production_write="allowlisted-human-approved"')) return ['status'=>'error','message'=>'PROJECT_STATE_DEPLOYMENT_SENTINEL_MISSING'];
    }
    return ['status'=>'ok','message'=>'KCL_STRUCTURE_OK'];
}
function kicomListMemoryResources(): array {
    $rows=[];
    foreach(array_keys(kicomMemoryResources()) as $name){
        $res=kicomReadMemoryResource($name); if($res===null) continue;
        $rows[]=['resource'=>$name,'bytes'=>$res['bytes'],'sha256'=>$res['sha256'],'revisions'=>kicomMemoryHistoryCount($name)];
    }
    return $rows;
}
function kicomMemoryHistoryResourceDir(string $name): string { return kicomMemoryHistoryDir().'/'.strtoupper($name); }
function kicomMemoryHistoryFiles(string $name): array {
    $name=strtoupper(trim($name)); if(!isset(kicomMemoryResources()[$name])) return [];
    $dir=kicomMemoryHistoryResourceDir($name); if(!is_dir($dir)) return [];
    $rows=[]; foreach(glob($dir.'/*.json')?:[] as $file){$row=json_decode((string)@file_get_contents($file),true);if(is_array($row)&&(($row['resource']??null)===$name))$rows[]=$row;}
    usort($rows,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')) ?: strcmp((string)($b['revision']??''),(string)($a['revision']??'')));
    return $rows;
}
function kicomMemoryHistoryCount(string $name): int { return count(kicomMemoryHistoryFiles($name)); }
function kicomMemoryHistoryGet(string $name,string $revision): ?array {
    $name=strtoupper(trim($name)); if(!isset(kicomMemoryResources()[$name])||!preg_match('/^[A-Za-z0-9_-]{8,100}$/',$revision))return null;
    $file=kicomMemoryHistoryResourceDir($name).'/'.$revision.'.json'; if(!is_file($file))return null;
    $row=json_decode((string)@file_get_contents($file),true); return is_array($row)&&(($row['resource']??null)===$name)?$row:null;
}
function kicomRecordMemoryRevision(string $name,string $content,string $action): ?string {
    $name=strtoupper(trim($name)); if(!isset(kicomMemoryResources()[$name])||!kicomEnsureStorage())return null;
    $dir=kicomMemoryHistoryResourceDir($name); if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return null;
    $sha=hash('sha256',$content); $revision=gmdate('YmdHis').'-'.substr($sha,0,12).'-'.strtolower(kicomRequestId());
    $row=['revision'=>$revision,'created_at'=>gmdate('c'),'resource'=>$name,'action'=>$action,'bytes'=>strlen($content),'sha256'=>$sha,'content_b64'=>base64_encode($content)];
    $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT); if($json===false||@file_put_contents($dir.'/'.$revision.'.json',$json,LOCK_EX)===false)return null;
    $files=glob($dir.'/*.json')?:[]; if(count($files)>KICOM_MAX_HISTORY_PER_FILE){usort($files,fn($a,$b)=>(int)@filemtime($a)<=>(int)@filemtime($b));foreach(array_slice($files,0,count($files)-KICOM_MAX_HISTORY_PER_FILE) as $old)@unlink($old);}
    return $revision;
}
function kicomEnsureMemoryBaseline(string $name): void {
    if(kicomMemoryHistoryCount($name)>0)return; $res=kicomReadMemoryResource($name); if($res!==null)kicomRecordMemoryRevision($name,(string)$res['raw'],'baseline');
}
function kicomAtomicMemoryWrite(string $name,string $content,string $action,string $expectedBase): array {
    $name=strtoupper(trim($name)); if(!isset(kicomMemoryResources()[$name]))return ['ok'=>false,'code'=>'UNKNOWN_MEMORY_RESOURCE'];
    $v=kicomValidateMemoryContent($name,$content); if($v['status']==='error')return ['ok'=>false,'code'=>'VALIDATION_FAILED','validation'=>$v['message']];
    $current=kicomMemoryCurrentHash($name); if($current===''||!hash_equals($current,$expectedBase))return ['ok'=>false,'code'=>'BASE_CONFLICT','current_sha256'=>$current];
    kicomEnsureMemoryBaseline($name); $target=kicomMemoryStateDir().'/'.kicomMemoryResources()[$name]; $tmp=$target.'.tmp-'.strtolower(kicomRequestId());
    if(@file_put_contents($tmp,$content,LOCK_EX)===false)return ['ok'=>false,'code'=>'WRITE_FAILED']; @chmod($tmp,0600);
    if(!@rename($tmp,$target)){@unlink($tmp);return ['ok'=>false,'code'=>'RENAME_FAILED'];} @chmod($target,0600);
    $revision=kicomRecordMemoryRevision($name,$content,$action);
    return ['ok'=>true,'revision'=>$revision??'','sha256'=>hash('sha256',$content),'bytes'=>strlen($content)];
}


/* KiCom 0.6: controlled deployment layer. Target filesystem roots are configured only in admin.php
   and never returned through the public protocol. The client only sees target aliases. */
function kicomDeployTargetAlias(string $alias): ?string {
    $alias=strtolower(trim($alias));
    return preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/',$alias)?$alias:null;
}
function kicomDeployClass(string $class): ?string {
    $class=strtolower(trim($class));
    return in_array($class,['test','staging','production'],true)?$class:null;
}
function kicomDeployRoot(string $root): ?string {
    $root=trim($root); if($root===''||str_contains($root,"\0"))return null;
    $real=realpath($root); if($real===false||!is_dir($real))return null;
    $base=realpath(kicomBaseDir());
    if($base!==false){$a=rtrim(str_replace('\\','/',$real),'/').'/';$b=rtrim(str_replace('\\','/',$base),'/').'/';if(str_starts_with($a,$b))return null;}
    return $real;
}
function kicomValidateHealthUrl(string $url): ?string {
    $url=trim($url); if($url==='')return '';
    $p=parse_url($url); if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass']))return null;
    return $url;
}
function kicomLoadDeployTargets(): array {
    $f=kicomDeployTargetsFile(); if(!is_file($f))return [];
    $x=require $f; if(!is_array($x))return [];
    $out=[];
    foreach($x as $alias=>$row){
        $a=kicomDeployTargetAlias((string)$alias); if($a===null||!is_array($row))continue;
        $root=kicomDeployRoot((string)($row['root']??'')); if($root===null)continue;
        $health=kicomValidateHealthUrl((string)($row['health_url']??'')); if($health===null)continue;
        /* Backward compatibility: 0.6.0 targets without an explicit class become test targets. */
        $class=kicomDeployClass((string)($row['class']??'test')); if($class===null)continue;
        if($class==='production' && $health==='')continue;
        $out[$a]=[
            'root'=>$root,
            'health_url'=>$health,
            'enabled'=>!empty($row['enabled']),
            'label'=>(string)($row['label']??$a),
            'class'=>$class,
        ];
    }
    return $out;
}
function kicomSaveDeployTargets(array $targets): bool {
    $clean=[];
    foreach($targets as $alias=>$row){
        $a=kicomDeployTargetAlias((string)$alias); if($a===null||!is_array($row))return false;
        $root=kicomDeployRoot((string)($row['root']??'')); if($root===null)return false;
        $health=kicomValidateHealthUrl((string)($row['health_url']??'')); if($health===null)return false;
        $class=kicomDeployClass((string)($row['class']??'test')); if($class===null)return false;
        if($class==='production' && $health==='')return false;
        $clean[$a]=[
            'root'=>$root,
            'health_url'=>$health,
            'enabled'=>!empty($row['enabled']),
            'label'=>substr(trim((string)($row['label']??$a)),0,80),
            'class'=>$class,
        ];
    }
    $php="<?php\nreturn ".var_export($clean,true).";\n";
    $ok=@file_put_contents(kicomDeployTargetsFile(),$php,LOCK_EX)!==false; if($ok)@chmod(kicomDeployTargetsFile(),0600); return $ok;
}
function kicomPublicDeployTargets(): array {
    $rows=[];
    foreach(kicomLoadDeployTargets() as $alias=>$r)$rows[]=[
        'alias'=>$alias,
        'label'=>$r['label'],
        'enabled'=>$r['enabled'],
        'healthcheck'=>$r['health_url']!=='',
        'class'=>$r['class'],
    ];
    return $rows;
}
function kicomDeployTarget(string $alias,bool $requireEnabled=true): ?array {
    $a=kicomDeployTargetAlias($alias); if($a===null)return null;$all=kicomLoadDeployTargets(); if(!isset($all[$a]))return null;if($requireEnabled&&!$all[$a]['enabled'])return null;return ['alias'=>$a]+$all[$a];
}
function kicomTargetFile(string $alias,string $dest): ?array {
    $t=kicomDeployTarget($alias,true);$dest=kicomSafeRelativePath($dest);if($t===null||$dest===null)return null;
    $full=rtrim((string)$t['root'],DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$dest);
    $parent=dirname($full);$rootReal=realpath((string)$t['root']);$parentReal=is_dir($parent)?realpath($parent):realpath(dirname($parent));
    if($rootReal===false)return null;
    $rootNorm=rtrim(str_replace('\\','/',$rootReal),'/').'/';
    $check=$parentReal!==false?rtrim(str_replace('\\','/',$parentReal),'/').'/':$rootNorm;
    if(!str_starts_with($check,$rootNorm))return null;
    return ['target'=>$t,'dest'=>$dest,'full'=>$full];
}
function kicomTargetHash(string $alias,string $dest): string {
    $r=kicomTargetFile($alias,$dest);if($r===null)return '';$f=$r['full'];if(!is_file($f))return 'NEW';return hash_file('sha256',$f)?:'';
}
function kicomHealthCheck(string $alias): array {
    $t=kicomDeployTarget($alias,true);if($t===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];$url=(string)$t['health_url'];if($url==='')return ['ok'=>true,'configured'=>false,'http_code'=>null];
    $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>KICOM_HEALTH_TIMEOUT,'ignore_errors'=>true,'header'=>"User-Agent: KiCom/".KICOM_VERSION." HealthCheck\r\nConnection: close\r\n"]]);
    $body=@file_get_contents($url,false,$ctx,0,1);$headers=$http_response_header??[];$code=0;foreach($headers as $h){if(preg_match('~^HTTP/\\S+\\s+(\\d{3})~i',$h,$m)){$code=(int)$m[1];break;}}
    return ['ok'=>$code>=200&&$code<400,'configured'=>true,'http_code'=>$code,'code'=>$code?null:'HEALTH_REQUEST_FAILED'];
}
function kicomDeployDryRun(string $alias,string $workspacePath,string $dest): array {
    $workspacePath=kicomSafeRelativePath($workspacePath); $dest=kicomSafeRelativePath($dest); $t=kicomDeployTarget($alias,true);
    if($workspacePath===null||$dest===null)return ['ok'=>false,'code'=>'INVALID_PATH'];
    if($t===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];
    if((string)$t['class']==='production' && (string)$t['health_url']==='')return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_REQUIRED'];
    $content=kicomReadStage($workspacePath); if($content===null)return ['ok'=>false,'code'=>'WORKSPACE_FILE_NOT_FOUND'];
    $tf0=kicomTargetFile($alias,$dest); if($tf0!==null&&is_file((string)$tf0['full'])&&filesize((string)$tf0['full'])>KICOM_MAX_DEPLOY_BACKUP_BYTES)return ['ok'=>false,'code'=>'TARGET_TOO_LARGE_TO_BACKUP'];
    $v=kicomValidateContent($dest,$content); if($v['status']==='error')return ['ok'=>false,'code'=>'VALIDATION_FAILED','validation'=>$v['message']];
    $tf=kicomTargetFile($alias,$dest); if($tf===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID'];
    $current=kicomTargetHash($alias,$dest); $parent=dirname((string)$tf['full']); $writable=is_dir($parent)?is_writable($parent):is_writable((string)$t['root']);
    $health=kicomHealthCheck($alias);
    if((string)$t['class']==='production' && (!$health['configured'] || !$health['ok']))return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_FAILED','health'=>$health];
    return [
        'ok'=>true,'target'=>$t['alias'],'target_class'=>$t['class'],'workspace_path'=>$workspacePath,'dest_path'=>$dest,
        'workspace_sha256'=>hash('sha256',$content),'target_sha256'=>$current,'bytes'=>strlen($content),'validation'=>$v['message'],
        'target_writable'=>$writable,'healthcheck_configured'=>$t['health_url']!=='','healthcheck_preflight_ok'=>$health['ok'],
        'healthcheck_http_code'=>$health['http_code'],
    ];
}
function kicomCreateDeployProposal(string $alias,string $workspacePath,string $dest,string $workspaceSha,string $targetBase,string $transport): array {
    $d=kicomDeployDryRun($alias,$workspacePath,$dest);if(!$d['ok'])return $d;if(!$d['target_writable'])return ['ok'=>false,'code'=>'TARGET_NOT_WRITABLE'];
    $workspaceSha=strtolower(trim($workspaceSha));$targetBase=strtoupper(trim($targetBase))==='NEW'?'NEW':strtolower(trim($targetBase));
    if(!preg_match('/^[a-f0-9]{64}$/',$workspaceSha)||($targetBase!=='NEW'&&!preg_match('/^[a-f0-9]{64}$/',$targetBase)))return ['ok'=>false,'code'=>'INVALID_HASH'];
    if(!hash_equals((string)$d['workspace_sha256'],$workspaceSha))return ['ok'=>false,'code'=>'WORKSPACE_CONFLICT','current_workspace_sha256'=>$d['workspace_sha256']];
    if(!hash_equals((string)$d['target_sha256'],$targetBase))return ['ok'=>false,'code'=>'TARGET_CONFLICT','current_target_sha256'=>$d['target_sha256']];
    $pr=kicomCreateProposal(['kind'=>'deploy_write','target'=>$d['target'],'target_class'=>$d['target_class'],'workspace_path'=>$d['workspace_path'],'dest_path'=>$d['dest_path'],'workspace_sha256'=>$workspaceSha,'target_base_sha256'=>$targetBase,'bytes'=>$d['bytes'],'sha256'=>$workspaceSha,'validation'=>['status'=>'ok','message'=>$d['validation']],'healthcheck_preflight_ok'=>$d['healthcheck_preflight_ok'],'healthcheck_http_code'=>$d['healthcheck_http_code'],'transport'=>$transport]);
    return $pr['ok']?['ok'=>true,'proposal_id'=>$pr['id']]+$d:$pr;
}
function kicomDeployHistoryFiles(): array {
    $rows=[];foreach(glob(kicomDeployHistoryDir().'/*.json')?:[] as $f){$r=json_decode((string)@file_get_contents($f),true);if(is_array($r))$rows[]=$r;}usort($rows,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));return array_slice($rows,0,KICOM_MAX_DEPLOY_HISTORY);
}
function kicomDeployHistoryGet(string $id): ?array {
    if(!preg_match('/^[A-Za-z0-9_-]{8,100}$/',$id))return null;$f=kicomDeployHistoryDir().'/'.$id.'.json';if(!is_file($f))return null;$r=json_decode((string)@file_get_contents($f),true);return is_array($r)?$r:null;
}
function kicomRecordDeployment(array $row): ?string {
    $id=gmdate('YmdHis').'-'.strtolower(kicomRequestId());$row['id']=$id;$row['created_at']=gmdate('c');$json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);if($json===false||@file_put_contents(kicomDeployHistoryDir().'/'.$id.'.json',$json,LOCK_EX)===false)return null;@chmod(kicomDeployHistoryDir().'/'.$id.'.json',0600);$files=glob(kicomDeployHistoryDir().'/*.json')?:[];if(count($files)>KICOM_MAX_DEPLOY_HISTORY){usort($files,fn($a,$b)=>(int)@filemtime($a)<=>(int)@filemtime($b));foreach(array_slice($files,0,count($files)-KICOM_MAX_DEPLOY_HISTORY) as $old)@unlink($old);}return $id;
}
function kicomWriteDeployTarget(string $alias,string $dest,string $content): array {
    $r=kicomTargetFile($alias,$dest);if($r===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID'];$full=(string)$r['full'];$dir=dirname($full);if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))return ['ok'=>false,'code'=>'TARGET_DIR_FAILED'];$tmp=$full.'.kicom-tmp-'.strtolower(kicomRequestId());if(@file_put_contents($tmp,$content,LOCK_EX)===false)return ['ok'=>false,'code'=>'TARGET_WRITE_FAILED'];@chmod($tmp,0640);if(!@rename($tmp,$full)){@unlink($tmp);return ['ok'=>false,'code'=>'TARGET_RENAME_FAILED'];}@chmod($full,0640);return ['ok'=>true,'sha256'=>hash('sha256',$content)];
}
function kicomRestoreDeploymentBackup(array $hist,string $expectedCurrent): array {
    $alias=(string)($hist['target']??'');$dest=(string)($hist['dest_path']??'');$current=kicomTargetHash($alias,$dest);if($current===''||!hash_equals($current,$expectedCurrent))return ['ok'=>false,'code'=>'TARGET_CONFLICT','current_target_sha256'=>$current];
    $before=(string)($hist['before_sha256']??'');$backup=(string)($hist['backup_content_b64']??'');
    $r=kicomTargetFile($alias,$dest);if($r===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID'];
    if($before==='NEW'){
        if(is_file((string)$r['full'])&&!@unlink((string)$r['full']))return ['ok'=>false,'code'=>'ROLLBACK_DELETE_FAILED'];$after='NEW';
    }else{$content=base64_decode($backup,true);if($content===false||hash('sha256',$content)!==$before)return ['ok'=>false,'code'=>'BACKUP_CORRUPT'];$w=kicomWriteDeployTarget($alias,$dest,$content);if(!$w['ok'])return $w;$after=$w['sha256'];}
    return ['ok'=>true,'sha256'=>$after];
}
function kicomApplyDeployProposal(array $p): array {
    $kind=(string)($p['kind']??'');
    if($kind==='deploy_package_write')return kicomApplyDeployPackageWrite($p);
    if($kind==='deploy_package_rollback')return kicomApplyDeployPackageRollback($p);
    if($kind==='deploy_write'){
        $alias=(string)($p['target']??'');$wp=kicomSafeRelativePath((string)($p['workspace_path']??''));$dest=kicomSafeRelativePath((string)($p['dest_path']??''));$target=kicomDeployTarget($alias,true);if($wp===null||$dest===null||$target===null)return ['ok'=>false,'code'=>'INVALID_DEPLOY_PROPOSAL'];
        if((string)$target['class']==='production'){$pre=kicomHealthCheck($alias);if(!$pre['configured']||!$pre['ok'])return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_FAILED','health'=>$pre];}
        $content=kicomReadStage($wp);if($content===null)return ['ok'=>false,'code'=>'WORKSPACE_FILE_NOT_FOUND'];$wsha=hash('sha256',$content);if(!hash_equals($wsha,(string)($p['workspace_sha256']??'')))return ['ok'=>false,'code'=>'WORKSPACE_CONFLICT','current_workspace_sha256'=>$wsha];$base=(string)($p['target_base_sha256']??'');$current=kicomTargetHash($alias,$dest);if($current===''||!hash_equals($current,$base))return ['ok'=>false,'code'=>'TARGET_CONFLICT','current_target_sha256'=>$current];
        $v=kicomValidateContent($dest,$content);if($v['status']==='error')return ['ok'=>false,'code'=>'VALIDATION_FAILED'];$tf=kicomTargetFile($alias,$dest);if($tf===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID'];if(is_file((string)$tf['full'])&&filesize((string)$tf['full'])>KICOM_MAX_DEPLOY_BACKUP_BYTES)return ['ok'=>false,'code'=>'TARGET_TOO_LARGE_TO_BACKUP'];$beforeContent=is_file((string)$tf['full'])?@file_get_contents((string)$tf['full']):false;$beforeB64=$beforeContent===false?'':base64_encode((string)$beforeContent);
        $w=kicomWriteDeployTarget($alias,$dest,$content);if(!$w['ok'])return $w;$health=kicomHealthCheck($alias);$hist=['action'=>'deploy','target'=>$alias,'target_class'=>$target['class'],'dest_path'=>$dest,'workspace_path'=>$wp,'workspace_sha256'=>$wsha,'before_sha256'=>$base,'after_sha256'=>$w['sha256'],'backup_content_b64'=>$beforeB64,'health'=>$health,'status'=>$health['ok']?'deployed':'health_failed'];
        if(!$health['ok']){$restore=kicomRestoreDeploymentBackup($hist,$w['sha256']);$hist['status']=$restore['ok']?'rolled_back_healthcheck':'health_failed_rollback_failed';$hist['rollback_result']=$restore;$id=kicomRecordDeployment($hist);return ['ok'=>false,'code'=>$restore['ok']?'HEALTHCHECK_FAILED_ROLLED_BACK':'HEALTHCHECK_FAILED_ROLLBACK_FAILED','deployment_id'=>$id??'','health'=>$health,'rolled_back'=>$restore['ok']];}
        $id=kicomRecordDeployment($hist);return ['ok'=>true,'deployment_id'=>$id??'','sha256'=>$w['sha256'],'health'=>$health];
    }
    if($kind==='deploy_rollback'){
        $source=kicomDeployHistoryGet((string)($p['source_deployment']??''));if($source===null)return ['ok'=>false,'code'=>'DEPLOYMENT_NOT_FOUND'];$expected=(string)($p['target_base_sha256']??'');$r=kicomRestoreDeploymentBackup($source,$expected);if(!$r['ok'])return $r;$health=kicomHealthCheck((string)$source['target']);$rollbackTarget=kicomDeployTarget((string)$source['target'],true);$id=kicomRecordDeployment(['action'=>'rollback','target'=>$source['target'],'target_class'=>$rollbackTarget['class']??($source['target_class']??'test'),'dest_path'=>$source['dest_path'],'source_deployment'=>$source['id'],'before_sha256'=>$expected,'after_sha256'=>$r['sha256'],'status'=>$health['ok']?'rolled_back':'rolled_back_health_warning','health'=>$health,'backup_content_b64'=>'']);return ['ok'=>true,'deployment_id'=>$id??'','sha256'=>$r['sha256'],'health'=>$health];
    }
    return ['ok'=>false,'code'=>'UNKNOWN_DEPLOY_KIND'];
}
function kicomCreateDeployRollbackProposal(string $deploymentId,string $transport): array {
    $h=kicomDeployHistoryGet($deploymentId);if($h===null||($h['action']??'')!=='deploy')return ['ok'=>false,'code'=>'DEPLOYMENT_NOT_FOUND'];
    $target=kicomDeployTarget((string)$h['target'],true);if($target===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];
    $current=kicomTargetHash((string)$h['target'],(string)$h['dest_path']);if($current===''||!hash_equals($current,(string)$h['after_sha256']))return ['ok'=>false,'code'=>'TARGET_CONFLICT','current_target_sha256'=>$current];
    $pr=kicomCreateProposal(['kind'=>'deploy_rollback','target'=>$h['target'],'target_class'=>$target['class'],'dest_path'=>$h['dest_path'],'source_deployment'=>$deploymentId,'target_base_sha256'=>$current,'bytes'=>0,'sha256'=>(string)$h['before_sha256'],'transport'=>$transport]);
    return $pr['ok']?['ok'=>true,'proposal_id'=>$pr['id'],'target'=>$h['target'],'target_class'=>$target['class'],'dest_path'=>$h['dest_path'],'current_sha256'=>$current,'target_sha256'=>$h['before_sha256']]:$pr;
}

/* KiCom 0.7: transaction-like multi-file deployment packages.
   Packages are explicit JSON manifests stored in the versioned workspace under packages/*.json.
   Every file is preflighted and backed up before commit. Multi-file filesystem commits are not
   globally atomic; failures trigger immediate all-or-nothing restoration attempts. */
function kicomPackageManifestPath(string $path): ?string {
    $p=kicomSafeRelativePath($path);
    if($p===null)return null;
    if(!str_starts_with($p,'packages/')||strtolower(pathinfo($p,PATHINFO_EXTENSION))!=='json')return null;
    return $p;
}
function kicomReadDeployPackageManifest(string $manifestPath): array {
    $manifestPath=kicomPackageManifestPath($manifestPath);
    if($manifestPath===null)return ['ok'=>false,'code'=>'INVALID_MANIFEST_PATH'];
    $raw=kicomReadStage($manifestPath);
    if($raw===null)return ['ok'=>false,'code'=>'MANIFEST_NOT_FOUND'];
    if(strlen($raw)>KICOM_MAX_READ_BYTES)return ['ok'=>false,'code'=>'MANIFEST_TOO_LARGE'];
    $j=json_decode($raw,true);
    if(!is_array($j)||($j['format']??'')!=='kicom-deploy-package/1'||!is_array($j['files']??null))return ['ok'=>false,'code'=>'MANIFEST_INVALID'];
    $name=trim((string)($j['name']??basename($manifestPath,'.json')));
    if($name===''||strlen($name)>80)return ['ok'=>false,'code'=>'PACKAGE_NAME_INVALID'];
    $files=$j['files'];
    if(count($files)<1||count($files)>KICOM_MAX_PACKAGE_FILES)return ['ok'=>false,'code'=>'PACKAGE_FILE_COUNT_INVALID'];
    $out=[];$seen=[];$total=0;
    foreach($files as $i=>$row){
        if(!is_array($row))return ['ok'=>false,'code'=>'MANIFEST_ENTRY_INVALID','entry'=>$i+1];
        $wp=kicomSafeRelativePath((string)($row['workspace_path']??''));
        $dest=kicomSafeRelativePath((string)($row['dest_path']??''));
        if($wp===null||$dest===null)return ['ok'=>false,'code'=>'MANIFEST_PATH_INVALID','entry'=>$i+1];
        if(isset($seen[$dest]))return ['ok'=>false,'code'=>'DUPLICATE_DEST_PATH','entry'=>$i+1];
        $seen[$dest]=true;
        $content=kicomReadStage($wp);
        if($content===null)return ['ok'=>false,'code'=>'WORKSPACE_FILE_NOT_FOUND','entry'=>$i+1,'workspace_path'=>$wp];
        $v=kicomValidateContent($dest,$content);
        if($v['status']==='error')return ['ok'=>false,'code'=>'VALIDATION_FAILED','entry'=>$i+1,'dest_path'=>$dest,'validation'=>$v['message']];
        $bytes=strlen($content);$total+=$bytes;
        if($total>KICOM_MAX_PACKAGE_BYTES)return ['ok'=>false,'code'=>'PACKAGE_TOO_LARGE'];
        $out[]=['workspace_path'=>$wp,'dest_path'=>$dest,'workspace_sha256'=>hash('sha256',$content),'bytes'=>$bytes,'validation'=>$v['message']];
    }
    return ['ok'=>true,'manifest_path'=>$manifestPath,'manifest_sha256'=>hash('sha256',$raw),'package_name'=>$name,'files'=>$out,'files_count'=>count($out),'bytes'=>$total];
}
function kicomDeployPackageDryRun(string $alias,string $manifestPath): array {
    $t=kicomDeployTarget($alias,true);
    if($t===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];
    if((string)$t['class']==='production' && (string)$t['health_url']==='')return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_REQUIRED'];
    $m=kicomReadDeployPackageManifest($manifestPath);
    if(!$m['ok'])return $m;
    $backupTotal=0;$locked=[];
    foreach($m['files'] as $i=>$f){
        $tf=kicomTargetFile($alias,(string)$f['dest_path']);
        if($tf===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID','entry'=>$i+1];
        $full=(string)$tf['full'];
        if(is_file($full)){
            $sz=(int)filesize($full);
            if($sz>KICOM_MAX_DEPLOY_BACKUP_BYTES)return ['ok'=>false,'code'=>'TARGET_TOO_LARGE_TO_BACKUP','entry'=>$i+1];
            $backupTotal+=$sz;
            if($backupTotal>KICOM_MAX_PACKAGE_BACKUP_BYTES)return ['ok'=>false,'code'=>'PACKAGE_BACKUP_TOO_LARGE'];
        }
        $parent=dirname($full);
        $writable=is_dir($parent)?is_writable($parent):is_writable((string)$t['root']);
        if(!$writable)return ['ok'=>false,'code'=>'TARGET_NOT_WRITABLE','entry'=>$i+1];
        $locked[]=$f+['target_base_sha256'=>kicomTargetHash($alias,(string)$f['dest_path'])];
    }
    $health=kicomHealthCheck($alias);
    if((string)$t['class']==='production'&&(!$health['configured']||!$health['ok']))return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_FAILED','health'=>$health];
    return array_merge($m,[
        'target'=>$t['alias'],'target_class'=>$t['class'],'files'=>$locked,'backup_bytes'=>$backupTotal,
        'healthcheck_configured'=>$t['health_url']!=='','healthcheck_preflight_ok'=>$health['ok'],
        'healthcheck_http_code'=>$health['http_code'],
    ]);
}
function kicomCreateDeployPackageProposal(string $alias,string $manifestPath,string $manifestSha,string $transport): array {
    $d=kicomDeployPackageDryRun($alias,$manifestPath);
    if(!$d['ok'])return $d;
    $manifestSha=strtolower(trim($manifestSha));
    if(!preg_match('/^[a-f0-9]{64}$/',$manifestSha))return ['ok'=>false,'code'=>'INVALID_MANIFEST_SHA256'];
    if(!hash_equals((string)$d['manifest_sha256'],$manifestSha))return ['ok'=>false,'code'=>'MANIFEST_CONFLICT','current_manifest_sha256'=>$d['manifest_sha256']];
    $pr=kicomCreateProposal([
        'kind'=>'deploy_package_write','target'=>$d['target'],'target_class'=>$d['target_class'],
        'manifest_path'=>$d['manifest_path'],'manifest_sha256'=>$manifestSha,'package_name'=>$d['package_name'],
        'files'=>$d['files'],'files_count'=>$d['files_count'],'bytes'=>$d['bytes'],'sha256'=>$manifestSha,
        'healthcheck_preflight_ok'=>$d['healthcheck_preflight_ok'],'healthcheck_http_code'=>$d['healthcheck_http_code'],
        'transport'=>$transport
    ]);
    return $pr['ok']?['ok'=>true,'proposal_id'=>$pr['id']]+$d:$pr;
}
function kicomCheckDeployPackageProposal(array $p): array {
    $alias=(string)($p['target']??'');
    $target=kicomDeployTarget($alias,true);
    if($target===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];
    $manifestPath=kicomPackageManifestPath((string)($p['manifest_path']??''));
    if($manifestPath===null)return ['ok'=>false,'code'=>'INVALID_MANIFEST_PATH'];
    $m=kicomReadDeployPackageManifest($manifestPath);
    if(!$m['ok'])return $m;
    if(!hash_equals((string)$m['manifest_sha256'],(string)($p['manifest_sha256']??'')))return ['ok'=>false,'code'=>'MANIFEST_CONFLICT','current_manifest_sha256'=>$m['manifest_sha256']];
    $locked=$p['files']??null;
    if(!is_array($locked)||count($locked)!==count($m['files']))return ['ok'=>false,'code'=>'PACKAGE_LOCK_INVALID'];
    foreach($locked as $i=>$lf){
        if(!is_array($lf)||!isset($m['files'][$i]))return ['ok'=>false,'code'=>'PACKAGE_LOCK_INVALID'];
        $mf=$m['files'][$i];
        foreach(['workspace_path','dest_path','workspace_sha256'] as $k){
            if((string)($lf[$k]??'')!==(string)$mf[$k])return ['ok'=>false,'code'=>'PACKAGE_MANIFEST_DRIFT','entry'=>$i+1];
        }
        $curW=kicomReadStage((string)$lf['workspace_path']);
        if($curW===null)return ['ok'=>false,'code'=>'WORKSPACE_FILE_NOT_FOUND','entry'=>$i+1];
        $curWsha=hash('sha256',$curW);
        if(!hash_equals($curWsha,(string)$lf['workspace_sha256']))return ['ok'=>false,'code'=>'WORKSPACE_CONFLICT','entry'=>$i+1,'current_workspace_sha256'=>$curWsha];
        $curT=kicomTargetHash($alias,(string)$lf['dest_path']);
        if($curT===''||!hash_equals($curT,(string)($lf['target_base_sha256']??'')))return ['ok'=>false,'code'=>'TARGET_CONFLICT','entry'=>$i+1,'current_target_sha256'=>$curT];
    }
    return ['ok'=>true,'target'=>$target,'manifest'=>$m,'files'=>$locked];
}
function kicomPackageBackupEntry(string $alias,array $f): array {
    $tf=kicomTargetFile($alias,(string)$f['dest_path']);
    if($tf===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID'];
    $full=(string)$tf['full'];
    if(!is_file($full))return ['ok'=>true,'dest_path'=>$f['dest_path'],'before_sha256'=>'NEW','backup_content_b64'=>''];
    $raw=@file_get_contents($full);
    if($raw===false)return ['ok'=>false,'code'=>'BACKUP_READ_FAILED'];
    if(strlen($raw)>KICOM_MAX_DEPLOY_BACKUP_BYTES)return ['ok'=>false,'code'=>'TARGET_TOO_LARGE_TO_BACKUP'];
    return ['ok'=>true,'dest_path'=>$f['dest_path'],'before_sha256'=>hash('sha256',$raw),'backup_content_b64'=>base64_encode($raw)];
}
function kicomPreparePackageTemp(string $alias,string $dest,string $content,string $tx): array {
    $tf=kicomTargetFile($alias,$dest);
    if($tf===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID'];
    $full=(string)$tf['full'];$dir=dirname($full);
    if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))return ['ok'=>false,'code'=>'TARGET_DIR_FAILED'];
    $tmp=$full.'.kicom-package-'.$tx;
    if(@file_put_contents($tmp,$content,LOCK_EX)===false)return ['ok'=>false,'code'=>'TARGET_WRITE_FAILED'];
    @chmod($tmp,0644);
    return ['ok'=>true,'tmp'=>$tmp,'full'=>$full];
}
function kicomRestorePackageState(string $alias,array $entries): array {
    /* Verify every destination first and snapshot the state we would have to restore
       if the restoration itself fails halfway through. */
    $snapshots=[];
    foreach($entries as $i=>$e){
        $dest=(string)$e['dest_path'];
        $cur=kicomTargetHash($alias,$dest);
        if($cur===''||!hash_equals($cur,(string)$e['expected_current']))return ['ok'=>false,'code'=>'TARGET_CONFLICT','entry'=>$i+1,'current_target_sha256'=>$cur];
        $tf=kicomTargetFile($alias,$dest);
        if($tf===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID','entry'=>$i+1];
        if($cur==='NEW')$snapshots[$i]=['dest_path'=>$dest,'sha256'=>'NEW','content_b64'=>''];
        else{
            $raw=@file_get_contents((string)$tf['full']);
            if($raw===false||hash('sha256',(string)$raw)!==$cur)return ['ok'=>false,'code'=>'CURRENT_STATE_READ_FAILED','entry'=>$i+1];
            $snapshots[$i]=['dest_path'=>$dest,'sha256'=>$cur,'content_b64'=>base64_encode((string)$raw)];
        }
    }
    $changed=[];$out=[];
    foreach(array_reverse($entries,true) as $i=>$e){
        $dest=(string)$e['dest_path'];$before=(string)$e['before_sha256'];$tf=kicomTargetFile($alias,$dest);
        $fail=null;$after='';
        if($tf===null)$fail=['ok'=>false,'code'=>'TARGET_PATH_INVALID','entry'=>$i+1];
        elseif($before==='NEW'){
            if(is_file((string)$tf['full'])&&!@unlink((string)$tf['full']))$fail=['ok'=>false,'code'=>'ROLLBACK_DELETE_FAILED','entry'=>$i+1];
            else $after='NEW';
        }else{
            $raw=base64_decode((string)$e['backup_content_b64'],true);
            if($raw===false||hash('sha256',$raw)!==$before)$fail=['ok'=>false,'code'=>'BACKUP_CORRUPT','entry'=>$i+1];
            else{
                $w=kicomWriteDeployTarget($alias,$dest,$raw);
                if(!$w['ok'])$fail=$w+['entry'=>$i+1];else $after=$w['sha256'];
            }
        }
        if($fail!==null){
            $recoveryOk=true;
            foreach(array_reverse($changed,true) as $j){
                $snap=$snapshots[$j];$rt=kicomTargetFile($alias,(string)$snap['dest_path']);
                if($rt===null){$recoveryOk=false;continue;}
                if($snap['sha256']==='NEW'){
                    if(is_file((string)$rt['full'])&&!@unlink((string)$rt['full']))$recoveryOk=false;
                }else{
                    $raw=base64_decode((string)$snap['content_b64'],true);
                    if($raw===false){$recoveryOk=false;continue;}
                    $w=kicomWriteDeployTarget($alias,(string)$snap['dest_path'],$raw);
                    if(!$w['ok'])$recoveryOk=false;
                }
            }
            $fail['restoration_recovery_ok']=$recoveryOk;
            return $fail;
        }
        $changed[]=$i;$out[]=['dest_path'=>$dest,'sha256'=>$after];
    }
    return ['ok'=>true,'files'=>$out];
}
function kicomApplyDeployPackageWrite(array $p): array {
    $chk=kicomCheckDeployPackageProposal($p);
    if(!$chk['ok'])return $chk;
    $alias=(string)$p['target'];$target=$chk['target'];
    if((string)$target['class']==='production'){
        $pre=kicomHealthCheck($alias);
        if(!$pre['configured']||!$pre['ok'])return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_FAILED','health'=>$pre];
    }
    $backups=[];$temps=[];$tx=strtolower(kicomRequestId());
    foreach($p['files'] as $i=>$f){
        $b=kicomPackageBackupEntry($alias,$f);
        if(!$b['ok'])return $b+['entry'=>$i+1];
        $backups[$i]=$b;
        $content=kicomReadStage((string)$f['workspace_path']);
        if($content===null)return ['ok'=>false,'code'=>'WORKSPACE_FILE_NOT_FOUND','entry'=>$i+1];
        $tmp=kicomPreparePackageTemp($alias,(string)$f['dest_path'],$content,$tx.'-'.$i);
        if(!$tmp['ok']){foreach($temps as $t)@unlink((string)$t['tmp']);return $tmp+['entry'=>$i+1];}
        $temps[$i]=$tmp;
    }
    $committed=[];$histFiles=[];
    foreach($p['files'] as $i=>$f){
        $tmp=$temps[$i];
        if(!@rename((string)$tmp['tmp'],(string)$tmp['full'])){
            foreach($temps as $j=>$t)if(!in_array($j,$committed,true))@unlink((string)$t['tmp']);
            $restore=[];
            foreach($committed as $j)$restore[]=$backups[$j]+['expected_current'=>(string)$p['files'][$j]['workspace_sha256']];
            $rr=$restore?kicomRestorePackageState($alias,$restore):['ok'=>true];
            $id=kicomRecordDeployment([
                'action'=>'package_deploy','target'=>$alias,'target_class'=>$target['class'],'package_name'=>$p['package_name'],
                'manifest_path'=>$p['manifest_path'],'manifest_sha256'=>$p['manifest_sha256'],'files_count'=>count($p['files']),
                'files'=>$histFiles,'status'=>$rr['ok']?'rolled_back_write_failure':'write_failure_rollback_failed','rollback_result'=>$rr
            ]);
            return ['ok'=>false,'code'=>$rr['ok']?'PACKAGE_WRITE_FAILED_ROLLED_BACK':'PACKAGE_WRITE_FAILED_ROLLBACK_FAILED','deployment_id'=>$id??'','rolled_back'=>$rr['ok']];
        }
        @chmod((string)$tmp['full'],0640);
        $committed[]=$i;$b=$backups[$i];
        $histFiles[]=[
            'workspace_path'=>$f['workspace_path'],'dest_path'=>$f['dest_path'],'workspace_sha256'=>$f['workspace_sha256'],
            'before_sha256'=>$b['before_sha256'],'after_sha256'=>$f['workspace_sha256'],'backup_content_b64'=>$b['backup_content_b64']
        ];
    }
    $health=kicomHealthCheck($alias);
    $hist=[
        'action'=>'package_deploy','target'=>$alias,'target_class'=>$target['class'],'package_name'=>$p['package_name'],
        'manifest_path'=>$p['manifest_path'],'manifest_sha256'=>$p['manifest_sha256'],'files_count'=>count($histFiles),
        'files'=>$histFiles,'health'=>$health,'status'=>$health['ok']?'deployed':'health_failed'
    ];
    if(!$health['ok']){
        $restore=[];
        foreach($histFiles as $f)$restore[]=[
            'dest_path'=>$f['dest_path'],'before_sha256'=>$f['before_sha256'],'backup_content_b64'=>$f['backup_content_b64'],
            'expected_current'=>$f['after_sha256']
        ];
        $rr=kicomRestorePackageState($alias,$restore);
        $hist['status']=$rr['ok']?'rolled_back_healthcheck':'health_failed_rollback_failed';
        $hist['rollback_result']=$rr;
        $id=kicomRecordDeployment($hist);
        return ['ok'=>false,'code'=>$rr['ok']?'HEALTHCHECK_FAILED_ROLLED_BACK':'HEALTHCHECK_FAILED_ROLLBACK_FAILED','deployment_id'=>$id??'','rolled_back'=>$rr['ok'],'health'=>$health];
    }
    $id=kicomRecordDeployment($hist);
    return ['ok'=>true,'deployment_id'=>$id??'','manifest_sha256'=>$p['manifest_sha256'],'files_count'=>count($histFiles),'health'=>$health];
}
function kicomCreateDeployPackageRollbackProposal(string $deploymentId,string $transport): array {
    $h=kicomDeployHistoryGet($deploymentId);
    if($h===null||($h['action']??'')!=='package_deploy'||($h['status']??'')!=='deployed')return ['ok'=>false,'code'=>'PACKAGE_DEPLOYMENT_NOT_FOUND'];
    $target=kicomDeployTarget((string)$h['target'],true);
    if($target===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];
    $files=$h['files']??null;
    if(!is_array($files)||!$files)return ['ok'=>false,'code'=>'PACKAGE_HISTORY_INVALID'];
    foreach($files as $i=>$f){
        $cur=kicomTargetHash((string)$h['target'],(string)$f['dest_path']);
        if($cur===''||!hash_equals($cur,(string)$f['after_sha256']))return ['ok'=>false,'code'=>'TARGET_CONFLICT','entry'=>$i+1,'current_target_sha256'=>$cur];
    }
    $pr=kicomCreateProposal([
        'kind'=>'deploy_package_rollback','target'=>$h['target'],'target_class'=>$target['class'],
        'source_deployment'=>$deploymentId,'package_name'=>$h['package_name']??'package',
        'manifest_sha256'=>$h['manifest_sha256']??'','files_count'=>count($files),'files'=>$files,
        'bytes'=>0,'sha256'=>(string)($h['manifest_sha256']??''),'transport'=>$transport
    ]);
    return $pr['ok']?[
        'ok'=>true,'proposal_id'=>$pr['id'],'target'=>$h['target'],'target_class'=>$target['class'],
        'source_deployment'=>$deploymentId,'package_name'=>$h['package_name']??'package','files_count'=>count($files)
    ]:$pr;
}
function kicomApplyDeployPackageRollback(array $p): array {
    $source=kicomDeployHistoryGet((string)($p['source_deployment']??''));
    if($source===null||($source['action']??'')!=='package_deploy')return ['ok'=>false,'code'=>'PACKAGE_DEPLOYMENT_NOT_FOUND'];
    $alias=(string)$source['target'];$target=kicomDeployTarget($alias,true);
    if($target===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];
    if((string)$target['class']==='production'){
        $pre=kicomHealthCheck($alias);
        if(!$pre['configured']||!$pre['ok'])return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_FAILED','health'=>$pre];
    }
    $files=$source['files']??null;
    if(!is_array($files)||!$files)return ['ok'=>false,'code'=>'PACKAGE_HISTORY_INVALID'];
    $restore=[];$currentBackups=[];
    foreach($files as $i=>$f){
        $cur=kicomTargetHash($alias,(string)$f['dest_path']);
        if($cur===''||!hash_equals($cur,(string)$f['after_sha256']))return ['ok'=>false,'code'=>'TARGET_CONFLICT','entry'=>$i+1,'current_target_sha256'=>$cur];
        $tf=kicomTargetFile($alias,(string)$f['dest_path']);
        $raw=($tf!==null&&is_file((string)$tf['full']))?@file_get_contents((string)$tf['full']):false;
        $currentBackups[]=['dest_path'=>$f['dest_path'],'before_sha256'=>$cur,'backup_content_b64'=>$raw===false?'':base64_encode((string)$raw),'expected_current'=>(string)$f['before_sha256']];
        $restore[]=['dest_path'=>$f['dest_path'],'before_sha256'=>$f['before_sha256'],'backup_content_b64'=>$f['backup_content_b64'],'expected_current'=>$cur];
    }
    $rr=kicomRestorePackageState($alias,$restore);
    if(!$rr['ok'])return $rr;
    $health=kicomHealthCheck($alias);
    if(!$health['ok']){
        $reapply=kicomRestorePackageState($alias,$currentBackups);
        $id=kicomRecordDeployment([
            'action'=>'package_rollback','target'=>$alias,'target_class'=>$target['class'],'source_deployment'=>$source['id'],
            'package_name'=>$source['package_name']??'package','manifest_sha256'=>$source['manifest_sha256']??'',
            'files_count'=>count($files),'status'=>$reapply['ok']?'rollback_health_failed_reapplied':'rollback_health_failed_reapply_failed',
            'health'=>$health,'rollback_result'=>$rr,'reapply_result'=>$reapply
        ]);
        return ['ok'=>false,'code'=>$reapply['ok']?'ROLLBACK_HEALTHCHECK_FAILED_REAPPLIED':'ROLLBACK_HEALTHCHECK_FAILED_REAPPLY_FAILED','deployment_id'=>$id??'','rolled_back'=>$reapply['ok']];
    }
    $histFiles=[];
    foreach($files as $f)$histFiles[]=['dest_path'=>$f['dest_path'],'before_sha256'=>$f['after_sha256'],'after_sha256'=>$f['before_sha256']];
    $id=kicomRecordDeployment([
        'action'=>'package_rollback','target'=>$alias,'target_class'=>$target['class'],'source_deployment'=>$source['id'],
        'package_name'=>$source['package_name']??'package','manifest_sha256'=>$source['manifest_sha256']??'',
        'files_count'=>count($files),'files'=>$histFiles,'status'=>'rolled_back','health'=>$health
    ]);
    return ['ok'=>true,'deployment_id'=>$id??'','files_count'=>count($files),'health'=>$health];
}
function kicomDeployPackageProposalConflict(array $p): bool {
    if((string)($p['kind']??'')==='deploy_package_write')return !kicomCheckDeployPackageProposal($p)['ok'];
    if((string)($p['kind']??'')==='deploy_package_rollback'){
        $h=kicomDeployHistoryGet((string)($p['source_deployment']??''));
        if($h===null||!is_array($h['files']??null))return true;
        foreach($h['files'] as $f){
            if(kicomTargetHash((string)$h['target'],(string)$f['dest_path'])!==(string)$f['after_sha256'])return true;
        }
        return false;
    }
    return false;
}


/* ---- KiCom 0.9.2 human control plane + redundant update channels -------- */
function kicomRandomSecret(int $bytes=24): string {
    try { return bin2hex(random_bytes($bytes)); }
    catch(Throwable $e){ return hash('sha256',uniqid('',true).'|'.microtime(true)); }
}
function kicomUpdateChannelsDefaults(): array {
    return [
        'schema'=>1,
        'auto_install_green'=>true,
        'pull'=>[
            'enabled'=>true,
            'feeds'=>[
                ['name'=>'primary','enabled'=>true,'url'=>'https://update.rurtalbahn.info/kicom/channel.json'],
                ['name'=>'mirror','enabled'=>false,'url'=>'']
            ]
        ],
        'push'=>[
            'enabled'=>true,
            'key_plain'=>kicomRandomSecret(24),
            'created_at'=>gmdate('c')
        ],
        'agent'=>[
            'key_plain'=>kicomRandomSecret(24),
            'created_at'=>gmdate('c')
        ]
    ];
}
function kicomUpdateChannelsLoad(): array {
    $f=kicomUpdateChannelsFile();
    if(!is_file($f)){
        $cfg=kicomUpdateChannelsDefaults();
        $j=json_encode($cfg,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        if($j!==false){@file_put_contents($f,$j."\n",LOCK_EX);@chmod($f,0600);}
        return $cfg;
    }
    $cfg=json_decode((string)@file_get_contents($f),true);
    if(!is_array($cfg))$cfg=kicomUpdateChannelsDefaults();
    $cfg['schema']=1;
    $cfg['auto_install_green']=array_key_exists('auto_install_green',$cfg)?(bool)$cfg['auto_install_green']:true;
    if(!is_array($cfg['pull']??null))$cfg['pull']=['enabled'=>true,'feeds'=>[]];
    if(!is_array($cfg['pull']['feeds']??null))$cfg['pull']['feeds']=[];
    if(!is_array($cfg['push']??null))$cfg['push']=['enabled'=>true];
    if(!is_string($cfg['push']['key_plain']??null)||strlen((string)$cfg['push']['key_plain'])<32)$cfg['push']['key_plain']=kicomRandomSecret(24);
    if(!is_array($cfg['agent']??null))$cfg['agent']=[];
    if(!is_string($cfg['agent']['key_plain']??null)||strlen((string)$cfg['agent']['key_plain'])<32)$cfg['agent']['key_plain']=kicomRandomSecret(24);
    return $cfg;
}
function kicomUpdateChannelsSave(array $cfg): bool {
    $feeds=[];
    foreach(($cfg['pull']['feeds']??[]) as $f){
        if(!is_array($f))continue;
        $name=preg_replace('/[^a-z0-9_-]/i','',(string)($f['name']??'feed'))?:'feed';
        $url=trim((string)($f['url']??''));
        $enabled=!empty($f['enabled']);
        if($url!==''&&!kicomUpdateFeedUrlAllowed($url))return false;
        $feeds[]=['name'=>substr($name,0,32),'enabled'=>$enabled,'url'=>$url];
        if(count($feeds)>=4)break;
    }
    $current=kicomUpdateChannelsLoad();
    $clean=[
        'schema'=>1,
        'auto_install_green'=>(bool)($cfg['auto_install_green']??false),
        'pull'=>['enabled'=>(bool)($cfg['pull']['enabled']??false),'feeds'=>$feeds],
        'push'=>[
            'enabled'=>(bool)($cfg['push']['enabled']??false),
            'key_plain'=>(string)($cfg['push']['key_plain']??$current['push']['key_plain']),
            'created_at'=>(string)($current['push']['created_at']??gmdate('c'))
        ],
        'agent'=>[
            'key_plain'=>(string)($cfg['agent']['key_plain']??$current['agent']['key_plain']),
            'created_at'=>(string)($current['agent']['created_at']??gmdate('c'))
        ]
    ];
    $j=json_encode($clean,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    return $j!==false&&@file_put_contents(kicomUpdateChannelsFile(),$j."\n",LOCK_EX)!==false&&@chmod(kicomUpdateChannelsFile(),0600);
}
function kicomUpdateFeedUrlAllowed(string $url): bool {
    $p=parse_url($url);
    if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https')return false;
    $host=strtolower((string)($p['host']??''));
    if($host===''||isset($p['user'])||isset($p['pass']))return false;
    if(filter_var($host,FILTER_VALIDATE_IP))return false;
    $port=(int)($p['port']??443);if($port!==443)return false;
    return true;
}
function kicomUpdatePackageUrlAllowed(string $url,string $feedUrl): bool {
    if(!kicomUpdateFeedUrlAllowed($url))return false;
    $uh=strtolower((string)parse_url($url,PHP_URL_HOST));
    $fh=strtolower((string)parse_url($feedUrl,PHP_URL_HOST));
    return $uh!==''&&hash_equals($fh,$uh);
}
function kicomUpdateChannelState(): array {
    $r=is_file(kicomUpdateChannelStateFile())?json_decode((string)@file_get_contents(kicomUpdateChannelStateFile()),true):[];
    return is_array($r)?$r:[];
}
function kicomUpdateChannelStateWrite(array $r): void {
    $j=json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($j!==false){@file_put_contents(kicomUpdateChannelStateFile(),$j."\n",LOCK_EX);@chmod(kicomUpdateChannelStateFile(),0600);}
}
function kicomUpdateChannelsPublicStatus(): array {
    $cfg=kicomUpdateChannelsLoad();$state=kicomUpdateChannelState();$pending=kicomSelfUpdatePending();
    $checks=[];
    foreach(($state['feed_checks']??[]) as $c)if(is_array($c))$checks[(string)($c['name']??'feed')]=$c;
    $feeds=[];foreach(($cfg['pull']['feeds']??[]) as $f){
        $name=(string)($f['name']??'feed');$diag=$checks[$name]??[];
        $feeds[]=[
            'name'=>$name,
            'enabled'=>(bool)($f['enabled']??false),
            'configured'=>trim((string)($f['url']??''))!=='',
            'host'=>trim((string)($f['url']??''))!==''?(string)parse_url((string)$f['url'],PHP_URL_HOST):'',
            'checked_at'=>(string)($diag['checked_at']??''),
            'ok'=>(bool)($diag['ok']??false),
            'code'=>(string)($diag['code']??'not_checked'),
            'http_code'=>(int)($diag['http_code']??0),
            'json_valid'=>(bool)($diag['json_valid']??false),
            'releases'=>(int)($diag['releases']??0)
        ];
    }
    return [
        'auto_install_green'=>(bool)$cfg['auto_install_green'],
        'pull_enabled'=>(bool)($cfg['pull']['enabled']??false),
        'push_enabled'=>(bool)($cfg['push']['enabled']??false),
        'feeds'=>$feeds,
        'last_check_at'=>(string)($state['last_check_at']??''),
        'last_code'=>(string)($state['last_code']??'never'),
        'last_source'=>(string)($state['last_source']??''),
        'pending_version'=>(string)($pending['to_version']??''),
        'pending_risk'=>(string)($pending['risk_class']??''),
        'pending_source'=>(string)($pending['source']??'')
    ];
}
function kicomUpdatePushKey(): string { $c=kicomUpdateChannelsLoad();return (string)($c['push']['key_plain']??''); }
function kicomUpdateAgentKey(): string { $c=kicomUpdateChannelsLoad();return (string)($c['agent']['key_plain']??''); }
function kicomUpdateRotatePushKey(): string {
    $c=kicomUpdateChannelsLoad();$c['push']['key_plain']=kicomRandomSecret(24);$c['push']['created_at']=gmdate('c');kicomUpdateChannelsSave($c);return (string)$c['push']['key_plain'];
}
function kicomUpdateRotateAgentKey(): string {
    $c=kicomUpdateChannelsLoad();$c['agent']['key_plain']=kicomRandomSecret(24);$c['agent']['created_at']=gmdate('c');kicomUpdateChannelsSave($c);return (string)$c['agent']['key_plain'];
}
function kicomUpdatePushAuth(string $provided): bool {
    $cfg=kicomUpdateChannelsLoad();if(empty($cfg['push']['enabled']))return false;
    $key=(string)($cfg['push']['key_plain']??'');return $key!==''&&$provided!==''&&hash_equals($key,$provided);
}
function kicomUpdateAgentAuth(string $provided): bool {
    $key=kicomUpdateAgentKey();return $key!==''&&$provided!==''&&hash_equals($key,$provided);
}
function kicomUpdatePushRateAllowed(string $fingerprint): bool {
    $now=time();$f=kicomUpdatePushRateFile();$r=is_file($f)?json_decode((string)@file_get_contents($f),true):[];
    if(!is_array($r))$r=[];$bucket=(int)floor($now/60);$key=hash('sha256',$fingerprint);
    $row=$r[$key]??['bucket'=>$bucket,'count'=>0];
    if((int)($row['bucket']??-1)!==$bucket)$row=['bucket'=>$bucket,'count'=>0];
    $row['count']=(int)$row['count']+1;$r=[$key=>$row];
    $j=json_encode($r);if($j!==false){@file_put_contents($f,$j,LOCK_EX);@chmod($f,0600);}
    return (int)$row['count']<=KICOM_UPDATE_PUSH_MAX_ATTEMPTS_PER_MINUTE;
}
function kicomUpdateHttpGet(string $url,int $maxBytes): array {
    if(!kicomUpdateFeedUrlAllowed($url))return ['ok'=>false,'code'=>'URL_NOT_ALLOWED'];
    if(function_exists('curl_init')){
        $buf='';$tooLarge=false;$ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>KICOM_HEALTH_TIMEOUT,CURLOPT_TIMEOUT=>max(8,KICOM_HEALTH_TIMEOUT),
            CURLOPT_USERAGENT=>'KiCom-UpdateChannel/'.KICOM_VERSION,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION=>function($ch,$data)use(&$buf,&$tooLarge,$maxBytes){
                if(strlen($buf)+strlen($data)>$maxBytes){$tooLarge=true;return 0;}
                $buf.=$data;return strlen($data);
            }
        ]);
        $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=(string)curl_error($ch);curl_close($ch);
        if($tooLarge)return ['ok'=>false,'code'=>'REMOTE_TOO_LARGE'];
        if($ok===false||$code<200||$code>=300)return ['ok'=>false,'code'=>'REMOTE_HTTP_FAILED','http_code'=>$code,'error'=>$err];
        return ['ok'=>true,'body'=>$buf,'http_code'=>$code];
    }
    $ctx=stream_context_create(['http'=>['timeout'=>max(8,KICOM_HEALTH_TIMEOUT),'follow_location'=>0,'user_agent'=>'KiCom-UpdateChannel/'.KICOM_VERSION]]);
    $body=@file_get_contents($url,false,$ctx,0,$maxBytes+1);
    if($body===false)return ['ok'=>false,'code'=>'REMOTE_HTTP_FAILED'];
    if(strlen($body)>$maxBytes)return ['ok'=>false,'code'=>'REMOTE_TOO_LARGE'];
    return ['ok'=>true,'body'=>$body,'http_code'=>200];
}
function kicomUpdateDownloadPackage(string $url,string $feedUrl,string $expectedSha): array {
    if(!kicomUpdatePackageUrlAllowed($url,$feedUrl))return ['ok'=>false,'code'=>'PACKAGE_URL_NOT_ALLOWED'];
    if(!preg_match('/^[a-f0-9]{64}$/',$expectedSha))return ['ok'=>false,'code'=>'PACKAGE_SHA_INVALID'];
    $r=kicomUpdateHttpGet($url,KICOM_MAX_SELF_UPDATE_ZIP_BYTES);
    if(!$r['ok'])return $r;
    $body=(string)$r['body'];$sha=hash('sha256',$body);if(!hash_equals($expectedSha,$sha))return ['ok'=>false,'code'=>'PACKAGE_SHA_MISMATCH'];
    $tmp=kicomTempDir().'/pull-'.substr($sha,0,20).'.zip';
    if(@file_put_contents($tmp,$body,LOCK_EX)===false)return ['ok'=>false,'code'=>'PACKAGE_TEMP_WRITE_FAILED'];
    @chmod($tmp,0600);return ['ok'=>true,'path'=>$tmp,'sha256'=>$sha,'bytes'=>strlen($body)];
}
function kicomUpdateFeedParse(string $raw,string $feedUrl): array {
    $j=json_decode($raw,true);if(!is_array($j)||(int)($j['schema']??0)!==1||strtolower((string)($j['product']??''))!=='kicom')return ['ok'=>false,'code'=>'FEED_INVALID'];
    $rows=[];
    $releases=$j['releases']??(isset($j['latest'])?[$j['latest']]:[]);
    if(!is_array($releases))return ['ok'=>false,'code'=>'FEED_RELEASES_INVALID'];
    foreach($releases as $x){
        if(!is_array($x))continue;$v=(string)($x['version']??'');$u=(string)($x['url']??'');$s=strtolower((string)($x['sha256']??''));
        if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',$v)||!preg_match('/^[a-f0-9]{64}$/',$s)||!kicomUpdatePackageUrlAllowed($u,$feedUrl))continue;
        if(version_compare($v,KICOM_VERSION,'>'))$rows[]=['version'=>$v,'url'=>$u,'sha256'=>$s,'published_at'=>(string)($x['published_at']??'')];
    }
    usort($rows,fn($a,$b)=>version_compare((string)$b['version'],(string)$a['version']));
    return ['ok'=>true,'releases'=>$rows];
}
/* ---- end channel primitives --------------------------------------------- */

/* ---- KiCom self-update controller (0.8) ---------------------------------- */
function kicomLoadTrustedModules(): void {
    $manifest=kicomBaseDir().'/genome/modules.json';
    if(!is_file($manifest))return;
    $raw=@file_get_contents($manifest);if($raw===false||strlen($raw)>65536)return;
    try{$cfg=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){return;}
    if(!is_array($cfg)||(int)($cfg['schema']??0)!==1||!is_array($cfg['modules']??null))return;
    $genome=kicomGenomeCurrent();if(!is_array($genome)||!is_array($genome['components']??null))return;
    $components=[];foreach($genome['components'] as $c){if(!is_array($c))continue;$p=(string)($c['path']??'');$sha=strtolower((string)($c['sha256']??''));if($p!==''&&preg_match('/^[a-f0-9]{64}$/',$sha))$components[$p]=$sha;}
    $moduleRoot=realpath(kicomBaseDir().'/modules');if($moduleRoot===false)return;$moduleRoot=rtrim(str_replace('\\','/',$moduleRoot),'/').'/';
    foreach($cfg['modules'] as $row){
        if(!is_array($row)||array_key_exists('enabled',$row)&&!$row['enabled'])continue;
        $path=kicomSafeUpdatePath((string)($row['path']??''));
        if($path===null||!str_starts_with($path,'modules/')||strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='php'||!isset($components[$path]))continue;
        $full=kicomBaseDir().'/'.$path;$real=realpath($full);if($real===false||!is_file($real))continue;$norm=str_replace('\\','/',$real);if(!str_starts_with($norm,$moduleRoot))continue;
        $actual=hash_file('sha256',$real)?:'';if($actual===''||!hash_equals((string)$components[$path],$actual))continue;
        require_once $real;
    }
}


/* ---- KiCom bounded Slack channel (0.9.22) ------------------------------ */
function kicomSlackConfig(): array {
    return [
        'team_id'=>'T0C2WFYUM7T',
        'workspace'=>'KIcom',
        'channel_id'=>'C0C2Y7VNSDA',
        'channel_name'=>'kicom',
        'events_url'=>'https://kicom.rurtalbahn.info/?q=SLACK_EVENTS'
    ];
}
function kicomSlackRoot(): string { return kicomBaseDir().'/var/slack'; }
function kicomSlackEnsure(): bool {
    $d=kicomSlackRoot();if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;@chmod($d,0700);
    $deny=$d.'/.htaccess';if(!is_file($deny)){@file_put_contents($deny,kicomDenyRules(),LOCK_EX);@chmod($deny,0600);}return true;
}
function kicomSlackSecretFile(string $kind): ?string {
    return $kind==='bot'?kicomBaseDir().'/var/secrets/slack-bot-token':($kind==='signing'?kicomBaseDir().'/var/secrets/slack-signing-secret':null);
}
function kicomSlackSecretRead(string $kind): ?string {
    $env=$kind==='bot'?'KICOM_SLACK_BOT_TOKEN':($kind==='signing'?'KICOM_SLACK_SIGNING_SECRET':'');
    if($env!==''){$v=getenv($env);if(is_string($v)&&$v!=='')return $v;}
    $f=kicomSlackSecretFile($kind);if($f===null||!is_file($f))return null;$v=@file_get_contents($f);if(!is_string($v))return null;$v=rtrim($v,"\r\n");return $v!==''?$v:null;
}
function kicomSlackSecretProvision(string $botToken,string $signingSecret): array {
    $botToken=trim($botToken);$signingSecret=strtolower(trim($signingSecret));
    if(!preg_match('/^xoxb-[A-Za-z0-9-]{20,500}$/',$botToken))return ['ok'=>false,'code'=>'SLACK_BOT_TOKEN_INVALID'];
    if(!preg_match('/^[a-f0-9]{32,128}$/',$signingSecret))return ['ok'=>false,'code'=>'SLACK_SIGNING_SECRET_INVALID'];
    $d=kicomBaseDir().'/var/secrets';if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return ['ok'=>false,'code'=>'SLACK_SECRET_DIR_FAILED'];@chmod($d,0700);
    foreach(['bot'=>$botToken,'signing'=>$signingSecret] as $kind=>$value){
        $f=kicomSlackSecretFile($kind);$tmp=$f.'.tmp-'.bin2hex(random_bytes(6));
        if(@file_put_contents($tmp,$value."\n",LOCK_EX)===false){@unlink($tmp);return ['ok'=>false,'code'=>'SLACK_SECRET_WRITE_FAILED'];}
        @chmod($tmp,0600);if(!@rename($tmp,$f)){@unlink($tmp);return ['ok'=>false,'code'=>'SLACK_SECRET_COMMIT_FAILED'];}@chmod($f,0600);
    }
    if(function_exists('kicomLivingEvent'))kicomLivingEvent('slack_secrets_provisioned','info',['team_id'=>'T0C2WFYUM7T','channel_id'=>'C0C2Y7VNSDA','secret_visible'=>false]);
    return ['ok'=>true,'code'=>'OK','secret_visible'=>false];
}
function kicomSlackStatus(): array {
    $c=kicomSlackConfig();$bot=kicomSlackSecretRead('bot')!==null;$sign=kicomSlackSecretRead('signing')!==null;
    return ['ok'=>true]+$c+['bot_token_configured'=>$bot,'signing_secret_configured'=>$sign,'configured'=>$bot&&$sign,'inbound'=>'APP_MENTION_ONLY','outbound'=>'ALLOWLISTED_CHANNEL_ONLY','trust'=>'UNTRUSTED_PERCEPTION_INPUT'];
}
function kicomSlackApi(string $method,array $payload=[]): array {
    if(!in_array($method,['auth.test','chat.postMessage'],true))return ['ok'=>false,'code'=>'SLACK_METHOD_FORBIDDEN'];
    $token=kicomSlackSecretRead('bot');if($token===null)return ['ok'=>false,'code'=>'SLACK_BOT_TOKEN_UNAVAILABLE'];
    $url='https://slack.com/api/'.$method;$json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false)return ['ok'=>false,'code'=>'SLACK_JSON_FAILED'];
    if(function_exists('curl_init')){
        $ch=curl_init($url);if($ch===false)return ['ok'=>false,'code'=>'SLACK_CURL_INIT_FAILED'];
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json; charset=utf-8','Accept: application/json','User-Agent: KiCom/'.KICOM_VERSION]]);
        $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=$raw===false?(string)curl_error($ch):'';curl_close($ch);
        if($raw===false)return ['ok'=>false,'code'=>'SLACK_HTTP_FAILED','http_code'=>$http,'error'=>$err];
    }else{
        $ctx=stream_context_create(['http'=>['method'=>'POST','timeout'=>20,'ignore_errors'=>true,'follow_location'=>0,'header'=>"Authorization: Bearer {$token}\r\nContent-Type: application/json; charset=utf-8\r\nAccept: application/json\r\nUser-Agent: KiCom/".KICOM_VERSION."\r\n",'content'=>$json],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>'slack.com','allow_self_signed'=>false]]);
        $raw=@file_get_contents($url,false,$ctx);if(!is_string($raw))return ['ok'=>false,'code'=>'SLACK_HTTP_FAILED'];
    }
    $r=json_decode((string)$raw,true);if(!is_array($r))return ['ok'=>false,'code'=>'SLACK_RESPONSE_INVALID'];
    if(empty($r['ok']))return ['ok'=>false,'code'=>'SLACK_API_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/','_',strval($r['error']??'ERROR')))];
    return ['ok'=>true,'code'=>'OK','response'=>$r];
}
function kicomSlackProbe(): array {
    $r=kicomSlackApi('auth.test');if(empty($r['ok']))return $r;$x=$r['response']??[];$c=kicomSlackConfig();
    if(!hash_equals((string)$c['team_id'],(string)($x['team_id']??'')))return ['ok'=>false,'code'=>'SLACK_TEAM_MISMATCH'];
    return ['ok'=>true,'code'=>'OK','team_id'=>(string)$x['team_id'],'bot_id'=>(string)($x['bot_id']??''),'user_id'=>(string)($x['user_id']??'')];
}
function kicomSlackRateFile(): string { return kicomSlackRoot().'/rate.json'; }
function kicomSlackRateCheck(): array {if(!kicomSlackEnsure())return ['ok'=>false,'code'=>'SLACK_STORAGE_FAILED'];$now=time();$r=kicomSqliteKvRead('slack','rate',kicomSlackRateFile(),['events'=>[]]);$events=is_array($r['events']??null)?$r['events']:[];$events=array_values(array_filter($events,fn($t)=>(int)$t>=$now-3600));if(count($events)>=20)return ['ok'=>false,'code'=>'SLACK_RATE_HOURLY'];return ['ok'=>true,'events'=>$events];}
function kicomSlackRateRecord(array $events): bool {$events[]=time();return kicomSqliteKvWrite('slack','rate',['schema'=>1,'events'=>$events,'updated_at'=>gmdate('c')],kicomSlackRateFile());}
function kicomSlackAudit(string $event,string $text,array $extra=[]): bool {
    if(!kicomSlackEnsure())return false;$row=['at'=>gmdate('c'),'event'=>$event,'text_sha256'=>hash('sha256',$text),'text_bytes'=>strlen($text)]+$extra;
    $j=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($j===false)return false;$f=kicomSlackRoot().'/audit-'.gmdate('Ym').'.jsonl';$ok=@file_put_contents($f,$j."\n",FILE_APPEND|LOCK_EX)!==false;if($ok)@chmod($f,0600);return $ok;
}
function kicomSlackSend(string $text): array {
    $text=trim($text);if($text===''||strlen($text)>4000)return ['ok'=>false,'code'=>'SLACK_TEXT_INVALID'];$rate=kicomSlackRateCheck();if(empty($rate['ok']))return $rate;
    $c=kicomSlackConfig();$r=kicomSlackApi('chat.postMessage',['channel'=>$c['channel_id'],'text'=>$text,'unfurl_links'=>false,'unfurl_media'=>false]);
    if(empty($r['ok'])){kicomSlackAudit('send_failed',$text,['code'=>(string)($r['code']??'UNKNOWN')]);return $r;}
    if(!kicomSlackRateRecord($rate['events']??[]))return ['ok'=>false,'code'=>'SLACK_RATE_RECORD_FAILED'];
    $x=$r['response']??[];kicomSlackAudit('sent',$text,['channel_id'=>$c['channel_id'],'ts'=>(string)($x['ts']??'')]);
    if(function_exists('kicomLivingEvent'))kicomLivingEvent('slack_outbound_sent','info',['channel_id'=>$c['channel_id'],'text_sha256'=>hash('sha256',$text),'text_bytes'=>strlen($text)]);
    return ['ok'=>true,'code'=>'SLACK_SENT','channel_id'=>$c['channel_id'],'ts'=>(string)($x['ts']??'')];
}
function kicomSlackStateFile(): string { return kicomSlackRoot().'/state.json'; }
function kicomSlackState(): array {if(!kicomSlackEnsure())return ['seen'=>[]];$r=kicomSqliteKvRead('slack','state',kicomSlackStateFile(),['seen'=>[]]);return is_array($r['seen']??null)?$r:['seen'=>[]];}
function kicomSlackStateWrite(array $r): bool {$r['seen']=array_slice(array_values(array_unique(array_map('strval',$r['seen']??[]))),-1000);$r['updated_at']=gmdate('c');return kicomSqliteKvWrite('slack','state',$r,kicomSlackStateFile());}
function kicomSlackVerifySignature(string $raw): bool {
    $secret=kicomSlackSecretRead('signing');if($secret===null)return false;$ts=(string)($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP']??'');$sig=(string)($_SERVER['HTTP_X_SLACK_SIGNATURE']??'');
    if(!preg_match('/^[0-9]{10,13}$/',$ts)||abs(time()-(int)$ts)>300||!preg_match('/^v0=[a-f0-9]{64}$/',$sig))return false;
    $expected='v0='.hash_hmac('sha256','v0:'.$ts.':'.$raw,$secret);return hash_equals($expected,$sig);
}
function kicomSlackEventAppend(array $row): bool {if(!kicomSlackEnsure())return false;return kicomSqliteEventAppend('slack',$row,kicomSlackRoot().'/events-'.gmdate('Ym').'.jsonl');}
function kicomSlackRecent(int $limit=20): array {$limit=max(1,min(50,$limit));$rows=kicomSqliteEvents('slack',$limit);if($rows)return $rows;$files=glob(kicomSlackRoot().'/events-*.jsonl')?:[];rsort($files,SORT_STRING);$rows=[];foreach(array_slice($files,0,2) as $f){foreach(array_reverse(file($f,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]) as $line){$r=json_decode($line,true);if(is_array($r))$rows[]=$r;if(count($rows)>=$limit)break 2;}}return $rows;}
function kicomSlackEventsHttp(): never {
    header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo '{"ok":false}';exit;}
    $raw=(string)file_get_contents('php://input');if($raw===''||strlen($raw)>1048576){http_response_code(400);echo '{"ok":false}';exit;}
    $j=json_decode($raw,true);if(!is_array($j)){http_response_code(400);echo '{"ok":false}';exit;}
    $secretConfigured=kicomSlackSecretRead('signing')!==null;
    if(!$secretConfigured&&($j['type']??'')==='url_verification'&&is_string($j['challenge']??null)){
        echo json_encode(['challenge'=>(string)$j['challenge']],JSON_UNESCAPED_SLASHES);exit;
    }
    if(!kicomSlackVerifySignature($raw)){http_response_code(401);echo '{"ok":false}';exit;}
    if(($j['type']??'')==='url_verification'&&is_string($j['challenge']??null)){echo json_encode(['challenge'=>(string)$j['challenge']],JSON_UNESCAPED_SLASHES);exit;}
    if(($j['type']??'')!=='event_callback'){echo '{"ok":true}';exit;}
    $c=kicomSlackConfig();if(!hash_equals($c['team_id'],(string)($j['team_id']??''))){http_response_code(403);echo '{"ok":false}';exit;}
    $id=(string)($j['event_id']??'');if($id===''||strlen($id)>180){http_response_code(400);echo '{"ok":false}';exit;}
    $state=kicomSlackState();if(in_array($id,$state['seen']??[],true)){echo '{"ok":true,"duplicate":true}';exit;}
    $e=is_array($j['event']??null)?$j['event']:[];$type=(string)($e['type']??'');
    if($type!=='app_mention'){echo '{"ok":true,"ignored":true}';exit;}
    if(!hash_equals($c['channel_id'],(string)($e['channel']??''))){echo '{"ok":true,"ignored":true}';exit;}
    if(isset($e['bot_id'])||($e['subtype']??'')==='bot_message'){echo '{"ok":true,"ignored":true}';exit;}
    $text=(string)($e['text']??'');if(strlen($text)>10000)$text=substr($text,0,10000);
    $row=['at'=>gmdate('c'),'event_id'=>$id,'type'=>'app_mention','trust'=>'UNTRUSTED','authority_granted'=>false,'team_id'=>$c['team_id'],'channel_id'=>$c['channel_id'],'user'=>(string)($e['user']??''),'ts'=>(string)($e['ts']??''),'thread_ts'=>(string)($e['thread_ts']??''),'text'=>$text,'text_sha256'=>hash('sha256',$text)];
    if(!kicomSlackEventAppend($row)){http_response_code(503);echo '{"ok":false}';exit;}
    $state['seen'][]=$id;kicomSlackStateWrite($state);
    if(function_exists('kicomLivingEvent'))kicomLivingEvent('slack_inbound_mention','info',['event_id'=>$id,'channel_id'=>$c['channel_id'],'text_sha256'=>$row['text_sha256'],'trust'=>'UNTRUSTED','authority_granted'=>false]);
    echo '{"ok":true}';exit;
}
function kicomSlackManifest(): array {
    $c=kicomSlackConfig();return [
      'display_information'=>['name'=>'KiCom','description'=>'Bounded Slack communication for KiCom','background_color'=>'#4A154B'],
      'features'=>['bot_user'=>['display_name'=>'KiCom','always_online'=>true]],
      'oauth_config'=>['scopes'=>['bot'=>['app_mentions:read','chat:write']]],
      'settings'=>[
        'event_subscriptions'=>['request_url'=>$c['events_url'],'bot_events'=>['app_mention']],
        'interactivity'=>['is_enabled'=>false],
        'org_deploy_enabled'=>false,
        'socket_mode_enabled'=>false,
        'token_rotation_enabled'=>false
      ]
    ];
}
/* ---- end bounded Slack channel ----------------------------------------- */

function kicomMailConfig(): array {
    return ['identity'=>'kicom@rurtalbahn.info','imap_host'=>'w021efff.kasserver.com','imap_port'=>993,'smtp_host'=>'w021efff.kasserver.com','smtp_port'=>465,'username'=>'kicom@rurtalbahn.info','secret_ref'=>'KICOM_MAIL_PASSWORD'];
}
function kicomMailSecretDir(): string { return kicomBaseDir().'/var/secrets'; }
function kicomMailSecretFile(): string { return kicomMailSecretDir().'/mail-password'; }
function kicomMailSecretConfigured(): bool {
    $env=getenv('KICOM_MAIL_PASSWORD');if(is_string($env)&&$env!=='')return true;$f=kicomMailSecretFile();return is_file($f)&&((int)@filesize($f)>0);
}
function kicomMailSecretRead(): ?string {
    $env=getenv('KICOM_MAIL_PASSWORD');if(is_string($env)&&$env!=='')return $env;$f=kicomMailSecretFile();if(!is_file($f))return null;$raw=@file_get_contents($f);if(!is_string($raw))return null;$raw=rtrim($raw,"\r\n");return $raw!==''?$raw:null;
}
function kicomMailSecretProvision(string $secret): array {
    if($secret===''||strlen($secret)>4096||str_contains($secret,"\0"))return ['ok'=>false,'code'=>'MAIL_SECRET_INVALID'];
    $d=kicomMailSecretDir();if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return ['ok'=>false,'code'=>'MAIL_SECRET_DIR_FAILED'];@chmod($d,0700);
    $f=kicomMailSecretFile();$tmp=$f.'.tmp-'.bin2hex(random_bytes(8));if(@file_put_contents($tmp,$secret."\n",LOCK_EX)===false)return ['ok'=>false,'code'=>'MAIL_SECRET_WRITE_FAILED'];@chmod($tmp,0600);
    if(!@rename($tmp,$f)){@unlink($tmp);return ['ok'=>false,'code'=>'MAIL_SECRET_COMMIT_FAILED'];}@chmod($f,0600);
    if(function_exists('kicomLivingEvent'))kicomLivingEvent('mail_secret_provisioned','info',['identity'=>'kicom@rurtalbahn.info','secret_visible'=>false]);
    return ['ok'=>true,'code'=>'OK','configured'=>true,'secret_visible'=>false];
}
function kicomMailStatus(): array {
    $c=kicomMailConfig();return ['ok'=>true,'identity'=>$c['identity'],'configured'=>kicomMailSecretConfigured(),'imap_host'=>$c['imap_host'],'imap_port'=>$c['imap_port'],'smtp_host'=>$c['smtp_host'],'smtp_port'=>$c['smtp_port'],'tls'=>true,'secret_visible'=>false];
}
function kicomMailTlsSocket(string $host,int $port) {
    if($host===''||$port<1||$port>65535)throw new RuntimeException('MAIL_ENDPOINT_INVALID');
    $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'allow_self_signed'=>false,'SNI_enabled'=>true,'disable_compression'=>true]]);
    $errno=0;$errstr='';$s=@stream_socket_client('ssl://'.$host.':'.$port,$errno,$errstr,15,STREAM_CLIENT_CONNECT,$ctx);if(!is_resource($s))throw new RuntimeException('MAIL_TLS_CONNECT_FAILED');stream_set_timeout($s,15);return $s;
}
function kicomMailWriteAll($s,string $data): void {
    $off=0;$len=strlen($data);while($off<$len){$n=@fwrite($s,substr($data,$off));if($n===false||$n===0)throw new RuntimeException('MAIL_SOCKET_WRITE_FAILED');$off+=$n;}
}
function kicomMailImapQuote(string $v): string {
    if(str_contains($v,"\r")||str_contains($v,"\n")||str_contains($v,"\0"))throw new RuntimeException('MAIL_IMAP_VALUE_INVALID');return '"'.addcslashes($v,"\\\"").'"';
}
function kicomMailReadImapTagged($s,string $tag): string {
    $buf='';while(!feof($s)&&strlen($buf)<1048576){$line=fgets($s,65536);if(!is_string($line))break;$buf.=$line;if(preg_match('/^'.preg_quote($tag,'/').'\s+(OK|NO|BAD)\b/i',$line))return $buf;}throw new RuntimeException('MAIL_IMAP_RESPONSE_INCOMPLETE');
}
function kicomMailSmtpRead($s): array {
    $lines=[];$code=0;while(!feof($s)&&count($lines)<100){$line=fgets($s,8192);if(!is_string($line))break;$lines[]=rtrim($line,"\r\n");if(preg_match('/^(\d{3})([ -])/',$line,$m)){$code=(int)$m[1];if($m[2]===' ')return ['code'=>$code,'lines'=>$lines];}}throw new RuntimeException('MAIL_SMTP_RESPONSE_INCOMPLETE');
}
function kicomMailSmtpExpect($s,array $codes): array {$r=kicomMailSmtpRead($s);if(!in_array((int)$r['code'],$codes,true))throw new RuntimeException('MAIL_SMTP_UNEXPECTED_'.$r['code']);return $r;}
function kicomMailProbeImap(): array {
    $c=kicomMailConfig();$pw=kicomMailSecretRead();if($pw===null)throw new RuntimeException('MAIL_SECRET_UNAVAILABLE');$s=kicomMailTlsSocket($c['imap_host'],$c['imap_port']);
    try{$g=fgets($s,8192);if(!is_string($g)||!preg_match('/^\*\s+(OK|PREAUTH)\b/i',$g))throw new RuntimeException('MAIL_IMAP_GREETING_INVALID');$tag='K001';kicomMailWriteAll($s,$tag.' LOGIN '.kicomMailImapQuote($c['username']).' '.kicomMailImapQuote($pw)."\r\n");$r=kicomMailReadImapTagged($s,$tag);if(!preg_match('/^'.$tag.'\s+OK\b/im',$r))throw new RuntimeException('MAIL_IMAP_AUTH_FAILED');kicomMailWriteAll($s,"K002 LOGOUT\r\n");return ['ok'=>true,'tls'=>true,'authenticated'=>true];}finally{@fclose($s);}
}
function kicomMailProbeSmtp(): array {
    $c=kicomMailConfig();$pw=kicomMailSecretRead();if($pw===null)throw new RuntimeException('MAIL_SECRET_UNAVAILABLE');$s=kicomMailTlsSocket($c['smtp_host'],$c['smtp_port']);
    try{kicomMailSmtpExpect($s,[220]);kicomMailWriteAll($s,"EHLO kicom.rurtalbahn.info\r\n");kicomMailSmtpExpect($s,[250]);kicomMailWriteAll($s,"AUTH LOGIN\r\n");kicomMailSmtpExpect($s,[334]);kicomMailWriteAll($s,base64_encode($c['username'])."\r\n");kicomMailSmtpExpect($s,[334]);kicomMailWriteAll($s,base64_encode($pw)."\r\n");kicomMailSmtpExpect($s,[235]);kicomMailWriteAll($s,"QUIT\r\n");return ['ok'=>true,'tls'=>true,'authenticated'=>true];}finally{@fclose($s);}
}
function kicomMailProbe(): array {
    $imap=['ok'=>false,'code'=>'NOT_RUN'];$smtp=['ok'=>false,'code'=>'NOT_RUN'];try{$imap=kicomMailProbeImap();}catch(Throwable $e){$imap=['ok'=>false,'code'=>$e->getMessage()];}try{$smtp=kicomMailProbeSmtp();}catch(Throwable $e){$smtp=['ok'=>false,'code'=>$e->getMessage()];}
    $ok=!empty($imap['ok'])&&!empty($smtp['ok']);if(function_exists('kicomLivingEvent'))kicomLivingEvent('mail_probe',$ok?'info':'warn',['ok'=>$ok,'imap_code'=>(string)($imap['code']??'OK'),'smtp_code'=>(string)($smtp['code']??'OK'),'secret_visible'=>false]);
    return ['ok'=>$ok,'code'=>$ok?'OK':'MAIL_PROBE_FAILED','imap'=>$imap,'smtp'=>$smtp,'credential_visible'=>false];
}
function kicomMailSendText(string $to,string $subject,string $body): array {
    if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('MAIL_TO_INVALID');$c=kicomMailConfig();$pw=kicomMailSecretRead();if($pw===null)throw new RuntimeException('MAIL_SECRET_UNAVAILABLE');
    $mid='<kicom-'.bin2hex(random_bytes(16)).'@rurtalbahn.info>';$safeSubject=str_replace(["\r","\n"],'',$subject);$safeBody=str_replace(["\r\n","\r"],"\n",$body);
    $headers=['Date: '.gmdate('D, d M Y H:i:s +0000'),'From: KiCom <'.$c['identity'].'>','To: <'.$to.'>','Subject: =?UTF-8?B?'.base64_encode($safeSubject).'?=','Message-ID: '.$mid,'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: 8bit','Auto-Submitted: auto-generated','X-KiCom-Origin: bounded-mail-channel-v1'];
    $data=implode("\r\n",$headers)."\r\n\r\n".str_replace("\n","\r\n",$safeBody)."\r\n";$data=preg_replace('/(?m)^\./','..',$data)??$data;$s=kicomMailTlsSocket($c['smtp_host'],$c['smtp_port']);
    try{kicomMailSmtpExpect($s,[220]);kicomMailWriteAll($s,"EHLO kicom.rurtalbahn.info\r\n");kicomMailSmtpExpect($s,[250]);kicomMailWriteAll($s,"AUTH LOGIN\r\n");kicomMailSmtpExpect($s,[334]);kicomMailWriteAll($s,base64_encode($c['username'])."\r\n");kicomMailSmtpExpect($s,[334]);kicomMailWriteAll($s,base64_encode($pw)."\r\n");kicomMailSmtpExpect($s,[235]);kicomMailWriteAll($s,'MAIL FROM:<'.$c['identity'].">\r\n");kicomMailSmtpExpect($s,[250]);kicomMailWriteAll($s,'RCPT TO:<'.$to.">\r\n");kicomMailSmtpExpect($s,[250,251]);kicomMailWriteAll($s,"DATA\r\n");kicomMailSmtpExpect($s,[354]);kicomMailWriteAll($s,$data.".\r\n");kicomMailSmtpExpect($s,[250]);kicomMailWriteAll($s,"QUIT\r\n");return ['ok'=>true,'message_id'=>$mid,'credential_visible'=>false];}finally{@fclose($s);}
}

/* ---- KiCom bounded outbound mail (0.9.21) ------------------------------ */
function kicomMailOutboundRoot(): string { return kicomBaseDir().'/var/mail-outbound'; }
function kicomMailOutboundEnsure(): bool {
    $d=kicomMailOutboundRoot();
    if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;
    @chmod($d,0700);
    $deny=$d.'/.htaccess';if(!is_file($deny)){@file_put_contents($deny,kicomDenyRules(),LOCK_EX);@chmod($deny,0600);}
    return true;
}
function kicomMailOutboundAllowFile(): string { return kicomMailOutboundRoot().'/recipients.json'; }
function kicomMailOutboundRecipients(): array {
    if(!kicomMailOutboundEnsure())return [];
    $r=json_decode((string)@file_get_contents(kicomMailOutboundAllowFile()),true);
    $rows=is_array($r['recipients']??null)?$r['recipients']:[];
    $out=[];
    foreach($rows as $x){
        $e=strtolower(trim((string)$x));
        if(filter_var($e,FILTER_VALIDATE_EMAIL))$out[$e]=true;
    }
    return array_keys($out);
}
function kicomMailOutboundRecipientAllowed(string $to): bool {
    $to=strtolower(trim($to));$self=strtolower((string)kicomMailConfig()['identity']);
    if($to!==''&&hash_equals($self,$to))return true;
    return in_array($to,kicomMailOutboundRecipients(),true);
}
function kicomMailOutboundSaveRecipients(array $rows): bool {
    if(!kicomMailOutboundEnsure())return false;$clean=[];
    foreach($rows as $x){$e=strtolower(trim((string)$x));if(filter_var($e,FILTER_VALIDATE_EMAIL))$clean[$e]=true;}
    $list=array_keys($clean);sort($list,SORT_STRING);
    $row=['schema'=>1,'policy'=>'exact-address-allowlist','recipients'=>$list,'updated_at'=>gmdate('c')];
    $j=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);if($j===false)return false;
    $f=kicomMailOutboundAllowFile();$tmp=$f.'.tmp-'.bin2hex(random_bytes(4));
    if(@file_put_contents($tmp,$j."\n",LOCK_EX)===false){@unlink($tmp);return false;}@chmod($tmp,0600);
    if(!@rename($tmp,$f)){@unlink($tmp);return false;}@chmod($f,0600);return true;
}
function kicomMailOutboundPrepareRecipient(string $sessionId,string $recipient): array {
    $recipient=strtolower(trim($recipient));
    if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)||strlen($recipient)>254)return ['ok'=>false,'code'=>'MAIL_RECIPIENT_INVALID'];
    if(kicomMailOutboundRecipientAllowed($recipient))return ['ok'=>true,'code'=>'ALREADY_ALLOWED','recipient'=>$recipient,'already_allowed'=>true];
    $binding=['action'=>'mail_recipient_allow','recipient'=>$recipient,'policy'=>'exact-address-v1'];
    return kicomAuthApprovalCreate($sessionId,'mail_recipient_allow',$binding,[],'red');
}
function kicomMailOutboundApplyRecipientApproval(array $binding): array {
    if(($binding['action']??'')!=='mail_recipient_allow')return ['ok'=>false,'code'=>'MAIL_RECIPIENT_BINDING_INVALID'];
    $recipient=strtolower(trim((string)($binding['recipient']??'')));
    if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)||strlen($recipient)>254)return ['ok'=>false,'code'=>'MAIL_RECIPIENT_INVALID'];
    $rows=kicomMailOutboundRecipients();
    if(!in_array($recipient,$rows,true))$rows[]=$recipient;
    if(!kicomMailOutboundSaveRecipients($rows))return ['ok'=>false,'code'=>'MAIL_RECIPIENT_STORE_FAILED'];
    if(function_exists('kicomLivingEvent'))kicomLivingEvent('mail_recipient_allowed','warn',['recipient_sha256'=>hash('sha256',$recipient),'policy'=>'exact-address-v1']);
    return ['ok'=>true,'code'=>'MAIL_RECIPIENT_ALLOWED','recipient'=>$recipient];
}
function kicomMailOutboundRateFile(): string { return kicomMailOutboundRoot().'/rate.json'; }
function kicomMailOutboundRateState(): array {if(!kicomMailOutboundEnsure())return ['events'=>[]];return kicomSqliteKvRead('mail','outbound_rate',kicomMailOutboundRateFile(),['events'=>[]]);}
function kicomMailOutboundRateCheck(string $recipient): array {
    $r=kicomMailOutboundRateState();$now=time();$events=[];$hour=0;$day=0;$recipientHour=0;
    foreach($r['events'] as $e){
        if(!is_array($e))continue;$ts=(int)($e['ts']??0);if($ts<$now-86400)continue;
        $events[]=$e;$day++;
        if($ts>=$now-3600){$hour++;if(hash_equals((string)($e['recipient']??''),$recipient))$recipientHour++;}
    }
    if($hour>=10)return ['ok'=>false,'code'=>'MAIL_RATE_HOURLY'];
    if($recipientHour>=5)return ['ok'=>false,'code'=>'MAIL_RATE_RECIPIENT_HOURLY'];
    if($day>=50)return ['ok'=>false,'code'=>'MAIL_RATE_DAILY'];
    return ['ok'=>true,'events'=>$events,'hour'=>$hour,'day'=>$day,'recipient_hour'=>$recipientHour];
}
function kicomMailOutboundRateRecord(string $recipient,array $events): bool {$events[]=['ts'=>time(),'recipient'=>$recipient];return kicomSqliteKvWrite('mail','outbound_rate',['schema'=>1,'events'=>array_slice($events,-100),'updated_at'=>gmdate('c')],kicomMailOutboundRateFile());}
function kicomMailOutboundAudit(string $event,string $to,string $subject,string $body,array $extra=[]): bool {
    if(!kicomMailOutboundEnsure())return false;
    $row=['at'=>gmdate('c'),'event'=>$event,'recipient_sha256'=>hash('sha256',$to),'subject_sha256'=>hash('sha256',$subject),'body_sha256'=>hash('sha256',$body),'body_bytes'=>strlen($body)]+$extra;
    $j=json_encode($row,JSON_UNESCAPED_SLASHES);if($j===false)return false;
    $f=kicomMailOutboundRoot().'/audit-'.gmdate('Ym').'.jsonl';$ok=@file_put_contents($f,$j."\n",FILE_APPEND|LOCK_EX)!==false;if($ok)@chmod($f,0600);return $ok;
}
function kicomMailOutboundStatus(): array {
    $r=kicomMailOutboundRateCheck('__status__');
    return ['ok'=>true,'configured'=>kicomMailSecretConfigured(),'allowed_recipients'=>count(kicomMailOutboundRecipients()),'sent_last_hour'=>(int)($r['hour']??0),'sent_last_day'=>(int)($r['day']??0),'max_per_hour'=>10,'max_per_recipient_per_hour'=>5,'max_per_day'=>50,'cc_bcc'=>false,'attachments'=>false];
}
function kicomMailOutboundSend(string $to,string $subject,string $body): array {
    $to=strtolower(trim($to));
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)||strlen($to)>254)return ['ok'=>false,'code'=>'MAIL_RECIPIENT_INVALID'];
    if(!kicomMailOutboundRecipientAllowed($to))return ['ok'=>false,'code'=>'MAIL_RECIPIENT_NOT_ALLOWED'];
    if(str_contains($subject,"\r")||str_contains($subject,"\n"))return ['ok'=>false,'code'=>'MAIL_SUBJECT_INVALID'];
    if(strlen($subject)>512)return ['ok'=>false,'code'=>'MAIL_SUBJECT_TOO_LARGE'];
    $body=str_replace(["\r\n","\r"],"\n",$body);if(strlen($body)>65536)return ['ok'=>false,'code'=>'MAIL_BODY_TOO_LARGE'];
    $rate=kicomMailOutboundRateCheck($to);if(empty($rate['ok']))return $rate;
    try{$sent=kicomMailSendText($to,$subject,$body);}
    catch(Throwable $e){kicomMailOutboundAudit('send_failed',$to,$subject,$body,['code'=>$e->getMessage()]);return ['ok'=>false,'code'=>$e->getMessage()];}
    if(empty($sent['ok'])){kicomMailOutboundAudit('send_failed',$to,$subject,$body,['code'=>(string)($sent['code']??'MAIL_SEND_FAILED')]);return ['ok'=>false,'code'=>(string)($sent['code']??'MAIL_SEND_FAILED')];}
    if(!kicomMailOutboundRateRecord($to,$rate['events']??[]))return ['ok'=>false,'code'=>'MAIL_RATE_RECORD_FAILED'];
    kicomMailOutboundAudit('sent',$to,$subject,$body,['message_id'=>(string)($sent['message_id']??'')]);
    if(function_exists('kicomLivingEvent'))kicomLivingEvent('mail_outbound_sent','info',['recipient_sha256'=>hash('sha256',$to),'message_id'=>(string)($sent['message_id']??''),'subject_sha256'=>hash('sha256',$subject),'body_bytes'=>strlen($body)]);
    return ['ok'=>true,'code'=>'MAIL_SENT','message_id'=>(string)($sent['message_id']??''),'recipient'=>$to];
}
/* ---- end bounded outbound mail ----------------------------------------- */

function kicomMailInboxHasMessageId(string $mid): bool {
    if(!preg_match('/^<[^\r\n<>]+@[^\r\n<>]+>$/',$mid))throw new RuntimeException('MAIL_MESSAGE_ID_INVALID');$c=kicomMailConfig();$pw=kicomMailSecretRead();if($pw===null)throw new RuntimeException('MAIL_SECRET_UNAVAILABLE');$s=kicomMailTlsSocket($c['imap_host'],$c['imap_port']);
    try{$g=fgets($s,8192);if(!is_string($g)||!preg_match('/^\*\s+(OK|PREAUTH)\b/i',$g))throw new RuntimeException('MAIL_IMAP_GREETING_INVALID');kicomMailWriteAll($s,'K101 LOGIN '.kicomMailImapQuote($c['username']).' '.kicomMailImapQuote($pw)."\r\n");if(!preg_match('/^K101\s+OK\b/im',kicomMailReadImapTagged($s,'K101')))throw new RuntimeException('MAIL_IMAP_AUTH_FAILED');kicomMailWriteAll($s,"K102 SELECT INBOX\r\n");if(!preg_match('/^K102\s+OK\b/im',kicomMailReadImapTagged($s,'K102')))throw new RuntimeException('MAIL_IMAP_SELECT_FAILED');kicomMailWriteAll($s,'K103 SEARCH HEADER Message-ID '.kicomMailImapQuote($mid)."\r\n");$r=kicomMailReadImapTagged($s,'K103');kicomMailWriteAll($s,"K104 LOGOUT\r\n");return (bool)preg_match('/^\* SEARCH\s+\d+/mi',$r);}finally{@fclose($s);}
}
function kicomMailLoopback(int $waitSeconds=20): array {
    $probe=kicomMailProbe();if(empty($probe['ok']))return ['ok'=>false,'code'=>'MAIL_PROBE_FAILED','probe'=>$probe,'credential_visible'=>false];
    try{$nonce=bin2hex(random_bytes(8));$sent=kicomMailSendText(kicomMailConfig()['identity'],'KiCom Mail Loopback '.$nonce,"Automatischer KiCom-Verbindungstest.\nNonce: {$nonce}\n");$deadline=time()+max(1,min(60,$waitSeconds));$found=false;do{if(kicomMailInboxHasMessageId((string)$sent['message_id'])){$found=true;break;}if(time()<$deadline)sleep(2);}while(time()<$deadline);$r=['ok'=>$found,'code'=>$found?'OK':'MAIL_LOOPBACK_NOT_RECEIVED','message_id'=>$sent['message_id'],'smtp_accepted'=>true,'imap_received'=>$found,'credential_visible'=>false];if(function_exists('kicomLivingEvent'))kicomLivingEvent('mail_loopback',$found?'info':'warn',['ok'=>$found,'message_id'=>$sent['message_id'],'credential_visible'=>false]);return $r;}catch(Throwable $e){return ['ok'=>false,'code'=>$e->getMessage(),'credential_visible'=>false];}
}


/* ---- KiCom bounded inbound mail / attachment quarantine (0.9.18) ------ */
function kicomMailInboxRoot(): string { return kicomBaseDir().'/var/mail-inbox'; }
function kicomMailInboxEnsure(): bool {
    $r=kicomMailInboxRoot();$q=$r.'/quarantine';
    foreach([$r,$q] as $d){if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;@chmod($d,0700);}
    $deny=$r.'/.htaccess';if(!is_file($deny)){@file_put_contents($deny,"Require all denied\nDeny from all\n",LOCK_EX);@chmod($deny,0600);}
    return true;
}
function kicomMailInboxLimit(string $v,int $max): string {
    if(strlen($v)<=$max)return $v;
    return function_exists('mb_substr')?(string)mb_substr($v,0,$max,'UTF-8'):substr($v,0,$max);
}
function kicomMailInboxStateRead(): array {$r=kicomSqliteKvRead('mail','inbox_state',kicomMailInboxRoot().'/state.json',['seen'=>[],'last_poll_at'=>null,'uidvalidity'=>null]);return is_array($r['seen']??null)?$r:['seen'=>[],'last_poll_at'=>null,'uidvalidity'=>null];}
function kicomMailInboxStateWrite(array $s): bool {if(!kicomMailInboxEnsure())return false;return kicomSqliteKvWrite('mail','inbox_state',$s,kicomMailInboxRoot().'/state.json');}
function kicomMailInboxEventAppend(array $e): bool {if(!kicomMailInboxEnsure())return false;return kicomSqliteEventAppend('mail_inbox',$e,kicomMailInboxRoot().'/events-'.gmdate('Ym').'.jsonl');}
function kicomMailReadExact($s,int $n): string {
    if($n<0||$n>41943040)throw new RuntimeException('MAIL_LITERAL_SIZE_INVALID');$out='';
    while(strlen($out)<$n){$c=fread($s,min(65536,$n-strlen($out)));if(!is_string($c)||$c==='')throw new RuntimeException('MAIL_LITERAL_READ_FAILED');$out.=$c;}return $out;
}
function kicomMailDiscardExact($s,int $n): void {
    while($n>0){$c=fread($s,min(65536,$n));if(!is_string($c)||$c==='')throw new RuntimeException('MAIL_LITERAL_DISCARD_FAILED');$n-=strlen($c);}
}
function kicomMailReadFetchLiteral($s,string $tag,int $max=41943040): ?string {
    $raw=null;$skip=false;
    while(!feof($s)){
        $line=fgets($s,65536);if(!is_string($line))break;
        if($raw===null&&!$skip&&preg_match('/\{([0-9]+)\}\r?\n$/',$line,$m)){
            $n=(int)$m[1];if($n>$max){kicomMailDiscardExact($s,$n);$skip=true;}else{$raw=kicomMailReadExact($s,$n);}continue;
        }
        if(preg_match('/^'.preg_quote($tag,'/').'\s+(OK|NO|BAD)\b/i',$line,$m)){
            if(strtoupper($m[1])!=='OK')throw new RuntimeException('MAIL_IMAP_FETCH_FAILED');
            if($skip)return null;if(!is_string($raw))throw new RuntimeException('MAIL_IMAP_LITERAL_MISSING');return $raw;
        }
    }
    throw new RuntimeException('MAIL_IMAP_FETCH_INCOMPLETE');
}
function kicomMailFetchRecentRaw(int $limit=10): array {
    $limit=max(1,min(25,$limit));$c=kicomMailConfig();$pw=kicomMailSecretRead();if($pw===null)throw new RuntimeException('MAIL_SECRET_UNAVAILABLE');$s=kicomMailTlsSocket($c['imap_host'],$c['imap_port']);
    try{
        $g=fgets($s,8192);if(!is_string($g)||!preg_match('/^\*\s+(OK|PREAUTH)\b/i',$g))throw new RuntimeException('MAIL_IMAP_GREETING_INVALID');
        kicomMailWriteAll($s,'K201 LOGIN '.kicomMailImapQuote($c['username']).' '.kicomMailImapQuote($pw)."\r\n");if(!preg_match('/^K201\s+OK\b/im',kicomMailReadImapTagged($s,'K201')))throw new RuntimeException('MAIL_IMAP_AUTH_FAILED');
        kicomMailWriteAll($s,"K202 SELECT INBOX\r\n");$sel=kicomMailReadImapTagged($s,'K202');if(!preg_match('/^K202\s+OK\b/im',$sel))throw new RuntimeException('MAIL_IMAP_SELECT_FAILED');
        $uidv='0';if(preg_match('/\[UIDVALIDITY\s+([0-9]+)\]/i',$sel,$m))$uidv=$m[1];
        kicomMailWriteAll($s,"K203 UID SEARCH ALL\r\n");$sr=kicomMailReadImapTagged($s,'K203');if(!preg_match('/^K203\s+OK\b/im',$sr))throw new RuntimeException('MAIL_IMAP_SEARCH_FAILED');
        $uids=[];if(preg_match('/^\* SEARCH(.*)$/mi',$sr,$m))foreach(preg_split('/\s+/',trim($m[1]))?:[] as $u)if(preg_match('/^[1-9][0-9]*$/',$u))$uids[]=$u;
        $uids=array_slice($uids,-$limit);$rows=[];$i=0;
        foreach($uids as $u){$tag='K'.(300+$i++);kicomMailWriteAll($s,$tag.' UID FETCH '.$u." (BODY.PEEK[])\r\n");$raw=kicomMailReadFetchLiteral($s,$tag);if(is_string($raw))$rows[]=['uid'=>(string)$u,'raw'=>$raw];}
        kicomMailWriteAll($s,"K399 LOGOUT\r\n");return ['uidvalidity'=>$uidv,'messages'=>$rows];
    }finally{@fclose($s);}
}
function kicomMailEntitySplit(string $raw): array {
    $p=strpos($raw,"\r\n\r\n");$n=4;if($p===false){$p=strpos($raw,"\n\n");$n=2;}return $p===false?[$raw,'']:[substr($raw,0,$p),substr($raw,$p+$n)];
}
function kicomMailHeaders(string $raw): array {
    $raw=preg_replace("/\r?\n[ \t]+/",' ',$raw)??$raw;$h=[];
    foreach(preg_split('/\r?\n/',$raw)?:[] as $l){$p=strpos($l,':');if($p===false)continue;$k=strtolower(trim(substr($l,0,$p)));if(!preg_match('/^[a-z0-9-]{1,64}$/',$k))continue;$v=trim(substr($l,$p+1));$h[$k]=isset($h[$k])?$h[$k].', '.$v:$v;}return $h;
}
function kicomMailHeaderDecode(string $v): string {
    if($v==='')return '';if(function_exists('mb_decode_mimeheader')){$x=@mb_decode_mimeheader($v);if(is_string($x)&&$x!=='')return $x;}
    if(function_exists('iconv_mime_decode')){$x=@iconv_mime_decode($v,ICONV_MIME_DECODE_CONTINUE_ON_ERROR,'UTF-8');if(is_string($x)&&$x!=='')return $x;}return $v;
}
function kicomMailValueParams(string $v): array {
    $a=preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/',$v)?:[];$base=trim((string)array_shift($a));$p=[];
    foreach($a as $x){$i=strpos($x,'=');if($i===false)continue;$k=strtolower(trim(substr($x,0,$i)));$z=trim(substr($x,$i+1));if(strlen($z)>=2&&$z[0]==='"'&&$z[strlen($z)-1]==='"')$z=stripcslashes(substr($z,1,-1));if(str_ends_with($k,'*')&&str_contains($z,"''")){[, $z]=explode("''",$z,2);$z=rawurldecode($z);$k=rtrim($k,'*');}$p[$k]=$z;}return [$base,$p];
}
function kicomMailTransferDecode(string $body,string $enc): string {
    $enc=strtolower(trim($enc));if($enc==='base64'){$c=preg_replace('/\s+/','',$body)??'';$r=base64_decode($c,true);if($r===false)throw new RuntimeException('MAIL_BASE64_INVALID');return $r;}
    if($enc==='quoted-printable')return quoted_printable_decode($body);if(in_array($enc,['','7bit','8bit','binary'],true))return $body;throw new RuntimeException('MAIL_TRANSFER_ENCODING_UNSUPPORTED');
}
function kicomMailTextUtf8(string $v,string $charset): string {
    $charset=trim($charset," \t\r\n\"'");if($charset===''||strcasecmp($charset,'UTF-8')===0)return $v;
    if(function_exists('mb_convert_encoding')){try{return mb_convert_encoding($v,'UTF-8',$charset);}catch(Throwable $e){}}
    if(function_exists('iconv')){$x=@iconv($charset,'UTF-8//IGNORE',$v);if(is_string($x))return $x;}return $v;
}
function kicomMailMimeWalk(array $h,string $body,int $depth,array &$ctx,array &$leaves): void {
    if($depth>8||++$ctx['parts']>100)throw new RuntimeException('MAIL_MIME_LIMIT_EXCEEDED');
    [$ct,$cp]=kicomMailValueParams((string)($h['content-type']??'text/plain; charset=UTF-8'));[$disp,$dp]=kicomMailValueParams((string)($h['content-disposition']??''));$ct=strtolower($ct);
    if(str_starts_with($ct,'multipart/')){$b=(string)($cp['boundary']??'');if($b===''||strlen($b)>200)throw new RuntimeException('MAIL_MIME_BOUNDARY_INVALID');$segs=explode('--'.$b,$body);foreach(array_slice($segs,1) as $seg){if(str_starts_with($seg,'--'))break;$seg=preg_replace('/^\r?\n/','',$seg,1)??$seg;$seg=preg_replace('/\r?\n$/','',$seg,1)??$seg;if(trim($seg)==='')continue;[$rh,$rb]=kicomMailEntitySplit($seg);kicomMailMimeWalk(kicomMailHeaders($rh),$rb,$depth+1,$ctx,$leaves);}return;}
    $data=kicomMailTransferDecode($body,(string)($h['content-transfer-encoding']??'8bit'));$ctx['bytes']+=strlen($data);if($ctx['bytes']>31457280)throw new RuntimeException('MAIL_DECODED_SIZE_LIMIT');
    $name=kicomMailHeaderDecode((string)($dp['filename']??$cp['name']??''));$attachment=strtolower($disp)==='attachment'||$name!=='';
    if($attachment){$leaves[]=['attachment'=>true,'filename'=>$name!==''?$name:'attachment.bin','content_type'=>$ct!==''?$ct:'application/octet-stream','size'=>strlen($data),'sha256'=>hash('sha256',$data),'data'=>$data];return;}
    if(in_array($ct,['text/plain','text/html'],true)){$txt=kicomMailTextUtf8($data,(string)($cp['charset']??'UTF-8'));if($ct==='text/html')$txt=html_entity_decode(strip_tags($txt),ENT_QUOTES|ENT_HTML5,'UTF-8');$leaves[]=['attachment'=>false,'content_type'=>$ct,'text'=>kicomMailInboxLimit($txt,65536)];}
}
function kicomMailMimeParse(string $raw): array {
    if($raw===''||strlen($raw)>41943040)throw new RuntimeException('MAIL_RAW_SIZE_INVALID');[$rh,$body]=kicomMailEntitySplit($raw);$h=kicomMailHeaders($rh);$ctx=['parts'=>0,'bytes'=>0];$leaves=[];kicomMailMimeWalk($h,$body,0,$ctx,$leaves);
    $txt='';$atts=[];foreach($leaves as $x){if(!empty($x['attachment']))$atts[]=$x;elseif($txt===''&&isset($x['text']))$txt=(string)$x['text'];}
    $mid=trim((string)($h['message-id']??''));if($mid==='')$mid='<sha256-'.substr(hash('sha256',$raw),0,32).'@kicom.local>';
    return ['message_id'=>$mid,'from'=>kicomMailHeaderDecode((string)($h['from']??'')),'to'=>kicomMailHeaderDecode((string)($h['to']??'')),'subject'=>kicomMailHeaderDecode((string)($h['subject']??'')),'date'=>trim((string)($h['date']??'')),'text'=>$txt,'body_sha256'=>hash('sha256',$txt),'attachments'=>$atts];
}
function kicomMailSafeFilename(string $n): string {
    $n=str_replace(["\0","\r","\n",'/',"\\"],'_',trim($n));$n=preg_replace('/[^A-Za-z0-9._() -]+/','_',$n)??'attachment.bin';$n=trim($n," .");if($n===''||$n==='.'||$n==='..')$n='attachment.bin';return substr($n,0,180);
}
function kicomMailQuarantineStore(array $a,string $mid): array {
    if(!kicomMailInboxEnsure())throw new RuntimeException('MAIL_QUARANTINE_DIR_FAILED');$data=$a['data']??null;if(!is_string($data))throw new RuntimeException('MAIL_ATTACHMENT_DATA_REQUIRED');$size=strlen($data);if($size>26214400)throw new RuntimeException('MAIL_ATTACHMENT_SIZE_LIMIT');
    $sha=hash('sha256',$data);$name=kicomMailSafeFilename((string)($a['filename']??'attachment.bin'));$ct=strtolower(trim((string)($a['content_type']??'application/octet-stream')));$magic=substr($data,0,4);$zip=strtolower(pathinfo($name,PATHINFO_EXTENSION))==='zip'||in_array($ct,['application/zip','application/x-zip-compressed'],true)||in_array($magic,["PK\x03\x04","PK\x05\x06","PK\x07\x08"],true);
    $d=kicomMailInboxRoot().'/quarantine/'.gmdate('Ymd');if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))throw new RuntimeException('MAIL_QUARANTINE_DIR_FAILED');@chmod($d,0700);$blob=$d.'/'.$sha.'.bin';
    if(!is_file($blob)){$tmp=$blob.'.tmp-'.bin2hex(random_bytes(4));if(@file_put_contents($tmp,$data,LOCK_EX)!==$size){@unlink($tmp);throw new RuntimeException('MAIL_QUARANTINE_WRITE_FAILED');}@chmod($tmp,0600);if(!@rename($tmp,$blob)){@unlink($tmp);throw new RuntimeException('MAIL_QUARANTINE_COMMIT_FAILED');}@chmod($blob,0600);}
    $id=gmdate('Ymd').'-'.substr($sha,0,24).'-'.bin2hex(random_bytes(3));$m=['schema'=>1,'quarantine_id'=>$id,'sha256'=>$sha,'size'=>$size,'filename'=>$name,'declared_content_type'=>$ct,'zip_like'=>$zip,'trust'=>'UNTRUSTED','executable'=>false,'auto_extract'=>false,'auto_install'=>false,'verifier_required_for_release'=>$zip,'message_id_sha256'=>hash('sha256',$mid),'stored_at'=>gmdate('c')];
    $j=json_encode($m,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($j===false||@file_put_contents($d.'/'.$id.'.json',$j."\n",LOCK_EX)===false)throw new RuntimeException('MAIL_QUARANTINE_METADATA_FAILED');@chmod($d.'/'.$id.'.json',0600);return $m;
}
function kicomMailInboxStatus(): array {
    $s=kicomMailInboxStateRead();$meta=glob(kicomMailInboxRoot().'/quarantine/*/*.json')?:[];
    return ['ok'=>true,'configured'=>kicomMailSecretConfigured(),'last_poll_at'=>$s['last_poll_at']??null,'dedup_entries'=>count($s['seen']??[]),'quarantine_items'=>count($meta),'inbound_authority'=>'UNTRUSTED_PERCEPTION_INPUT','attachments'=>'QUARANTINE_ONLY','attachments_auto_execute'=>false,'attachments_auto_extract'=>false,'attachments_auto_install'=>false];
}
function kicomMailInboxPoll(int $limit=10): array {
    if(!kicomMailSecretConfigured())return ['ok'=>false,'code'=>'MAIL_SECRET_UNAVAILABLE','events'=>[]];if(!kicomMailInboxEnsure())return ['ok'=>false,'code'=>'MAIL_INBOX_STORAGE_UNAVAILABLE','events'=>[]];
    try{$fetch=kicomMailFetchRecentRaw($limit);$state=kicomMailInboxStateRead();$events=[];$uidv=(string)($fetch['uidvalidity']??'0');
        foreach($fetch['messages']??[] as $row){$uid=(string)($row['uid']??'');$raw=$row['raw']??null;if($uid===''||!is_string($raw))continue;$p=kicomMailMimeParse($raw);$key=hash('sha256',$uidv."\0".$uid."\0".(string)$p['message_id']);if(isset($state['seen'][$key]))continue;
            $ams=[];$zipc=0;foreach($p['attachments'] as $a){$m=kicomMailQuarantineStore($a,(string)$p['message_id']);$ams[]=$m;if(!empty($m['zip_like']))$zipc++;}
            $eid=gmdate('YmdHis').'-'.substr(hash('sha256',$key.random_bytes(8)),0,20);$event=['event_id'=>$eid,'event_type'=>'mail_received','trust'=>'UNTRUSTED','authority_granted'=>false,'uid'=>$uid,'uidvalidity'=>$uidv,'message_id'=>(string)$p['message_id'],'from'=>kicomMailInboxLimit((string)$p['from'],1024),'to'=>kicomMailInboxLimit((string)$p['to'],1024),'subject'=>kicomMailInboxLimit((string)$p['subject'],998),'date'=>kicomMailInboxLimit((string)$p['date'],128),'body_sha256'=>(string)$p['body_sha256'],'body_text'=>kicomMailInboxLimit((string)$p['text'],65536),'attachments'=>$ams,'zip_attachments'=>$zipc,'links'=>'UNTRUSTED','received_by_kicom_at'=>gmdate('c')];
            if(!kicomMailInboxEventAppend($event))throw new RuntimeException('MAIL_EVENT_APPEND_FAILED');$events[]=$event;$state['seen'][$key]=time();
            if(function_exists('kicomLivingEvent'))kicomLivingEvent('mail_received','info',['event_id'=>$eid,'from_sha256'=>hash('sha256',(string)$p['from']),'attachments'=>count($ams),'zip_attachments'=>$zipc,'trust'=>'UNTRUSTED','authority_granted'=>false]);
        }
        if(count($state['seen'])>4096){asort($state['seen'],SORT_NUMERIC);$state['seen']=array_slice($state['seen'],-2048,null,true);}$state['last_poll_at']=gmdate('c');$state['uidvalidity']=$uidv;if(!kicomMailInboxStateWrite($state))throw new RuntimeException('MAIL_STATE_WRITE_FAILED');
        return ['ok'=>true,'code'=>'OK','new_messages'=>count($events),'events'=>$events,'trust'=>'UNTRUSTED','authority_changed'=>false,'attachments_auto_execute'=>false,'attachments_auto_install'=>false];
    }catch(Throwable $e){if(function_exists('kicomLivingEvent'))kicomLivingEvent('mail_inbox_poll','warn',['ok'=>false,'code'=>$e->getMessage()]);return ['ok'=>false,'code'=>$e->getMessage(),'events'=>[],'authority_changed'=>false];}
}

function kicomMailQuarantineResolve(string $id): array {
    $id=strtolower(trim($id));
    if(!preg_match('/^([0-9]{8})-([a-f0-9]{24})-([a-f0-9]{6})$/',$id,$m))return ['ok'=>false,'code'=>'MAIL_QUARANTINE_ID_INVALID'];
    $dir=kicomMailInboxRoot().'/quarantine/'.$m[1];$metaPath=$dir.'/'.$id.'.json';
    if(!is_file($metaPath))return ['ok'=>false,'code'=>'MAIL_QUARANTINE_NOT_FOUND'];
    $meta=json_decode((string)@file_get_contents($metaPath),true);
    if(!is_array($meta)||!hash_equals($id,(string)($meta['quarantine_id']??'')))return ['ok'=>false,'code'=>'MAIL_QUARANTINE_META_INVALID'];
    $sha=strtolower((string)($meta['sha256']??''));if(!preg_match('/^[a-f0-9]{64}$/',$sha))return ['ok'=>false,'code'=>'MAIL_QUARANTINE_SHA_INVALID'];
    if(empty($meta['zip_like']))return ['ok'=>false,'code'=>'MAIL_QUARANTINE_NOT_ZIP'];
    $blob=$dir.'/'.$sha.'.bin';if(!is_file($blob))return ['ok'=>false,'code'=>'MAIL_QUARANTINE_BLOB_MISSING'];
    $actual=hash_file('sha256',$blob)?:'';if(!hash_equals($sha,$actual))return ['ok'=>false,'code'=>'MAIL_QUARANTINE_HASH_MISMATCH'];
    $size=(int)@filesize($blob);if($size!==(int)($meta['size']??-1)||$size<=0)return ['ok'=>false,'code'=>'MAIL_QUARANTINE_SIZE_MISMATCH'];
    return ['ok'=>true,'id'=>$id,'path'=>$blob,'meta'=>$meta,'sha256'=>$sha,'size'=>$size];
}
function kicomMailUpdateCandidates(int $limit=50): array {
    $limit=max(1,min(100,$limit));$rows=[];$files=glob(kicomMailInboxRoot().'/quarantine/*/*.json')?:[];rsort($files,SORT_STRING);
    foreach($files as $f){
        if(count($rows)>=$limit)break;$m=json_decode((string)@file_get_contents($f),true);if(!is_array($m)||empty($m['zip_like']))continue;
        $id=(string)($m['quarantine_id']??'');$r=kicomMailQuarantineResolve($id);if(empty($r['ok']))continue;
        $rows[]=['quarantine_id'=>$id,'filename'=>(string)($m['filename']??''),'bytes'=>(int)$r['size'],'sha256'=>(string)$r['sha256'],'stored_at'=>(string)($m['stored_at']??''),'trust'=>'UNTRUSTED','verifier_required'=>true];
    }
    return $rows;
}
function kicomMailUpdateCorroboration(string $version,string $sha): array {
    $version=trim($version);$sha=strtolower(trim($sha));$matches=[];$seenHashes=[];$errors=[];
    $cfg=kicomUpdateChannelsLoad();
    foreach(($cfg['pull']['feeds']??[]) as $feed){
        if(!is_array($feed)||empty($feed['enabled']))continue;$url=trim((string)($feed['url']??''));$name=(string)($feed['name']??'feed');if($url===''||!kicomUpdateFeedUrlAllowed($url))continue;
        $get=kicomUpdateHttpGet($url,KICOM_UPDATE_FEED_MAX_BYTES);
        if(empty($get['ok'])){$errors[]=$name.':'.(string)($get['code']??'FETCH_FAILED');continue;}
        $parsed=kicomUpdateFeedParse((string)$get['body'],$url);
        if(empty($parsed['ok'])){$errors[]=$name.':'.(string)($parsed['code']??'FEED_INVALID');continue;}
        foreach(($parsed['releases']??[]) as $rel){
            if(!is_array($rel)||!hash_equals($version,(string)($rel['version']??'')))continue;
            $h=strtolower((string)($rel['sha256']??''));if(preg_match('/^[a-f0-9]{64}$/',$h))$seenHashes[$h]=true;
            if($h!==''&&hash_equals($sha,$h))$matches[]=$name;
        }
    }
    $conflict=count($seenHashes)>1;
    return ['matched'=>!$conflict&&count($matches)>0,'matches'=>array_values(array_unique($matches)),'feed_conflict'=>$conflict,'observed_hashes'=>array_keys($seenHashes),'errors'=>$errors];
}
function kicomMailUpdateImport(string $id): array {
    $q=kicomMailQuarantineResolve($id);if(empty($q['ok']))return $q;
    $check=kicomSelfUpdateZipInspect((string)$q['path']);
    if(empty($check['ok'])){
        if(function_exists('kicomLivingEvent'))kicomLivingEvent('mail_update_rejected','warn',['quarantine_id'=>$id,'sha256'=>(string)$q['sha256'],'code'=>(string)($check['code']??'VERIFIER_REJECTED')]);
        return ['ok'=>false,'code'=>(string)($check['code']??'MAIL_UPDATE_VERIFIER_REJECTED'),'quarantine_id'=>$id,'sha256'=>(string)$q['sha256'],'verifier_passed'=>false];
    }
    $version=(string)($check['version']??'');$risk=kicomSelfUpdateRiskClass($check);$corr=kicomMailUpdateCorroboration($version,(string)$q['sha256']);
    $pending=kicomSelfUpdatePending();
    if(is_array($pending)){
        $psha=(string)($pending['zip_sha256']??'');
        if($psha!==''&&hash_equals($psha,(string)$q['sha256']))return ['ok'=>true,'code'=>'ALREADY_STAGED','quarantine_id'=>$id,'to_version'=>$version,'sha256'=>(string)$q['sha256'],'risk_class'=>(string)$risk['class'],'corroborated'=>!empty($corr['matched']),'auto_installed'=>false];
        return ['ok'=>false,'code'=>'PENDING_UPDATE_EXISTS','quarantine_id'=>$id,'pending_version'=>(string)($pending['to_version']??''),'pending_sha256'=>$psha,'candidate_version'=>$version,'candidate_sha256'=>(string)$q['sha256']];
    }
    $allowAuto=((string)$risk['class']==='green'&&!empty($corr['matched']));
    $r=kicomReceiveSelfUpdatePackage((string)$q['path'],(string)($q['meta']['filename']??'mail-update.zip'),'mail:'.$id,$allowAuto);
    $auto=(string)($r['code']??'')==='AUTO_INSTALLED_GREEN';
    if(function_exists('kicomLivingEvent'))kicomLivingEvent('mail_update_import',!empty($r['ok'])?'info':'warn',['quarantine_id'=>$id,'to_version'=>$version,'sha256'=>(string)$q['sha256'],'risk_class'=>(string)$risk['class'],'corroborated'=>!empty($corr['matched']),'auto_installed'=>$auto,'result'=>(string)($r['code']??'UNKNOWN')]);
    return ['ok'=>!empty($r['ok']),'code'=>(string)($r['code']??'UNKNOWN'),'quarantine_id'=>$id,'to_version'=>$version,'sha256'=>(string)$q['sha256'],'risk_class'=>(string)$risk['class'],'corroborated'=>!empty($corr['matched']),'corroborating_feeds'=>$corr['matches'],'feed_conflict'=>!empty($corr['feed_conflict']),'feed_errors'=>$corr['errors'],'auto_install_eligible'=>$allowAuto,'auto_installed'=>$auto,'result'=>$r];
}
/* ---- end bounded inbound mail ------------------------------------------ */


function kicomSelfUpdateSupported(): bool {
    return class_exists('ZipArchive') || class_exists('PharData');
}
function kicomSafeUpdatePath(string $path): ?string {
    $path=str_replace('\\','/',$path);
    $path=preg_replace('~^\./+~','',$path)??$path;
    $path=ltrim(trim($path),'/');
    if($path===''||strlen($path)>220||str_contains($path,"\0"))return null;
    if(!preg_match('~^[A-Za-z0-9_./-]+$~',$path))return null;
    $parts=explode('/',$path);
    foreach($parts as $seg){
        if($seg===''||$seg==='.'||$seg==='..')return null;
        if(str_starts_with($seg,'.')&&$seg!=='.htaccess')return null;
    }
    return $path;
}
function kicomSelfUpdateInstallPathAllowed(string $path): bool {
    $root=['.htaccess','README.md','admin.php','api.php','index.php','lib.php','living.php','recovery.php','guardian.php','robots.txt','MANIFEST.sha256'];
    if(in_array($path,$root,true))return true;
    if(in_array($path,['var/.htaccess','stage/.htaccess'],true))return true;
    if(str_starts_with($path,'memory/'))return basename($path)==='.htaccess'||strtolower(pathinfo($path,PATHINFO_EXTENSION))==='kcl';
    if(str_starts_with($path,'genome/'))return basename($path)==='.htaccess'||in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['json','sig','txt'],true);
    if(str_starts_with($path,'modules/'))return basename($path)==='.htaccess'||in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['php','json','txt'],true);
    if(str_starts_with($path,'assets/'))return in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['css','js','json','svg','png','jpg','jpeg','webp','ico','txt'],true);
    return false;
}
function kicomSelfUpdateManifestParse(string $raw): array {
    $rows=[];
    foreach(preg_split('/\r?\n/',$raw)?:[] as $line){
        $line=trim($line);if($line==='')continue;
        if(!preg_match('/^([a-f0-9]{64})\s+(.+)$/i',$line,$m))return ['ok'=>false,'code'=>'MANIFEST_INVALID'];
        $path=kicomSafeUpdatePath(trim($m[2]));
        if($path===null||isset($rows[$path]))return ['ok'=>false,'code'=>'MANIFEST_PATH_INVALID'];
        $rows[$path]=strtolower($m[1]);
    }
    foreach(['.htaccess','lib.php','index.php','api.php','admin.php','living.php','recovery.php','guardian.php','robots.txt','genome/genome.json'] as $required)if(!isset($rows[$required]))return ['ok'=>false,'code'=>'MANIFEST_REQUIRED_FILE_MISSING','path'=>$required];
    return ['ok'=>true,'files'=>$rows];
}
function kicomSelfUpdateZipInspect(string $zipPath): array {
    if(!kicomSelfUpdateSupported())return ['ok'=>false,'code'=>'ZIP_READER_UNAVAILABLE'];
    if(!is_file($zipPath))return ['ok'=>false,'code'=>'PACKAGE_NOT_FOUND'];
    $size=(int)@filesize($zipPath);if($size<=0||$size>KICOM_MAX_SELF_UPDATE_ZIP_BYTES)return ['ok'=>false,'code'=>'PACKAGE_SIZE_INVALID'];
    $entries=[];$total=0;
    if(class_exists('ZipArchive')){
        $zip=new ZipArchive();$opened=$zip->open($zipPath,ZipArchive::RDONLY);
        if($opened!==true)return ['ok'=>false,'code'=>'ZIP_OPEN_FAILED'];
        try{
            if($zip->numFiles<1||$zip->numFiles>KICOM_MAX_SELF_UPDATE_FILES)return ['ok'=>false,'code'=>'ZIP_FILE_COUNT_INVALID'];
            for($i=0;$i<$zip->numFiles;$i++){
                $st=$zip->statIndex($i,ZipArchive::FL_UNCHANGED);if(!is_array($st))return ['ok'=>false,'code'=>'ZIP_ENTRY_INVALID'];
                $name=(string)($st['name']??'');if(str_ends_with($name,'/'))continue;
                $clean=kicomSafeUpdatePath($name);if($clean===null||isset($entries[$clean]))return ['ok'=>false,'code'=>'ZIP_PATH_INVALID'];
                if(method_exists($zip,'getExternalAttributesIndex')){
                    $opsys=0;$attr=0;if($zip->getExternalAttributesIndex($i,$opsys,$attr)){$mode=($attr>>16)&0170000;if($mode===0120000)return ['ok'=>false,'code'=>'ZIP_SYMLINK_FORBIDDEN','path'=>$clean];}
                }
                $usize=(int)($st['size']??0);$total+=$usize;if($total>KICOM_MAX_SELF_UPDATE_UNCOMPRESSED_BYTES)return ['ok'=>false,'code'=>'ZIP_UNCOMPRESSED_TOO_LARGE'];
                $raw=$zip->getFromIndex($i);if($raw===false)return ['ok'=>false,'code'=>'ZIP_READ_FAILED','path'=>$clean];
                $entries[$clean]=['bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'content'=>$raw];
            }
        } finally {$zip->close();}
    } else {
        try{
            $real=realpath($zipPath);if($real===false)return ['ok'=>false,'code'=>'ZIP_OPEN_FAILED'];
            $phar=new PharData($real);$prefix='phar://'.str_replace('\\','/',$real).'/';$count=0;
            foreach(new RecursiveIteratorIterator($phar,RecursiveIteratorIterator::LEAVES_ONLY) as $file){
                if(!($file instanceof PharFileInfo))continue;$count++;if($count>KICOM_MAX_SELF_UPDATE_FILES)return ['ok'=>false,'code'=>'ZIP_FILE_COUNT_INVALID'];
                if($file->isLink())return ['ok'=>false,'code'=>'ZIP_SYMLINK_FORBIDDEN'];
                $pathname=str_replace('\\','/',$file->getPathname());if(!str_starts_with($pathname,$prefix))return ['ok'=>false,'code'=>'ZIP_PATH_INVALID'];
                $clean=kicomSafeUpdatePath(substr($pathname,strlen($prefix)));if($clean===null||isset($entries[$clean]))return ['ok'=>false,'code'=>'ZIP_PATH_INVALID'];
                $usize=(int)$file->getSize();$total+=$usize;if($total>KICOM_MAX_SELF_UPDATE_UNCOMPRESSED_BYTES)return ['ok'=>false,'code'=>'ZIP_UNCOMPRESSED_TOO_LARGE'];
                $raw=@file_get_contents($pathname);if($raw===false)return ['ok'=>false,'code'=>'ZIP_READ_FAILED','path'=>$clean];
                $entries[$clean]=['bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'content'=>$raw];
            }
            if($count<1)return ['ok'=>false,'code'=>'ZIP_FILE_COUNT_INVALID'];
        } catch(Throwable $e){return ['ok'=>false,'code'=>'ZIP_OPEN_FAILED'];}
    }
    if(!isset($entries['MANIFEST.sha256']))return ['ok'=>false,'code'=>'MANIFEST_MISSING'];
    $manifestRaw=(string)$entries['MANIFEST.sha256']['content'];$mp=kicomSelfUpdateManifestParse($manifestRaw);if(!$mp['ok'])return $mp;$manifest=$mp['files'];
    foreach($entries as $path=>$e){if($path==='MANIFEST.sha256')continue;if(!isset($manifest[$path]))return ['ok'=>false,'code'=>'UNMANIFESTED_FILE','path'=>$path];}
    foreach($manifest as $path=>$sha){if(!isset($entries[$path]))return ['ok'=>false,'code'=>'MANIFEST_FILE_MISSING','path'=>$path];if(!hash_equals($sha,(string)$entries[$path]['sha256']))return ['ok'=>false,'code'=>'MANIFEST_HASH_MISMATCH','path'=>$path];}
    $lib=(string)$entries['lib.php']['content'];if(!preg_match("/const\\s+KICOM_VERSION\\s*=\\s*'([0-9]+\\.[0-9]+\\.[0-9]+)'\\s*;/",$lib,$m))return ['ok'=>false,'code'=>'VERSION_NOT_FOUND'];$version=$m[1];
    $install=[];$preserved=[];foreach(array_keys($manifest) as $path){$stateSentinel=in_array($path,['var/.htaccess','stage/.htaccess'],true);if((str_starts_with($path,'var/')||str_starts_with($path,'stage/'))&&!$stateSentinel){$preserved[]=$path;continue;}if(!kicomSelfUpdateInstallPathAllowed($path))return ['ok'=>false,'code'=>'UPDATE_PATH_NOT_ALLOWLISTED','path'=>$path];$install[$path]=$entries[$path];}
    foreach($install as $path=>$e){
        if(strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='php')continue;
        try{token_get_all((string)$e['content'],TOKEN_PARSE);}catch(ParseError $pe){return ['ok'=>false,'code'=>'PHP_SYNTAX_INVALID','path'=>$path];}
    }
    $genomeRaw=(string)($entries['genome/genome.json']['content']??'');
    $genome=json_decode($genomeRaw,true);
    if(!is_array($genome))return ['ok'=>false,'code'=>'GENOME_JSON_INVALID'];
    if(!hash_equals($version,(string)($genome['version']??'')))return ['ok'=>false,'code'=>'GENOME_VERSION_MISMATCH'];
    $gv=kicomGenomeValidateArray($genome,$entries);if(!$gv['ok'])return $gv;
    $currentGenome=kicomGenomeCurrent();
    if(is_array($currentGenome)){
        if(!hash_equals((string)($currentGenome['id']??''),(string)($genome['parent']??'')))return ['ok'=>false,'code'=>'GENOME_PARENT_MISMATCH'];
        if((int)($genome['generation']??0)!==(int)($currentGenome['generation']??0)+1)return ['ok'=>false,'code'=>'GENOME_GENERATION_INVALID'];
    }
    $currentRecovery=is_file(kicomBaseDir().'/recovery.php')?(hash_file('sha256',kicomBaseDir().'/recovery.php')?:''):'';
    $kernelChanged=!hash_equals($currentRecovery,(string)($entries['recovery.php']['sha256']??''));
    if(is_array($currentGenome)){
        $oldKr=(int)($currentGenome['kernel_revision']??0);$newKr=(int)($genome['kernel_revision']??0);
        if($kernelChanged&&$newKr<=$oldKr)return ['ok'=>false,'code'=>'KERNEL_REVISION_NOT_INCREMENTED'];
        if(!$kernelChanged&&$newKr!==$oldKr)return ['ok'=>false,'code'=>'KERNEL_REVISION_CHANGED_WITHOUT_KERNEL'];
    }
    $install['MANIFEST.sha256']=$entries['MANIFEST.sha256'];
    return ['ok'=>true,'version'=>$version,'zip_sha256'=>hash_file('sha256',$zipPath)?:'', 'zip_bytes'=>$size,'files_count'=>count($entries),'install_files'=>$install,'preserved_files'=>$preserved,'manifest_files'=>$manifest,'manifest_sha256'=>hash('sha256',$manifestRaw),'genome'=>$genome,'genome_sha256'=>hash('sha256',$genomeRaw),'entries'=>$entries,'kernel_update'=>$kernelChanged];
}

function kicomSelfUpdateNormalizedLib(string $raw): string {
    return preg_replace("/const\s+KICOM_VERSION\s*=\s*'[0-9]+\.[0-9]+\.[0-9]+'\s*;/","const KICOM_VERSION = '__VERSION__';",$raw,1)??$raw;
}
function kicomSelfUpdateChangedPaths(array $check): array {
    $changed=[];
    foreach(($check['install_files']??[]) as $path=>$e){
        if($path==='MANIFEST.sha256'){$changed[]=$path;continue;}
        $full=kicomBaseDir().'/'.$path;
        $cur=is_file($full)?(hash_file('sha256',$full)?:''):'NEW';
        if(!hash_equals((string)($e['sha256']??''),$cur))$changed[]=$path;
    }
    return $changed;
}
function kicomSelfUpdateRiskClass(array $check): array {
    if(empty($check['ok']))return ['class'=>'red','reasons'=>['package-not-verified'],'changed'=>[]];
    $changed=kicomSelfUpdateChangedPaths($check);$reasons=[];$class='green';
    $current=kicomGenomeCurrent();$new=$check['genome']??null;
    if(!is_array($new)||!is_array($current))return ['class'=>'red','reasons'=>['genome-unavailable'],'changed'=>$changed];

    if(!empty($check['kernel_update'])){$class='red';$reasons[]='recovery-kernel-change';}
    foreach(['invariants','healthchecks','mutable_paths'] as $field){
        $a=$current[$field]??[];$b=$new[$field]??[];
        if(json_encode($a)!==json_encode($b)){$class='red';$reasons[]='genome-'.$field.'-change';}
    }
    if((int)($current['kernel_revision']??0)!==(int)($new['kernel_revision']??0)){$class='red';$reasons[]='kernel-revision-change';}

    $curComponents=[];foreach(($current['components']??[]) as $c)if(is_array($c)&&isset($c['path']))$curComponents[(string)$c['path']]=$c;
    $newComponents=[];foreach(($new['components']??[]) as $c)if(is_array($c)&&isset($c['path']))$newComponents[(string)$c['path']]=$c;
    $curKeys=array_keys($curComponents);$newKeys=array_keys($newComponents);sort($curKeys);sort($newKeys);
    if($curKeys!==$newKeys){
        $class='red';$reasons[]='genome-component-set-change';
    } else {
        foreach($newComponents as $path=>$c){
            $old=$curComponents[$path]??[];
            if((bool)($old['auto_heal']??false)!==(bool)($c['auto_heal']??false)||($old['role']??'')!==($c['role']??'')){
                $class='red';$reasons[]='component-policy-change:'.$path;break;
            }
        }
    }

    $hardRed=['recovery.php','guardian.php','api.php','index.php','.htaccess','robots.txt','genome/.htaccess','memory/.htaccess','stage/.htaccess','var/.htaccess'];
    foreach($changed as $path)if(in_array($path,$hardRed,true)&&$path!=='MANIFEST.sha256'){
        $class='red';$reasons[]='security-boundary-change:'.$path;
    }

    if(in_array('lib.php',$changed,true)){
        $cur=@file_get_contents(kicomBaseDir().'/lib.php');$newLib=(string)($check['entries']['lib.php']['content']??'');
        if($cur===false||kicomSelfUpdateNormalizedLib((string)$cur)!==kicomSelfUpdateNormalizedLib($newLib)){
            if($class!=='red')$class='yellow';$reasons[]='core-library-change';
        } else $reasons[]='version-metadata-only';
    }

    foreach($changed as $path){
        if(in_array($path,['MANIFEST.sha256','genome/genome.json','lib.php','README.md'],true)||str_starts_with($path,'assets/'))continue;
        if(in_array($path,['admin.php','living.php'],true)||str_starts_with($path,'memory/')){
            if($class==='green')$class='yellow';$reasons[]='reviewed-code-or-memory-change:'.$path;continue;
        }
        if(str_ends_with(strtolower($path),'.php')&&$class==='green'){$class='yellow';$reasons[]='php-change:'.$path;}
    }
    if($class==='green'&&!$reasons)$reasons[]='presentation-or-metadata-only';
    return ['class'=>$class,'reasons'=>array_values(array_unique($reasons)),'changed'=>$changed];
}
function kicomStageSelfUpdatePackage(string $sourcePath,string $originalName='update.zip',string $source='admin_upload'): array {
    if(!kicomEnsureStorage())return ['ok'=>false,'code'=>'STORAGE_UNAVAILABLE'];
    $check=kicomSelfUpdateZipInspect($sourcePath);if(!$check['ok'])return $check;
    $version=(string)$check['version'];
    if(version_compare($version,KICOM_VERSION,'<='))return ['ok'=>false,'code'=>'VERSION_NOT_NEWER','current_version'=>KICOM_VERSION,'package_version'=>$version];
    $risk=kicomSelfUpdateRiskClass($check);
    $sha=(string)$check['zip_sha256'];$dest=kicomSelfUpdatePackagesDir().'/'.$sha.'.zip';
    if(!is_file($dest)&&!@copy($sourcePath,$dest))return ['ok'=>false,'code'=>'PACKAGE_STORE_FAILED'];@chmod($dest,0600);
    $pending=[
        'package_file'=>basename($dest),'original_name'=>basename($originalName),
        'from_version'=>KICOM_VERSION,'to_version'=>$version,'zip_sha256'=>$sha,
        'manifest_sha256'=>$check['manifest_sha256'],'genome_id'=>(string)($check['genome']['id']??''),
        'genome_sha256'=>(string)($check['genome_sha256']??''),'kernel_update'=>(bool)($check['kernel_update']??false),
        'zip_bytes'=>$check['zip_bytes'],'files_count'=>$check['files_count'],
        'install_files_count'=>count($check['install_files']),'preserved_state_files'=>count($check['preserved_files']),
        'source'=>substr(preg_replace('/[^A-Za-z0-9_.:-]/','',$source)??'unknown',0,80),
        'risk_class'=>$risk['class'],'risk_reasons'=>$risk['reasons'],'changed_paths'=>$risk['changed'],
        'created_at'=>gmdate('c')
    ];
    $old=kicomSelfUpdatePending();
    if(is_array($old)&&!hash_equals((string)($old['zip_sha256']??''),$sha)){
        $old['superseded_at']=gmdate('c');$old['superseded_by_version']=$version;$old['superseded_by_sha256']=$sha;
        $oldJson=json_encode($old,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        if($oldJson!==false){$sid=gmdate('YmdHis').'-'.substr((string)($old['zip_sha256']??hash('sha256',$oldJson)),0,16);$sf=kicomSelfUpdateSupersededDir().'/'.$sid.'.json';if(!is_file($sf)){@file_put_contents($sf,$oldJson."\n",LOCK_EX);@chmod($sf,0600);}}
        kicomLivingEvent('update_pending_superseded','info',['from_version'=>(string)($old['to_version']??''),'from_sha256'=>(string)($old['zip_sha256']??''),'to_version'=>$version,'to_sha256'=>$sha]);
    }
    $json=json_encode($pending,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($json===false||@file_put_contents(kicomSelfUpdatePendingFile(),$json,LOCK_EX)===false)return ['ok'=>false,'code'=>'PENDING_WRITE_FAILED'];@chmod(kicomSelfUpdatePendingFile(),0600);
    kicomLivingEvent('update_received','info',['source'=>$pending['source'],'to_version'=>$version,'risk_class'=>$risk['class'],'sha256'=>$sha]);
    return ['ok'=>true]+$pending;
}
function kicomReceiveSelfUpdatePackage(string $sourcePath,string $originalName,string $source,bool $allowAuto=true): array {
    $r=kicomStageSelfUpdatePackage($sourcePath,$originalName,$source);if(!$r['ok'])return $r;
    $cfg=kicomUpdateChannelsLoad();
    if($allowAuto&&($r['risk_class']??'')==='green'&&!empty($cfg['auto_install_green'])){
        $pending=kicomSelfUpdatePending();if($pending===null)return ['ok'=>false,'code'=>'PENDING_LOST'];
        $apply=kicomApplySelfUpdate($pending);
        return ['ok'=>(bool)($apply['ok']??false),'code'=>($apply['ok']??false)?'AUTO_INSTALLED_GREEN':($apply['code']??'AUTO_INSTALL_FAILED'),'staged'=>$r,'install'=>$apply,'risk_class'=>'green'];
    }
    return ['ok'=>true,'code'=>'STAGED_DECISION_REQUIRED','risk_class'=>(string)$r['risk_class'],'staged'=>$r];
}
function kicomUpdatePullCheck(bool $allowAuto=true,bool $force=false): array {
    if(!kicomEnsureStorage())return ['ok'=>false,'code'=>'STORAGE_UNAVAILABLE'];
    $cfg=kicomUpdateChannelsLoad();if(empty($cfg['pull']['enabled']))return ['ok'=>true,'code'=>'PULL_DISABLED'];
    $state=kicomUpdateChannelState();$last=(int)($state['last_check_epoch']??0);
    if(!$force&&$last>0&&(time()-$last)<KICOM_UPDATE_CHANNEL_MIN_INTERVAL)return ['ok'=>true,'code'=>'PULL_THROTTLED','last_check_at'=>$state['last_check_at']??''];
    $candidates=[];$errors=[];$feedChecks=[];
    foreach(($cfg['pull']['feeds']??[]) as $feed){
        if(!is_array($feed)||empty($feed['enabled']))continue;$url=trim((string)($feed['url']??''));if($url==='')continue;
        $name=(string)($feed['name']??'feed');$diag=['name'=>$name,'checked_at'=>gmdate('c'),'ok'=>false,'code'=>'not_checked','http_code'=>0,'json_valid'=>false,'releases'=>0];
        if(!kicomUpdateFeedUrlAllowed($url)){$diag['code']='FEED_URL_NOT_ALLOWED';$feedChecks[]=$diag;$errors[]=['feed'=>$name,'code'=>$diag['code']];continue;}
        $get=kicomUpdateHttpGet($url,KICOM_UPDATE_FEED_MAX_BYTES);$diag['http_code']=(int)($get['http_code']??0);
        if(!$get['ok']){$diag['code']=(string)($get['code']??'FETCH_FAILED');$feedChecks[]=$diag;$errors[]=['feed'=>$name,'code'=>$diag['code'],'http_code'=>$diag['http_code']];continue;}
        $parsed=kicomUpdateFeedParse((string)$get['body'],$url);
        if(!$parsed['ok']){$diag['code']=(string)($parsed['code']??'FEED_INVALID');$feedChecks[]=$diag;$errors[]=['feed'=>$name,'code'=>$diag['code'],'http_code'=>$diag['http_code']];continue;}
        $diag['ok']=true;$diag['code']='OK';$diag['json_valid']=true;$diag['releases']=count($parsed['releases']);$feedChecks[]=$diag;
        foreach($parsed['releases'] as $rel)$candidates[]=$rel+['feed_name'=>$name,'feed_url'=>$url];
    }
    $now=['last_check_epoch'=>time(),'last_check_at'=>gmdate('c'),'feed_checks'=>$feedChecks];
    if(!$candidates){
        $evo=kicomEvolutionAutonomousTick();
        $code=(string)($evo['code']??'');
        $now+=['last_code'=>$code==='AUTO_INSTALLED_GREEN'?'EVOLUTION_AUTO_INSTALLED_GREEN':($errors?'NO_RELEASE_FEED_ERRORS':'NO_UPDATE'),'last_source'=>$code==='AUTO_INSTALLED_GREEN'?'evolution':'pull','errors'=>$errors,'evolution_code'=>$code];kicomUpdateChannelStateWrite($now);
        if($code==='AUTO_INSTALLED_GREEN')return ['ok'=>true,'code'=>'EVOLUTION_AUTO_INSTALLED_GREEN','errors'=>$errors,'feed_checks'=>$feedChecks,'evolution'=>$evo];
        return ['ok'=>true,'code'=>$now['last_code'],'errors'=>$errors,'feed_checks'=>$feedChecks,'evolution'=>$evo];
    }
    usort($candidates,fn($a,$b)=>version_compare((string)$b['version'],(string)$a['version']));
    $best=$candidates[0];$same=array_values(array_filter($candidates,fn($x)=>($x['version']??'')===$best['version']));
    $hashes=array_values(array_unique(array_map(fn($x)=>(string)$x['sha256'],$same)));
    if(count($hashes)>1){
        $now+=['last_code'=>'FEED_CONFLICT','last_source'=>'pull','version'=>$best['version']];kicomUpdateChannelStateWrite($now);
        kicomLivingEvent('update_feed_conflict','critical',['version'=>$best['version'],'hashes'=>$hashes]);
        return ['ok'=>false,'code'=>'FEED_CONFLICT','version'=>$best['version']];
    }
    $dl=kicomUpdateDownloadPackage((string)$best['url'],(string)$best['feed_url'],(string)$best['sha256']);
    if(!$dl['ok']){$now+=['last_code'=>$dl['code']??'DOWNLOAD_FAILED','last_source'=>'pull'];kicomUpdateChannelStateWrite($now);return $dl;}
    try{
        $r=kicomReceiveSelfUpdatePackage((string)$dl['path'],'KiCom-'.$best['version'].'.zip','pull:'.$best['feed_name'],$allowAuto);
    } finally {@unlink((string)($dl['path']??''));}
    $now+=['last_code'=>$r['code']??($r['ok']?'OK':'FAILED'),'last_source'=>'pull:'.$best['feed_name'],'version'=>$best['version'],'sha256'=>$best['sha256'],'risk_class'=>$r['risk_class']??($r['staged']['risk_class']??'')];kicomUpdateChannelStateWrite($now);
    return $r+['feed_errors'=>$errors,'feed_matches'=>count($same)];
}
function kicomSelfUpdatePending(): ?array {
    $f=kicomSelfUpdatePendingFile();if(!is_file($f))return null;$r=json_decode((string)@file_get_contents($f),true);return is_array($r)?$r:null;
}
function kicomSelfUpdatePackagePath(array $pending): ?string {
    $name=(string)($pending['package_file']??'');if(!preg_match('/^[a-f0-9]{64}\\.zip$/',$name))return null;$path=kicomSelfUpdatePackagesDir().'/'.$name;return is_file($path)?$path:null;
}
function kicomSelfUpdateAtomicWrite(string $relative,string $content,string $tx): array {
    $path=kicomSafeUpdatePath($relative);$stateSentinel=in_array((string)$path,['var/.htaccess','stage/.htaccess'],true);if($path===null||((str_starts_with($path,'var/')||str_starts_with($path,'stage/'))&&!$stateSentinel))return ['ok'=>false,'code'=>'UPDATE_PATH_FORBIDDEN'];
    $full=kicomBaseDir().'/'.$path;$parent=dirname($full);
    if(!is_dir($parent)&&!@mkdir($parent,0755,true)&&!is_dir($parent))return ['ok'=>false,'code'=>'UPDATE_DIR_CREATE_FAILED','path'=>$path];
    $tmp=$parent.'/.kicom-update-'.$tx.'-'.basename($path);
    if(@file_put_contents($tmp,$content,LOCK_EX)===false)return ['ok'=>false,'code'=>'UPDATE_TEMP_WRITE_FAILED','path'=>$path];
    @chmod($tmp,0644);
    if(!@rename($tmp,$full)){@unlink($tmp);return ['ok'=>false,'code'=>'UPDATE_RENAME_FAILED','path'=>$path];}
    clearstatcache(true,$full);if(function_exists('opcache_invalidate'))@opcache_invalidate($full,true);
    return ['ok'=>true,'sha256'=>hash('sha256',$content)];
}
function kicomSelfUpdateSnapshot(array $installFiles): array {
    $rows=[];$total=0;
    foreach($installFiles as $path=>$e){
        $full=kicomBaseDir().'/'.$path;$raw=is_file($full)?@file_get_contents($full):false;
        if($raw===false){$before='NEW';$b64='';$bytes=0;}else{$before=hash('sha256',(string)$raw);$b64=base64_encode((string)$raw);$bytes=strlen((string)$raw);}
        $total+=$bytes;if($total>KICOM_MAX_SELF_UPDATE_BACKUP_BYTES)return ['ok'=>false,'code'=>'UPDATE_BACKUP_TOO_LARGE'];
        $rows[]=['path'=>$path,'before_sha256'=>$before,'before_content_b64'=>$b64,'after_sha256'=>(string)$e['sha256']];
    }
    return ['ok'=>true,'files'=>$rows,'bytes'=>$total];
}
function kicomRestoreSelfUpdateSnapshot(array $rows,string $tx): array {
    foreach(array_reverse($rows) as $r){
        $path=kicomSafeUpdatePath((string)($r['path']??''));if($path===null)return ['ok'=>false,'code'=>'ROLLBACK_PATH_INVALID'];
        $full=kicomBaseDir().'/'.$path;$before=(string)($r['before_sha256']??'');
        if($before==='NEW'){if(is_file($full)&&!@unlink($full))return ['ok'=>false,'code'=>'ROLLBACK_DELETE_FAILED','path'=>$path];clearstatcache(true,$full);if(function_exists('opcache_invalidate'))@opcache_invalidate($full,true);continue;}
        $raw=base64_decode((string)($r['before_content_b64']??''),true);if($raw===false||!hash_equals($before,hash('sha256',$raw)))return ['ok'=>false,'code'=>'ROLLBACK_BACKUP_INVALID','path'=>$path];
        $w=kicomSelfUpdateAtomicWrite($path,$raw,'rb-'.$tx);if(!$w['ok'])return $w;
    }
    return ['ok'=>true];
}
function kicomSelfUpdateVerifyInstalled(array $installFiles): array {
    foreach($installFiles as $path=>$e){$full=kicomBaseDir().'/'.$path;if(!is_file($full))return ['ok'=>false,'code'=>'INSTALLED_FILE_MISSING','path'=>$path];$sha=hash_file('sha256',$full)?:'';if(!hash_equals((string)$e['sha256'],$sha))return ['ok'=>false,'code'=>'INSTALLED_HASH_MISMATCH','path'=>$path];}
    return ['ok'=>true];
}
function kicomSelfHealthUrl(): ?string {
    $host=(string)($_SERVER['HTTP_HOST']??$_SERVER['SERVER_NAME']??'');
    if($host===''||!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/',$host))return null;
    $https=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https';
    $local=(bool)preg_match('/^(localhost|127\\.0\\.0\\.1)(?::[0-9]+)?$/i',$host);
    if(!$https&&!$local)return null;$scheme=$https?'https':'http';
    $script=(string)($_SERVER['SCRIPT_NAME']??'/admin.php');$dir=rtrim(str_replace('\\','/',dirname($script)),'/');if($dir==='.'||$dir==='/')$dir='';
    return $scheme.'://'.$host.$dir.'/?q=HELLO';
}
function kicomSelfHttpGet(string $url): array {
    if(function_exists('curl_init')){
        $ch=curl_init($url);if($ch!==false){
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>KICOM_HEALTH_TIMEOUT,CURLOPT_TIMEOUT=>KICOM_HEALTH_TIMEOUT,CURLOPT_USERAGENT=>'KiCom-SelfUpdate/'.KICOM_VERSION,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
            $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);curl_close($ch);
            return ['body'=>is_string($body)?substr($body,0,4096):false,'http_code'=>$code,'transport'=>'curl','transport_error'=>$errno?:null];
        }
    }
    $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>KICOM_HEALTH_TIMEOUT,'ignore_errors'=>true,'header'=>"User-Agent: KiCom-SelfUpdate/".KICOM_VERSION."\r\nConnection: close\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $body=@file_get_contents($url,false,$ctx,0,4096);$headers=$http_response_header??[];$code=0;foreach($headers as $h)if(preg_match('~^HTTP/\S+\s+(\d{3})~i',$h,$m)){$code=(int)$m[1];break;}
    return ['body'=>$body,'http_code'=>$code,'transport'=>'stream','transport_error'=>$body===false?'REQUEST_FAILED':null];
}
function kicomSelfHealthCheckVersion(string $version): array {
    $url=kicomSelfHealthUrl();if($url===null)return ['ok'=>false,'code'=>'SELF_HEALTH_URL_UNAVAILABLE'];
    $last=['ok'=>false,'http_code'=>0,'url_host'=>(string)parse_url($url,PHP_URL_HOST),'code'=>'SELF_HEALTHCHECK_FAILED','attempts'=>0,'actual_version'=>null];
    for($attempt=1;$attempt<=5;$attempt++){
        $resp=kicomSelfHttpGet($url);$body=$resp['body'];$code=(int)$resp['http_code'];
        $actual=null;if(is_string($body)&&preg_match('/FACT version="([^"]+)"/',$body,$vm))$actual=$vm[1];
        $ok=$code>=200&&$code<300&&is_string($body)&&str_contains($body,'OK hello')&&$actual===$version;
        $last=['ok'=>$ok,'http_code'=>$code,'url_host'=>(string)parse_url($url,PHP_URL_HOST),'code'=>$ok?null:'SELF_HEALTHCHECK_FAILED','attempts'=>$attempt,'actual_version'=>$actual,'transport'=>$resp['transport'],'transport_error'=>$resp['transport_error']];
        if($ok)return $last;if($attempt<5)usleep(750000);
    }
    return $last;
}
function kicomSelfUpdateHistoryRecord(array $row): ?string {
    try{$id=gmdate('YmdHis').'-'.bin2hex(random_bytes(4));}catch(Throwable $e){$id=gmdate('YmdHis').'-'.substr(hash('sha256',uniqid('',true)),0,8);}
    $row=['id'=>$id,'created_at'=>gmdate('c')]+$row;$json=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($json===false||@file_put_contents(kicomSelfUpdateHistoryDir().'/'.$id.'.json',$json,LOCK_EX)===false)return null;@chmod(kicomSelfUpdateHistoryDir().'/'.$id.'.json',0600);
    $files=glob(kicomSelfUpdateHistoryDir().'/*.json')?:[];usort($files,fn($a,$b)=>(@filemtime($b)?:0)<=>(@filemtime($a)?:0));foreach(array_slice($files,KICOM_MAX_SELF_UPDATE_HISTORY) as $old)@unlink($old);
    return $id;
}
function kicomSelfUpdateHistory(): array {
    $rows=[];foreach(glob(kicomSelfUpdateHistoryDir().'/*.json')?:[] as $f){$r=json_decode((string)@file_get_contents($f),true);if(is_array($r))$rows[]=$r;}usort($rows,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));return $rows;
}
function kicomApplySelfUpdateUnlocked(array $pending): array {
    $pkg=kicomSelfUpdatePackagePath($pending);if($pkg===null)return ['ok'=>false,'code'=>'PACKAGE_NOT_FOUND'];
    if(!hash_equals((string)($pending['from_version']??''),KICOM_VERSION))return ['ok'=>false,'code'=>'CURRENT_VERSION_CHANGED','current_version'=>KICOM_VERSION];
    $check=kicomSelfUpdateZipInspect($pkg);if(!$check['ok'])return $check;
    if(!hash_equals((string)($pending['zip_sha256']??''),(string)$check['zip_sha256'])||!hash_equals((string)($pending['to_version']??''),(string)$check['version']))return ['ok'=>false,'code'=>'PENDING_PACKAGE_MISMATCH'];
    $snapshot=kicomSelfUpdateSnapshot($check['install_files']);if(!$snapshot['ok'])return $snapshot;
    try{$tx=strtolower(bin2hex(random_bytes(4)));}catch(Throwable $e){$tx=strtolower(kicomRequestId());}
    $order=array_keys($check['install_files']);$critical=['genome/genome.json','living.php','lib.php','index.php','api.php','admin.php','guardian.php','recovery.php','.htaccess','MANIFEST.sha256'];usort($order,function($a,$b)use($critical){$ia=array_search($a,$critical,true);$ib=array_search($b,$critical,true);$wa=$ia===false?0:100+(int)$ia;$wb=$ib===false?0:100+(int)$ib;return $wa<=>$wb;});
    $failure=null;
    foreach($order as $path){$e=$check['install_files'][$path];$w=kicomSelfUpdateAtomicWrite($path,(string)$e['content'],$tx);if(!$w['ok']){$failure=$w;break;}}
    if($failure===null){$v=kicomSelfUpdateVerifyInstalled($check['install_files']);if(!$v['ok'])$failure=$v;}
    if($failure===null){$h=kicomSelfHealthCheckVersion((string)$check['version']);if(!$h['ok'])$failure=$h;}
    if($failure!==null){
        $rr=kicomRestoreSelfUpdateSnapshot($snapshot['files'],$tx);
        if($rr['ok'])kicomLivingCommitGenomeAfterInstall();
        $rh=$rr['ok']?kicomSelfHealthCheckVersion(KICOM_VERSION):['ok'=>false,'code'=>'ROLLBACK_NOT_APPLIED'];
        $restored=$rr['ok']&&($rh['ok']??false);
        $id=kicomSelfUpdateHistoryRecord(['action'=>'self_update','from_version'=>KICOM_VERSION,'to_version'=>$check['version'],'package_sha256'=>$check['zip_sha256'],'status'=>$restored?'rolled_back':'rollback_failed','failure'=>$failure,'rollback_result'=>$rr,'rollback_health'=>$rh]);
        kicomLivingEvent('self_update_failed',$restored?'warn':'critical',['from_version'=>KICOM_VERSION,'to_version'=>$check['version'],'failure_code'=>$failure['code']??'unknown','rollback_ok'=>$restored,'history_id'=>$id??'']);
        return ['ok'=>false,'code'=>$restored?'SELF_UPDATE_FAILED_ROLLED_BACK':'SELF_UPDATE_FAILED_ROLLBACK_FAILED','failure'=>$failure,'rollback_result'=>$rr,'rollback_health'=>$rh,'history_id'=>$id??''];
    }
    $genomeCommit=kicomLivingCommitGenomeAfterInstall();
    if(!$genomeCommit['ok']){
        $rr=kicomRestoreSelfUpdateSnapshot($snapshot['files'],$tx);if($rr['ok'])kicomLivingCommitGenomeAfterInstall();
        kicomLivingEvent('genome_promotion_failed','critical',['to_version'=>$check['version'],'rollback_ok'=>$rr['ok']??false]);
        return ['ok'=>false,'code'=>'GENOME_PROMOTION_FAILED','genome_result'=>$genomeCommit,'rollback_result'=>$rr];
    }
    $evoMeta=is_array($check['genome']['evolution']??null)?$check['genome']['evolution']:[];$evolutionary=!empty($evoMeta['counts_as_generation'])||str_starts_with((string)($pending['source']??''),'evolution:');
    $historyRow=['action'=>'self_update','from_version'=>KICOM_VERSION,'to_version'=>$check['version'],'package_sha256'=>$check['zip_sha256'],'manifest_sha256'=>$check['manifest_sha256'],'genome_id'=>(string)($check['genome']['id']??''),'kernel_update'=>(bool)($check['kernel_update']??false),'source'=>(string)($pending['source']??'unknown'),'risk_class'=>(string)($pending['risk_class']??'red'),'risk_reasons'=>$pending['risk_reasons']??[],'package_file'=>basename($pkg),'status'=>'installed','files_count'=>count($check['install_files']),'snapshot'=>$snapshot['files'],'evolutionary'=>$evolutionary];
    $id=kicomSelfUpdateHistoryRecord($historyRow);$historyRow['id']=$id??'';$historyRow['created_at']=gmdate('c');
    @unlink(kicomSelfUpdatePendingFile());
    kicomLivingEvent('self_update_installed','info',['from_version'=>KICOM_VERSION,'to_version'=>$check['version'],'genome_id'=>$check['genome']['id']??'','history_id'=>$id??'','source'=>(string)($pending['source']??'unknown'),'risk_class'=>(string)($pending['risk_class']??'red'),'evolutionary'=>$evolutionary]);
    if($evolutionary)kicomEvolutionObserveInstalledUpdate($historyRow,true);
    if(function_exists('kicomArchiveReleasePackage')){ $ar=kicomArchiveReleasePackage($pkg,$check,['source'=>(string)($pending['source']??'unknown'),'risk_class'=>(string)($pending['risk_class']??'')]); if(empty($ar['ok']))kicomLivingEvent('release_archive_failed','warn',['version'=>$check['version'],'code'=>$ar['code']??'UNKNOWN']); }
    if(function_exists('kicomPrimaryFeedPublishPackage')){ $pf=kicomPrimaryFeedPublishPackage($pkg,$check,['source'=>(string)($pending['source']??'unknown'),'risk_class'=>(string)($pending['risk_class']??'')]); if(empty($pf['ok']))kicomLivingEvent('primary_publish_failed','warn',['version'=>$check['version'],'code'=>$pf['code']??'UNKNOWN']); }
    return ['ok'=>true,'from_version'=>KICOM_VERSION,'to_version'=>$check['version'],'history_id'=>$id??'','files_count'=>count($check['install_files'])];
}
function kicomRollbackSelfUpdateUnlocked(string $historyId): array {
    $id=trim($historyId);if(!preg_match('/^[0-9]{14}-[a-f0-9]{8}$/',$id))return ['ok'=>false,'code'=>'HISTORY_ID_INVALID'];$file=kicomSelfUpdateHistoryDir().'/'.$id.'.json';if(!is_file($file))return ['ok'=>false,'code'=>'HISTORY_NOT_FOUND'];$h=json_decode((string)@file_get_contents($file),true);
    if(!is_array($h)||($h['action']??'')!=='self_update'||($h['status']??'')!=='installed'||!is_array($h['snapshot']??null))return ['ok'=>false,'code'=>'HISTORY_NOT_ROLLBACKABLE'];
    if(!hash_equals((string)($h['to_version']??''),KICOM_VERSION))return ['ok'=>false,'code'=>'CURRENT_VERSION_CHANGED','current_version'=>KICOM_VERSION];
    foreach($h['snapshot'] as $r){$path=(string)($r['path']??'');$full=kicomBaseDir().'/'.$path;$cur=is_file($full)?(hash_file('sha256',$full)?:''):'NEW';if(!hash_equals((string)($r['after_sha256']??''),$cur))return ['ok'=>false,'code'=>'ROLLBACK_CONFLICT','path'=>$path,'current_sha256'=>$cur];}
    try{$tx=strtolower(bin2hex(random_bytes(4)));}catch(Throwable $e){$tx=strtolower(kicomRequestId());}
    $rr=kicomRestoreSelfUpdateSnapshot($h['snapshot'],$tx);
    if(!$rr['ok']){
        $pkgName=(string)($h['package_file']??'');$pkgPath=preg_match('/^[a-f0-9]{64}\.zip$/',$pkgName)?kicomSelfUpdatePackagesDir().'/'.$pkgName:'';$reapply=['ok'=>false,'code'=>'REAPPLY_PACKAGE_UNAVAILABLE'];
        if($pkgPath!==''&&is_file($pkgPath)){$chk=kicomSelfUpdateZipInspect($pkgPath);if($chk['ok']&&(string)$chk['version']===(string)$h['to_version']){$reapply=['ok'=>true];foreach($chk['install_files'] as $path=>$e){$w=kicomSelfUpdateAtomicWrite($path,(string)$e['content'],'reapply-'.$tx);if(!$w['ok']){$reapply=$w;break;}}}}
        kicomLivingEvent('self_update_rollback_failed','critical',['source_update'=>$id,'reapplied'=>$reapply['ok']??false]);
        return ['ok'=>false,'code'=>$reapply['ok']?'ROLLBACK_FAILED_REAPPLIED':'ROLLBACK_FAILED_REAPPLY_FAILED','rollback_result'=>$rr,'reapply_result'=>$reapply];
    }
    $health=kicomSelfHealthCheckVersion((string)$h['from_version']);
    if(!$health['ok']){
        $pkgName=(string)($h['package_file']??'');$pkgPath=preg_match('/^[a-f0-9]{64}\.zip$/',$pkgName)?kicomSelfUpdatePackagesDir().'/'.$pkgName:'';$reapply=['ok'=>false,'code'=>'REAPPLY_PACKAGE_UNAVAILABLE'];
        if($pkgPath!==''&&is_file($pkgPath)){$chk=kicomSelfUpdateZipInspect($pkgPath);if($chk['ok']&&(string)$chk['version']===(string)$h['to_version']){$reapply=['ok'=>true];foreach($chk['install_files'] as $path=>$e){$w=kicomSelfUpdateAtomicWrite($path,(string)$e['content'],'reapply-'.$tx);if(!$w['ok']){$reapply=$w;break;}}if($reapply['ok'])$reapply['health']=kicomSelfHealthCheckVersion((string)$h['to_version']);}}
        $rid=kicomSelfUpdateHistoryRecord(['action'=>'self_update_rollback','source_update'=>$id,'from_version'=>$h['to_version'],'to_version'=>$h['from_version'],'status'=>$reapply['ok']&&($reapply['health']['ok']??false)?'rollback_health_failed_reapplied':'rollback_health_failed_reapply_failed','health'=>$health,'reapply_result'=>$reapply]);
        kicomLivingEvent('self_update_rollback_health_failed','critical',['source_update'=>$id,'reapplied'=>$reapply['ok']&&($reapply['health']['ok']??false),'history_id'=>$rid??'']);
        return ['ok'=>false,'code'=>$reapply['ok']&&($reapply['health']['ok']??false)?'ROLLBACK_HEALTHCHECK_FAILED_REAPPLIED':'ROLLBACK_HEALTHCHECK_FAILED_REAPPLY_FAILED','health'=>$health,'reapply_result'=>$reapply,'history_id'=>$rid??''];
    }
    $genomeRollback=kicomLivingCommitGenomeAfterInstall();
    if(!$genomeRollback['ok'])return ['ok'=>false,'code'=>'ROLLBACK_GENOME_REPIN_FAILED','genome_result'=>$genomeRollback];
    $rid=kicomSelfUpdateHistoryRecord(['action'=>'self_update_rollback','source_update'=>$id,'from_version'=>$h['to_version'],'to_version'=>$h['from_version'],'status'=>'rolled_back']);
    kicomLivingEvent('self_update_rolled_back','warn',['source_update'=>$id,'from_version'=>$h['to_version'],'to_version'=>$h['from_version'],'history_id'=>$rid??'']);
    return ['ok'=>true,'from_version'=>$h['to_version'],'to_version'=>$h['from_version'],'history_id'=>$rid??''];
}
function kicomApplySelfUpdate(array $pending): array {
    $target=(string)($pending['to_version']??'');
    if(!kicomImmuneMaintenanceBegin('self_update',$target)) return ['ok'=>false,'code'=>'MAINTENANCE_LOCK_FAILED'];
    try { return kicomApplySelfUpdateUnlocked($pending); }
    finally { kicomImmuneMaintenanceEnd(); }
}
function kicomRollbackSelfUpdate(string $historyId): array {
    if(!kicomImmuneMaintenanceBegin('self_update_rollback','')) return ['ok'=>false,'code'=>'MAINTENANCE_LOCK_FAILED'];
    try { return kicomRollbackSelfUpdateUnlocked($historyId); }
    finally { kicomImmuneMaintenanceEnd(); }
}
/* ---- end self-update controller ----------------------------------------- */

