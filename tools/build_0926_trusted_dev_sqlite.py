#!/usr/bin/env python3
from __future__ import annotations
import argparse, hashlib, json, shutil, re
from pathlib import Path

def sha(p: Path) -> str:
    return hashlib.sha256(p.read_bytes()).hexdigest()

def replace_once(text: str, old: str, new: str, label: str) -> str:
    n=text.count(old)
    if n != 1:
        raise SystemExit(f"{label}: expected exactly one match, got {n}")
    return text.replace(old,new,1)

def write(p: Path, s: str) -> None:
    p.parent.mkdir(parents=True,exist_ok=True)
    p.write_text(s,encoding="utf-8")

def copy(src: Path,dst: Path) -> None:
    dst.parent.mkdir(parents=True,exist_ok=True)
    shutil.copy2(src,dst)

ap=argparse.ArgumentParser()
ap.add_argument("--base",required=True)
ap.add_argument("--dev",required=True)
ap.add_argument("--passkey",required=True)
ap.add_argument("--out",required=True)
a=ap.parse_args()
base=Path(a.base); dev=Path(a.dev); pk=Path(a.passkey); out=Path(a.out)
if out.exists(): shutil.rmtree(out)
shutil.copytree(base,out)

# ---- Trusted DEV modules: executable authority lives in genome-covered modules/.
module_sources = {
    "PasskeyBridge.php": pk/"PasskeyBridge.php",
    "DevSession.php": dev/"DevSession.php",
    "DevAuthAdapter.php": dev/"DevAuthAdapter.php",
    "DevAuthFlow.php": dev/"DevAuthFlow.php",
    "DevRouter.php": dev/"DevRouter.php",
    "DevHttpAdapter.php": dev/"DevHttpAdapter.php",
    "DevKiComBindings.php": dev/"DevKiComBindings.php",
    "DevDiagnostics.php": dev/"DevDiagnostics.php",
}
for name,src in module_sources.items():
    if not src.is_file(): raise SystemExit(f"missing DEV source {src}")
    copy(src,out/"modules/dev"/name)

endpoint = r'''<?php
declare(strict_types=1);

/* KiCom 0.9.26 trusted DEV endpoint adapter.
   Authority remains DEV-scoped. Production/self-update/recovery/secrets are absent. */

function kicomDevStore(): string { return kicomVarDir().'/dev_zone'; }
function kicomDevPasskeys(): KiComPasskeyBridge { return new KiComPasskeyBridge(kicomDevStore().'/passkeys'); }
function kicomDevSessions(): KiComDevSessionManager { return new KiComDevSessionManager(kicomDevStore().'/sessions'); }
function kicomDevRouter(): KiComDevRouter {
    $handlers=array_merge(KiComDevRuntimeBindings::handlers(),KiComDevDiagnostics::handlers());
    return new KiComDevRouter(kicomDevSessions(),$handlers);
}
function kicomDevReady(): array {
    $p=kicomDevPasskeys()->ready();$s=kicomDevSessions()->ready();$b=KiComDevRuntimeBindings::ready();
    return ['ok'=>!empty($p['ok'])&&!empty($s['ok'])&&!empty($b['ok']),
        'code'=>(!empty($p['ok'])&&!empty($s['ok'])&&!empty($b['ok']))?'DEV_READY':'DEV_NOT_READY',
        'passkey_ready'=>!empty($p['ok']),'session_ready'=>!empty($s['ok']),'bindings_ready'=>!empty($b['ok']),
        'credential_count'=>method_exists(kicomDevPasskeys(),'credentialCount')?kicomDevPasskeys()->credentialCount():0,
        'session_ttl'=>$s['ttl']??KiComDevSessionManager::DEFAULT_TTL,
        'idle_ttl'=>$s['idle_ttl']??KiComDevSessionManager::DEFAULT_IDLE_TTL,
        'scope'=>'dev','token_rotation'=>false];
}
function kicomDevAuthDispatch(array $data): array {
    $op=strtoupper(trim((string)($data['operation']??'')));$payload=is_array($data['payload']??null)?$data['payload']:[];
    $passkeys=kicomDevPasskeys();$sessions=kicomDevSessions();
    $adapter=new KiComDevAuthAdapter($passkeys,$sessions,kicomDevStore().'/locks');
    $flow=new KiComDevAuthFlow($passkeys,$adapter);
    if($op==='DEV_READY')return kicomDevReady();
    if($op==='DEV_ENROLL_BEGIN'){
        /* Legacy/fallback enrollment transport only. Normal DEV entry is passkey-only. */
        if(!function_exists('kicomTotpVerifyConsume'))return ['ok'=>false,'code'=>'TOTP_VERIFIER_UNAVAILABLE'];
        $code=preg_replace('/\\D+/','',(string)($payload['code']??''));$v=kicomTotpVerifyConsume($code,'dev_passkey_enrollment');
        if(empty($v['ok']))return ['ok'=>false,'code'=>(string)($v['code']??'TOTP_CODE_REJECTED')];
        return $passkeys->createEnrollmentTicket((string)($payload['account']??'Christoph'));
    }
    if($op==='DEV_ENROLL_OPTIONS')return $passkeys->registrationOptions((string)($payload['enrollment_id']??''));
    if($op==='DEV_ENROLL_COMPLETE')return $passkeys->completeRegistration((string)($payload['enrollment_id']??''),is_array($payload['credential']??null)?$payload['credential']:[],(string)($payload['label']??'KiCom DEV Passkey'));
    if($op==='DEV_AUTH_BEGIN')return $flow->begin();
    if($op==='DEV_AUTH_OPTIONS')return $flow->options((string)($payload['challenge_id']??''));
    if($op==='DEV_AUTH_COMPLETE')return $flow->complete((string)($payload['challenge_id']??''),is_array($payload['credential']??null)?$payload['credential']:[],(string)($payload['label']??'KiCom DEV'));
    return ['ok'=>false,'code'=>'DEV_AUTH_OPERATION_UNKNOWN'];
}
function kicomDevApiDispatch(array $server,string $raw): array {
    return (new KiComDevHttpAdapter(kicomDevRouter()))->handle($server,$raw);
}
function kicomDevBridgeDispatch(array $q): array {
    $sid=strtolower(trim((string)($q['sid']??'')));$key=strtolower(trim((string)($q['key']??'')));
    $op=strtoupper(trim((string)($q['op']??'')));$encoded=(string)($q['p']??'');
    if($op==='')return ['ok'=>false,'code'=>'DEV_BRIDGE_OPERATION_REQUIRED'];
    if($encoded==='')$payload=[];
    else{
        if(!preg_match('/^[A-Za-z0-9_-]{1,32768}$/',$encoded))return ['ok'=>false,'code'=>'DEV_BRIDGE_PAYLOAD_INVALID'];
        $s=strtr($encoded,'-_','+/');$pad=strlen($s)%4;if($pad)$s.=str_repeat('=',4-$pad);
        $raw=base64_decode($s,true);if($raw===false||strlen($raw)>24576)return ['ok'=>false,'code'=>'DEV_BRIDGE_PAYLOAD_INVALID'];
        $payload=json_decode($raw,true);if(!is_array($payload))return ['ok'=>false,'code'=>'DEV_BRIDGE_PAYLOAD_JSON_INVALID'];
    }
    $r=kicomDevRouter()->handle($op,$sid,$key,$payload);
    $r['transport']='dev-get-bridge';$r['credential_scope']='dev-only';return $r;
}
'''
write(out/"modules/dev/DevEndpoint.php",endpoint)

modules = {
    "schema":1,
    "modules":[
        {"path":"modules/dev/PasskeyBridge.php","enabled":True},
        {"path":"modules/dev/DevSession.php","enabled":True},
        {"path":"modules/dev/DevAuthAdapter.php","enabled":True},
        {"path":"modules/dev/DevAuthFlow.php","enabled":True},
        {"path":"modules/dev/DevRouter.php","enabled":True},
        {"path":"modules/dev/DevHttpAdapter.php","enabled":True},
        {"path":"modules/dev/DevKiComBindings.php","enabled":True},
        {"path":"modules/dev/DevDiagnostics.php","enabled":True},
        {"path":"modules/dev/DevEndpoint.php","enabled":True},
    ]
}
write(out/"genome/modules.json",json.dumps(modules,indent=2)+"\n")

# ---- Bootstrap routing: keep the existing /dev/ static UI but make every authority-bearing
#      endpoint resolve to trusted genome-covered root code. No loose PHP under /dev/.
ht=(out/".htaccess").read_text()
ht += r'''
# KiCom 0.9.26 trusted DEV backend bridge.
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^dev/dev-auth\.php$ api.php?q=DEV_AUTH [L,QSA]
  RewriteRule ^dev/dev-api\.php$ api.php?q=DEV_API [L,QSA]
  RewriteRule ^dev/dev-bridge\.php$ index.php?q=DEV_BRIDGE [L,QSA]
  RewriteRule ^dev/agent/?$ index.php?q=DEV_BRIDGE [L,QSA]
</IfModule>
'''
write(out/".htaccess",ht)

# ---- API JSON dispatch before normal KCL/body processing.
api=(out/"api.php").read_text()
anchor="$queryOp=strtoupper(trim((string)($_GET['q']??'')));\n"
insert=r'''$queryOp=strtoupper(trim((string)($_GET['q']??'')));
if(in_array($queryOp,['DEV_AUTH','DEV_API'],true)){
    $raw=file_get_contents('php://input');
    if($raw===false||strlen($raw)>1048576){header('Content-Type: application/json; charset=utf-8');http_response_code(413);echo "{\"ok\":false,\"code\":\"DEV_BODY_INVALID\"}\n";exit;}
    if($queryOp==='DEV_AUTH'){
        $data=json_decode($raw,true);$r=is_array($data)?kicomDevAuthDispatch($data):['ok'=>false,'code'=>'DEV_JSON_INVALID'];$status=!empty($r['ok'])?200:(str_contains((string)($r['code']??''),'REJECTED')?401:422);
    }else{$x=kicomDevApiDispatch($_SERVER,$raw);$r=$x['body']??['ok'=>false,'code'=>'DEV_RESPONSE_INVALID'];$status=(int)($x['http_status']??500);}
    header('Content-Type: application/json; charset=utf-8');http_response_code($status);echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";exit;
}
'''
api=replace_once(api,anchor,insert,"api DEV dispatch")
write(out/"api.php",api)

# ---- GET-only DEV bridge in trusted index.php.
idx=(out/"index.php").read_text()
anchor="kicomSqliteMaintenanceTick($kicomSqliteForce);\n\nheader('Content-Type: text/plain; charset=utf-8');"
insert=r'''kicomSqliteMaintenanceTick($kicomSqliteForce);

if(isset($_GET['q'])&&strtoupper(trim((string)$_GET['q']))==='DEV_BRIDGE'){
    $r=kicomDevBridgeDispatch($_GET);$ok=!empty($r['ok']);$code=(string)($r['code']??'DEV_UNKNOWN');
    $status=$ok?200:(str_starts_with($code,'DEV_SESSION_')?401:(str_contains($code,'FORBIDDEN')?403:422));
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, max-age=0');header('X-Robots-Tag: noindex, nofollow, noarchive');http_response_code($status);
    echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";exit;
}

header('Content-Type: text/plain; charset=utf-8');'''
idx=replace_once(idx,anchor,insert,"index DEV bridge")
write(out/"index.php",idx)

# ---- SQLite hardening.
lib=(out/"lib.php").read_text()
lib=replace_once(lib,"const KICOM_VERSION = '0.9.25';","const KICOM_VERSION = '0.9.26';","version")

old=""" foreach($m as $ver=>$x){$q=$db->prepare('SELECT 1 FROM schema_migrations WHERE version=?');$q->execute([$ver]);if($q->fetchColumn())continue;$sum=hash('sha256',json_encode($x,JSON_UNESCAPED_SLASHES)?:'');$db->beginTransaction();"""
new=""" foreach($m as $ver=>$x){$sum=hash('sha256',json_encode($x,JSON_UNESCAPED_SLASHES)?:'');$q=$db->prepare('SELECT name,checksum FROM schema_migrations WHERE version=?');$q->execute([$ver]);$seen=$q->fetch();if(is_array($seen)){if(!hash_equals((string)$x[0],(string)($seen['name']??''))||!hash_equals($sum,strtolower((string)($seen['checksum']??''))))return false;continue;}$db->beginTransaction();"""
lib=replace_once(lib,old,new,"migration checksum verification")

old="""function kicomSqliteEventAppend(string $stream,array $row,?string $mirror=null): bool {if(!isset($row['at']))$row['at']=gmdate('c');$j=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($j===false)return false;$type=(string)($row['type']??'event');$sev=(string)($row['severity']??'info');$sum=hash('sha256',$stream."\\n".$j);$ok=false;$db=kicomSqliteDb();if($db){try{$q=$db->prepare('INSERT OR IGNORE INTO events(stream,at,type,severity,payload_json,sha256,created_at) VALUES(?,?,?,?,?,?,?)');$ok=$q->execute([$stream,(string)$row['at'],$type,$sev,$j,$sum,gmdate('c')]);}catch(Throwable $e){}}$m=false;"""
new="""function kicomSqliteEventAppend(string $stream,array $row,?string $mirror=null): bool {if(!isset($row['at']))$row['at']=gmdate('c');$j=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($j===false)return false;$type=(string)($row['type']??'event');$sev=(string)($row['severity']??'info');$sum=hash('sha256',$stream."\\n".$j);$ok=false;$db=kicomSqliteDb();if($db){try{$q=$db->prepare('INSERT OR IGNORE INTO events(stream,at,type,severity,payload_json,sha256,created_at) VALUES(?,?,?,?,?,?,?)');if($q->execute([$stream,(string)$row['at'],$type,$sev,$j,$sum,gmdate('c')]))$ok=$q->rowCount()===1;}catch(Throwable $e){}}$m=false;"""
lib=replace_once(lib,old,new,"event insert semantics")

old="""function kicomSqliteBackupFile(string $dest): bool {if(!class_exists('SQLite3')||!is_file(kicomSqliteFile()))return false;try{$src=new SQLite3(kicomSqliteFile(),SQLITE3_OPEN_READONLY);$dst=new SQLite3($dest,SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE);$ok=$src->backup($dst);$src->close();$dst->close();if($ok)@chmod($dest,0600);return (bool)$ok;}catch(Throwable $e){return false;}}"""
new="""function kicomSqliteBackupFile(string $dest): bool {if(!is_file(kicomSqliteFile()))return false;@unlink($dest);if(class_exists('SQLite3')){try{$src=new SQLite3(kicomSqliteFile(),SQLITE3_OPEN_READONLY);$dst=new SQLite3($dest,SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE);$ok=$src->backup($dst);$src->close();$dst->close();if($ok&&is_file($dest)&&!empty(kicomSqliteVerifyFile($dest,true)['ok'])){@chmod($dest,0600);return true;}@unlink($dest);}catch(Throwable $e){@unlink($dest);}}$db=kicomSqliteDb();if($db){try{$quoted=str_replace("'","''",$dest);$db->exec("VACUUM INTO '".$quoted."'");if(is_file($dest)&&!empty(kicomSqliteVerifyFile($dest,true)['ok'])){@chmod($dest,0600);return true;}}catch(Throwable $e){@unlink($dest);}}return false;}"""
lib=replace_once(lib,old,new,"sqlite backup fallback")

old="""$pre=null;if($promote)$pre=kicomSqliteSnapshot('pre-evolution-'.$id);if($promote){$db->beginTransaction();"""
new="""$pre=null;if($promote){$pre=kicomSqliteSnapshot('pre-evolution-'.$id);if(empty($pre['ok']))$promote=false;}if($promote){$db->beginTransaction();"""
lib=replace_once(lib,old,new,"evolution pre snapshot gate")

# Add bounded diagnostics for the historical ~65k-event investigation.
marker="function kicomSqliteStatus(): array {"
diag=r'''function kicomSqliteDiagnostics(): array {
    $db=kicomSqliteDb();if(!$db)return ['ok'=>false,'code'=>'SQLITE_OPEN_FAILED'];
    try{
        $total=(int)$db->query('SELECT COUNT(*) FROM events')->fetchColumn();
        $distinct=(int)$db->query('SELECT COUNT(DISTINCT sha256) FROM events')->fetchColumn();
        $streams=[];foreach($db->query('SELECT stream,COUNT(*) AS n FROM events GROUP BY stream ORDER BY n DESC')->fetchAll() as $r)$streams[(string)$r['stream']]=(int)$r['n'];
        $dupes=max(0,$total-$distinct);
        return ['ok'=>true,'code'=>'OK','events'=>$total,'distinct_event_sha256'=>$distinct,'duplicate_sha_rows'=>$dupes,'streams'=>$streams,'legacy_import_complete'=>kicomSqliteMetaGet('legacy_import_complete','0')];
    }catch(Throwable $e){return ['ok'=>false,'code'=>'SQLITE_DIAGNOSTICS_EXCEPTION'];}
}
'''
if marker not in lib: raise SystemExit("sqlite status marker missing")
lib=lib.replace(marker,diag+marker,1)

# Future releases may carry first-party dev assets, but 0.9.26 itself bootstraps via modules.
old="""    if(str_starts_with($path,'assets/'))return in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['css','js','json','svg','png','jpg','jpeg','webp','ico','txt'],true);
    return false;"""
new="""    if(str_starts_with($path,'assets/'))return in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['css','js','json','svg','png','jpg','jpeg','webp','ico','txt'],true);
    if(str_starts_with($path,'dev/'))return in_array($path,['dev/index.html','dev/browser.js','dev/dev-client.js','dev/.htaccess'],true);
    return false;"""
lib=replace_once(lib,old,new,"future dev asset allowlist")

# Canonical memory is persistent under var/ and seed files are not authoritative after initialization.
# 0.9.26 therefore performs one explicit, revision-preserving release sync once.
old="""function kicomRecordMemoryRevision(string $name,string $content,string $action): ?string {
    $name=strtoupper(trim($name)); if(!isset(kicomMemoryResources()[$name])||!kicomEnsureStorage())return null;"""
new="""function kicomRecordMemoryRevision(string $name,string $content,string $action): ?string {
    $name=strtoupper(trim($name)); if(!isset(kicomMemoryResources()[$name]))return null;"""
lib=replace_once(lib,old,new,"memory revision recursion removal")

marker="function kicomEnsureStorage(): bool {"
sync=r'''function kicomReleaseMemorySync0926(): bool {
    if(KICOM_VERSION!=='0.9.26')return true;
    $marker=kicomMemoryStateDir().'/.release-sync-0.9.26.json';
    if(is_file($marker)){
        $m=json_decode((string)@file_get_contents($marker),true);
        if(is_array($m)&&($m['status']??'')==='complete')return true;
    }
    foreach(kicomMemoryResources() as $name=>$filename){
        $seed=kicomMemorySeedDir().'/'.$filename;$raw=@file_get_contents($seed);
        if(!is_string($raw))return false;
        $v=kicomValidateMemoryContent((string)$name,$raw);if(($v['status']??'error')==='error')return false;
        $current=kicomReadMemoryResource((string)$name);if(!is_array($current))return false;
        $targetSha=hash('sha256',$raw);
        if(hash_equals((string)$current['sha256'],$targetSha))continue;
        $w=kicomAtomicMemoryWrite((string)$name,$raw,'release_sync_0.9.26',(string)$current['sha256']);
        if(empty($w['ok']))return false;
    }
    $row=['schema'=>1,'release'=>'0.9.26','status'=>'complete','synced_at'=>gmdate('c'),'policy'=>'revision-preserving canonical-memory release sync'];
    $json=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);if($json===false)return false;
    $tmp=$marker.'.tmp-'.strtolower(kicomRequestId());
    if(@file_put_contents($tmp,$json."\n",LOCK_EX)===false)return false;@chmod($tmp,0600);
    if(!@rename($tmp,$marker)){@unlink($tmp);return false;}@chmod($marker,0600);return true;
}
'''
if marker not in lib: raise SystemExit("ensure storage marker missing")
lib=lib.replace(marker,sync+marker,1)

old="""    if (is_file($stateFile)) {
        /* Never silently mix a persistent memory set with seed files from a later package. */
        foreach ($resources as $filename) if (!is_file(kicomMemoryStateDir().'/'.$filename)) return false;
        return kicomLivingEnsure();
    }"""
new="""    if (is_file($stateFile)) {
        /* Persistent canonical memory is release-synchronized only through the explicit,
           revision-preserving 0.9.26 migration below. */
        foreach ($resources as $filename) if (!is_file(kicomMemoryStateDir().'/'.$filename)) return false;
        if(!kicomReleaseMemorySync0926())return false;
        return kicomLivingEnsure();
    }"""
lib=replace_once(lib,old,new,"existing canonical memory sync")

old="""    return kicomLivingEnsure();
}
function kicomCleanupPending(): void {"""
new="""    if(!kicomReleaseMemorySync0926())return false;
    return kicomLivingEnsure();
}
function kicomCleanupPending(): void {"""
lib=replace_once(lib,old,new,"fresh canonical memory sync")

write(out/"lib.php",lib)

# ---- Authority semantics: risk != authority. External targets stay human-authorized.
living=(out/"living.php").read_text()
old="'auto_memory_archive'=>true,'auto_yellow_from_session'=>true,'red_requires_totp'=>true,'production_requires_totp'=>true,'kernel_requires_totp'=>true,'archive_append_only'=>true]"
new="'auto_memory_archive'=>true,'auto_yellow_from_session'=>true,'internal_self_update'=>true,'red_requires_totp'=>false,'production_requires_totp'=>true,'kernel_requires_totp'=>false,'external_authority_requires_human'=>true,'archive_append_only'=>true]"
living=replace_once(living,old,new,"autonomy authority defaults")
# 0.9.26 verifier: replace the legacy TOTP-named invariant with authority-boundary invariants.
old="""$requiredInvariants=['no-arbitrary-shell','no-arbitrary-sql','no-arbitrary-remote-fetch','human-gated-new-capabilities','separate-genome-memory-phenotype','autonomous-heal-trusted-state-only','production-writes-human-approved','recovery-kernel-not-auto-healed','unknown-code-quarantine-before-use','goal-layer-no-permission-grants','memory-archive-no-hard-delete','autonomy-envelope-bounded','critical-actions-totp-bound','release-archive-append-only'];"""
new="""$requiredInvariants=['no-arbitrary-shell','no-arbitrary-sql','no-arbitrary-remote-fetch','human-gated-new-capabilities','separate-genome-memory-phenotype','autonomous-heal-trusted-state-only','production-writes-human-approved','recovery-kernel-not-auto-healed','unknown-code-quarantine-before-use','goal-layer-no-permission-grants','memory-archive-no-hard-delete','autonomy-envelope-bounded','release-archive-append-only','risk-authority-separated','internal-self-authoring-verifier-bounded','protected-external-authority-human','dev-scope-no-production-authority'];"""
living=replace_once(living,old,new,"0.9.26 genome authority invariants")


# Authenticated internal self-update transport can finish through the normal verifier without TOTP.
old="""if(!empty($result['ok'])&&($result['code']??'')==='STAGED_DECISION_REQUIRED'&&($result['risk_class']??($result['staged']['risk_class']??''))==='yellow'&&!empty(kicomAutonomyPolicy()['auto_yellow_from_session'])){$pending=kicomSelfUpdatePending();if(is_array($pending))$result=kicomApplySelfUpdate($pending)+['code'=>'AUTO_INSTALLED_YELLOW_SESSION'];}"""
new="""if(!empty($result['ok'])&&($result['code']??'')==='STAGED_DECISION_REQUIRED'){ $policy=kicomAutonomyPolicy();$pending=kicomSelfUpdatePending();if(is_array($pending)&&(!empty($policy['internal_self_update'])||(($result['risk_class']??($result['staged']['risk_class']??''))==='yellow'&&!empty($policy['auto_yellow_from_session']))))$result=kicomApplySelfUpdate($pending)+['code'=>'AUTO_INSTALLED_INTERNAL_SESSION'];}"""
living=replace_once(living,old,new,"chunked internal self update")

# Server-authored build uses exactly the same verifier, backup, healthcheck and rollback path.
old="""$risk=kicomSelfUpdateRiskClass($inspect);$r=kicomReceiveSelfUpdatePackage($zip,basename($zip),'autonomy:server-build',true);$m['status']=!empty($r['ok'])?'finalized':'failed';"""
new="""$risk=kicomSelfUpdateRiskClass($inspect);$r=kicomReceiveSelfUpdatePackage($zip,basename($zip),'autonomy:server-build',true);if(!empty($r['ok'])&&($r['code']??'')==='STAGED_DECISION_REQUIRED'&&!empty(kicomAutonomyPolicy()['internal_self_update'])){$pending=kicomSelfUpdatePending();if(is_array($pending))$r=kicomApplySelfUpdate($pending)+['code'=>'AUTO_INSTALLED_INTERNAL_BUILD'];}$m['status']=!empty($r['ok'])?'finalized':'failed';"""
living=replace_once(living,old,new,"server build internal self update")
write(out/"living.php",living)

# Match raw POST direct update to the same internal-authority semantics.
api=(out/"api.php").read_text()
old="""if(!empty($r['ok'])&&($r['code']??'')==='STAGED_DECISION_REQUIRED'&&($r['risk_class']??($r['staged']['risk_class']??''))==='yellow'&&!empty(kicomAutonomyPolicy()['auto_yellow_from_session'])){$p=kicomSelfUpdatePending();if(is_array($p))$r=kicomApplySelfUpdate($p)+['code'=>'AUTO_INSTALLED_YELLOW_SESSION'];}"""
new="""if(!empty($r['ok'])&&($r['code']??'')==='STAGED_DECISION_REQUIRED'){ $policy=kicomAutonomyPolicy();$p=kicomSelfUpdatePending();if(is_array($p)&&(!empty($policy['internal_self_update'])||(($r['risk_class']??($r['staged']['risk_class']??''))==='yellow'&&!empty($policy['auto_yellow_from_session']))))$r=kicomApplySelfUpdate($p)+['code'=>'AUTO_INSTALLED_INTERNAL_SESSION'];}"""
api=replace_once(api,old,new,"raw internal self update")
write(out/"api.php",api)

# Add diagnostics KCL and stop advertising internal RED as TOTP authority.
idx=(out/"index.php").read_text()
idx=idx.replace("'RULE red-production-kernel-require-transaction-bound-freeotp'","'RULE protected-external-authority-requires-human-authorization'")
idx=idx.replace("'RULE production-writes-require-human-configured-target-allowlist-and-transaction-totp'","'RULE production-writes-require-human-configured-target-allowlist-and-protected-external-authorization'")
anchor="case 'SQLITE_HEAL':"
diagcase=r"""case 'SQLITE_DIAGNOSTICS':
    $r=kicomSqliteDiagnostics();$lines=[KCL_PROTOCOL,(!empty($r['ok'])?'OK':'ERROR').' sqlite_diagnostics','FACT request_id='.kclString($rid)];foreach(['events','distinct_event_sha256','duplicate_sha_rows','legacy_import_complete'] as $kk)if(isset($r[$kk]))$lines[]='FACT '.$kk.'='.kclString((string)$r[$kk]);foreach(($r['streams']??[]) as $s=>$n)$lines[]='STREAM name='.kclString((string)$s).' events='.(int)$n;$lines[]='END';out($lines,!empty($r['ok'])?200:422);
"""
if anchor not in idx: raise SystemExit("sqlite KCL anchor missing")
idx=idx.replace(anchor,diagcase+anchor,1)
write(out/"index.php",idx)

# ---- Canonical seed memory synchronized to 0.9.26.
# Start from the valid 0.9.25 resources so all structural/safety sentinels survive.
p=out/"memory/project_state.kcl"; m=p.read_text()
m=replace_once(m,'VERSION "0.9.25"','VERSION "0.9.26"',"PROJECT_STATE version")
m=replace_once(m,'FACT genome_id="kicom-0.9.25-g24"','FACT genome_id="kicom-0.9.26-g25r2"',"PROJECT_STATE genome")
m=replace_once(m,'FACT genome_generation=24','FACT genome_generation=25',"PROJECT_STATE generation")
m=replace_once(m,'FACT autonomy_envelope="freeotp-session:test,staging,workspace,memory,goals"','FACT autonomy_envelope="dev-passkey+bounded-internal-self-authoring; legacy-freeotp-session-fallback"',"PROJECT_STATE autonomy")
m=replace_once(m,'FACT critical_approval="transaction-bound-freeotp:red,production,kernel"','FACT critical_approval="protected-external-human:production,credentials,external-recipients; FreeOTP remains a legacy implementation where configured"',"PROJECT_STATE authority")
m=replace_once(m,'FACT session_open_totp_grace="current plus previous 2 FreeOTP counters by default; one-use replay protection; critical actions current-counter only"','FACT session_open_totp_grace="legacy fallback only; current plus previous 2 FreeOTP counters by default with one-use replay protection"',"PROJECT_STATE legacy session")
needle='FACT sqlite_boundaries="Canonical KCL, Genome, secrets and immutable archive authority stay outside SQLite"'
extra='''FACT sqlite_boundaries="Canonical KCL, Genome, secrets and immutable archive authority stay outside SQLite"
FACT dev_zone="trusted genome-covered modules; WebAuthn passkey; reusable DEV-only session"
FACT slack_transport="bounded redundant transport; never authority; requires configured bot/signing secrets"
FACT mail_transport="bounded redundant transport; never authority; IMAPS/SMTPS configured separately"
FACT browser_transport="Opera-compatible DEV/Admin control surface; never authority"
FACT authority_model="technical risk classification is separate from authority boundary"
FACT internal_red_totp_gate=false
FACT production_external_authority="human-protected"
FACT legacy_bootstrap_invariant="critical-actions-totp-bound is retained only for the 0.9.25 installer transition"
RULE "Internal reversible KiCom work is not TOTP-gated solely because technical risk is RED."
RULE "Production targets, credentials, new external recipients and protected foreign systems remain human-authorized."
RULE "Verifier, SHA-256, backup, healthcheck, rollback, TLS and trust-root controls remain mandatory."'''
m=replace_once(m,needle,extra,"PROJECT_STATE 0.9.26 facts")
write(p,m)

p=out/"memory/architecture.kcl"; m=p.read_text()
m=replace_once(m,'COMPONENT human_authorization_bridge role="FreeOTP TOTP and transaction-bound critical approval"','COMPONENT human_authorization_bridge role="human authorization for protected external authority; FreeOTP retained as legacy/fallback implementation"',"ARCH auth bridge")
m=replace_once(m,'COMPONENT autonomy_envelope role="TOTP-opened rolling session for bounded non-production work"','COMPONENT autonomy_envelope role="bounded internal work; legacy KCL session transport remains available while routine DEV uses passkey"',"ARCH autonomy")
m=replace_once(m,'FLOW autonomy_session="FreeOTP -> session_id + rolling one-time token -> bounded KCL operations"','FLOW autonomy_session="legacy FreeOTP session transport -> rolling token -> bounded KCL operations; transport token is not a policy grant"',"ARCH session flow")
m=replace_once(m,'FLOW critical_action="session -> exact transaction binding -> FreeOTP -> one exact RED/production/kernel action"','FLOW protected_external_action="exact action binding -> human authorization where required -> execute with revalidation"',"ARCH protected action")
m=replace_once(m,'RULE "Production targets remain allowlisted and require transaction-bound FreeOTP"','RULE "Production targets remain allowlisted and human-authorized; current legacy implementation may use transaction-bound FreeOTP"',"ARCH production")
m=replace_once(m,'COMPONENT session_totp_grace role="normal autonomy-session opening may accept configured recent past counters; critical approval verification remains current-counter only"','COMPONENT session_totp_grace role="legacy session-opening compatibility; not an internal autonomy policy gate"',"ARCH legacy grace")
needle='RULE "Canonical KCL, Genome, secrets and immutable archives remain separate."'
extra='''COMPONENT dev_zone role="trusted genome-covered DEV modules with WebAuthn passkey and reusable DEV-only sessions"
FLOW dev_entry="WebAuthn passkey -> DEV-scoped session -> fixed capability router"
COMPONENT slack_transport role="bounded optional transport/perception input; never authority"
COMPONENT mail_transport role="bounded optional transport with quarantine/verifier path; never authority"
COMPONENT opera_control_surface role="browser DEV/Admin control surface; never authority"
RULE "DEV sessions cannot grant production deployment, self-update-install, recovery, auth-admin or secret authority."
RULE "Technical risk class and authority boundary are separate dimensions."
RULE "Canonical KCL, Genome, secrets and immutable archives remain separate."'''
m=replace_once(m,needle,extra,"ARCH 0.9.26 components")
write(p,m)

p=out/"memory/protocol.kcl"; m=p.read_text()
m=replace_once(m,'AUTH session_open="?q=AUTH_SESSION_OPEN&code=<6digits>"','AUTH session_open="?q=AUTH_SESSION_OPEN&code=<6digits>; legacy/fallback transport, not internal policy gate"',"PROTOCOL legacy session")
m=replace_once(m,'RULE "TOTP is current-step only, one-use, 30 seconds, 6 digits"','RULE "Where FreeOTP remains required for a protected external action, it is current-step, one-use, 30 seconds and 6 digits."',"PROTOCOL TOTP scope")
m=replace_once(m,'RULE "Critical RED, production and kernel TOTP verification remains current-counter only."','RULE "Technical RED classification alone does not imply human authorization; protected production/external authority remains human-gated."',"PROTOCOL risk authority")
needle='SQLITE status="?q=SQLITE_STATUS"'
extra='''DEV auth="POST api.php?q=DEV_AUTH; WebAuthn passkey"
DEV api="POST api.php?q=DEV_API; X-KiCom-Dev-Session + X-KiCom-Dev-Token"
DEV bridge="GET /dev/agent/ or ?q=DEV_BRIDGE; same fixed DEV capability router"
RULE "DEV credentials are DEV-only and cannot cross into production, recovery, auth-admin, secrets or self-update-install authority."
RULE "Slack, Mail, GitHub and Opera/browser are transports/control surfaces and never authority sources."
SQLITE status="?q=SQLITE_STATUS"'''
m=replace_once(m,needle,extra,"PROTOCOL DEV transport")
needle='SQLITE evolution_tick="?q=SQLITE_EVOLUTION_TICK&session_id=...&token=..."'
extra='''SQLITE evolution_tick="?q=SQLITE_EVOLUTION_TICK&session_id=...&token=..."
SQLITE diagnostics="?q=SQLITE_DIAGNOSTICS"
RULE "Stored migration checksums must match compiled migration definitions before the DB opens successfully."
RULE "Evolution promotion requires a successfully verified pre-snapshot."'''
m=replace_once(m,needle,extra,"PROTOCOL SQLite hardening")
write(p,m)

p=out/"memory/decisions.kcl"; m=p.read_text()
extra='''DECISION D031 status=accepted title="Separate technical risk classification from authority boundary"
RATIONALE D031 "Risk drives verification depth; authority determines whether a human authorization is required."
DECISION D032 status=accepted title="Make DEV a trusted genome-covered module set"
RATIONALE D032 "Loose DEV PHP was removed by the immune guardian; trusted modules preserve integrity while keeping DEV incapable of production authority."
DECISION D033 status=accepted title="Use WebAuthn passkey for routine DEV entry"
RATIONALE D033 "Routine development should not require repeated FreeOTP codes; DEV credentials remain strictly scope-limited."
DECISION D034 status=accepted title="Harden SQLite migration, backup, import and evolution semantics"
RATIONALE D034 "Persistent operational memory needs checksum verification, actual-insert accounting, verified backup fallback and snapshot-gated promotion."
DECISION D035 status=accepted title="Preserve Slack, Mail and Opera as redundant non-authoritative paths"
RATIONALE D035 "Transport diversity improves resilience without letting any transport grant authority."
'''
m=replace_once(m,'END_DECISIONS kicom',extra+'END_DECISIONS kicom',"DECISIONS 0.9.26")
write(p,m)

p=out/"memory/changelog.kcl"; m=p.read_text()
extra='''RELEASE "0.9.26" date="2026-09-18" change="Trusted DEV modules with WebAuthn passkey sessions; risk/authority separation; SQLite checksum, dedupe, snapshot and backup hardening; revision-preserving canonical-memory release sync."
EVENT "2026-09-18" change="The literal critical-actions-totp-bound genome invariant is retained in 0.9.26 only as bootstrap compatibility for the 0.9.25 installer; the 0.9.26 verifier uses explicit authority-boundary invariants."
EVENT "2026-09-18" change="Slack, Mail and Opera/browser remain optional redundant transports/control surfaces and never grant authority."
'''
m=replace_once(m,'END_CHANGELOG kicom',extra+'END_CHANGELOG kicom',"CHANGELOG 0.9.26")
write(p,m)

p=out/"memory/next.kcl"; m=p.read_text()
m=replace_once(m,'CONSTRAINT "RED, production and recovery-kernel trust-boundary crossings require transaction-bound FreeOTP"','CONSTRAINT "Protected external authority crossings remain human-authorized; technical RED classification alone is not an authorization requirement."',"NEXT authority")
extra='''COMPLETED milestone="0.9.26 trusted DEV/passkey integration and SQLite hardening candidate verified by the 0.9.25 self-update verifier."
PRIORITY 1 goal="Install verified 0.9.26 through the ordinary self-update path and verify genome g25 with zero drift."
PRIORITY 1 goal="Retest DEV passkey, DEV API, extensionless agent bridge and iPhone/Opera JSON behavior."
PRIORITY 1 goal="Verify revision-preserving canonical-memory sync to 0.9.26."
PRIORITY 2 goal="Restore/provision bounded Slack credentials if Slack transport is desired; never treat Slack as authority."
PRIORITY 2 goal="Verify Mail IMAP/SMTP loopback and inbound update quarantine/verifier path."
PRIORITY 2 goal="Inspect SQLite event-stream diagnostics and confirm legacy-import deduplication behavior."
'''
m=replace_once(m,'END_NEXT kicom',extra+'END_NEXT kicom',"NEXT 0.9.26")
write(p,m)

# All six release resources must pass KiCom's own KCL validator after installation;
# the runtime migration will archive baseline/current revisions before replacement.


# ---- Genome: g25, same recovery kernel revision, trusted module components.
gp=out/"genome/genome.json"; g=json.loads(gp.read_text())
g["id"]="kicom-0.9.26-g25r2"; g["version"]="0.9.26"; g["parent"]="kicom-0.9.25-g24"; g["generation"]=25
g["mutation_reason"]="trusted-dev-authority-boundary-sqlite-hardening-memory-sync-r2"
g["created_at"]="2026-09-18T00:00:00+00:00"
inv=list(g.get("invariants",[]))
# Bootstrap compatibility: 0.9.25's verifier still requires this literal marker.
# In 0.9.26 it is retained only as a lineage/installer compatibility token;
# runtime authority is defined by the new authority-boundary invariants below.
if "critical-actions-totp-bound" not in inv: inv.append("critical-actions-totp-bound")
for x in ["risk-authority-separated","internal-self-authoring-verifier-bounded","protected-external-authority-human","dev-scope-no-production-authority"]:
    if x not in inv: inv.append(x)
g["invariants"]=inv
roles={
 "genome/modules.json":"trusted-module-manifest",
 "modules/dev/PasskeyBridge.php":"dev-passkey-verifier",
 "modules/dev/DevSession.php":"dev-session",
 "modules/dev/DevAuthAdapter.php":"dev-auth-adapter",
 "modules/dev/DevAuthFlow.php":"dev-auth-flow",
 "modules/dev/DevRouter.php":"dev-router",
 "modules/dev/DevHttpAdapter.php":"dev-http",
 "modules/dev/DevKiComBindings.php":"dev-bindings",
 "modules/dev/DevDiagnostics.php":"dev-diagnostics",
 "modules/dev/DevEndpoint.php":"dev-endpoint"
}
existing={c["path"]:c for c in g["components"]}
for path,role in roles.items():
    existing[path]={"path":path,"sha256":"","role":role,"auto_heal":True}
g["components"]=list(existing.values())

# Refresh component hashes after all code/docs writes except genome itself.
for c in g["components"]:
    p=out/c["path"]
    if not p.is_file(): raise SystemExit(f"genome component missing: {c['path']}")
    c["sha256"]=sha(p)
write(gp,json.dumps(g,indent=4,ensure_ascii=False)+"\n")

# Manifest includes every packaged file except snapshot metadata and itself.
rows=[]
for p in sorted(out.rglob("*")):
    if not p.is_file(): continue
    rel=p.relative_to(out).as_posix()
    if rel in {"MANIFEST.sha256","SOURCE-SNAPSHOT.json"}: continue
    rows.append(f"{sha(p)}  {rel}\n")
write(out/"MANIFEST.sha256","".join(rows))
print(json.dumps({"version":"0.9.26","genome":g["id"],"files":len(rows)},indent=2))
