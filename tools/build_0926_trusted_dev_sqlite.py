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
write(out/"lib.php",lib)

# ---- Authority semantics: risk != authority. External targets stay human-authorized.
living=(out/"living.php").read_text()
old="'auto_memory_archive'=>true,'auto_yellow_from_session'=>true,'red_requires_totp'=>true,'production_requires_totp'=>true,'kernel_requires_totp'=>true,'archive_append_only'=>true]"
new="'auto_memory_archive'=>true,'auto_yellow_from_session'=>true,'internal_self_update'=>true,'red_requires_totp'=>false,'production_requires_totp'=>true,'kernel_requires_totp'=>false,'external_authority_requires_human'=>true,'archive_append_only'=>true]"
living=replace_once(living,old,new,"autonomy authority defaults")

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

# ---- Canonical seed memory synchronized to 0.9.26. Runtime var remains authoritative after install.
mem={
"project_state.kcl":'''PROJECT kicom
VERSION "0.9.26"
STATE active
FACT canonical=true
FACT canonical_name="KiCom"
FACT sqlite_primary=true
FACT dev_zone="trusted-modules-passkey"
FACT slack_transport="bounded-redundant"
FACT mail_transport="bounded-redundant"
FACT browser_transport="opera-compatible-dev-admin"
FACT internal_authority="self-authoring-after-verifier"
FACT external_authority="human-protected"
RULE "Risk class and authority boundary are separate dimensions."
RULE "Internal reversible KiCom work is not TOTP-gated solely because it is RED."
RULE "Production targets, new external recipients, credentials and protected foreign systems remain human-authorized."
RULE "Verifier, SHA-256, backup, healthcheck, rollback, TLS and trust-root controls remain mandatory."
END
''',
"architecture.kcl":'''ARCHITECTURE kicom
VERSION "0.9.26"
LAYER trust="genome+lkg+recovery"
LAYER runtime="php+sqlite-primary+compatibility-mirror"
LAYER dev="trusted genome-covered modules + WebAuthn passkey + reusable DEV session"
LAYER transport="direct-chat + Slack + Mail + GitHub-mirror + browser"
LAYER authority="internal-self-authoring | protected-external-human"
RULE "DEV has no production, self-update-install, recovery, auth-admin or secret authority."
RULE "Slack and Mail are transports/perception surfaces, never authority sources."
RULE "Opera/browser is a control surface, never an authority source."
RULE "SQLite migration checksums, verified snapshots and deduplicated imports are invariants."
END
''',
"protocol.kcl":'''PROTOCOL KCL/1
VERSION "0.9.26"
AUTH legacy_session_open="FreeOTP fallback transport, not internal policy gate"
AUTH dev="WebAuthn passkey -> reusable DEV-scoped session"
RULE "Internal KiCom autonomy does not require human approval merely because technical risk is RED."
RULE "Human authorization is reserved for protected external systems, credentials, recipients and production targets."
RULE "Direct update transports converge on the normal self-update verifier."
RULE "No transport expands authority."
RULE "Slack, Mail, GitHub and Opera/browser are non-authoritative transports or control surfaces."
SQLITE diagnostics="SQLITE_DIAGNOSTICS"
END
''',
"decisions.kcl":'''DECISIONS kicom
VERSION "0.9.26"
DECISION D031 "Separate technical risk classification from authority boundary."
DECISION D032 "Make the DEV backend a trusted genome component under modules/; keep DEV credentials incapable of production authority."
DECISION D033 "Use WebAuthn/passkey for routine DEV entry; FreeOTP remains only a legacy/fallback transport and protected-external mechanism where configured."
DECISION D034 "Harden SQLite migration checksum verification, snapshot-gated evolution and actual-insert legacy counters."
DECISION D035 "Preserve Slack, Mail and Opera/browser as redundant non-authoritative paths in acceptance testing."
END
''',
"changelog.kcl":'''CHANGELOG kicom
VERSION "0.9.26"
CHANGE "Trusted DEV backend moved into genome-covered modules; incomplete loose drop-in is no longer authoritative."
CHANGE "Existing /dev/ browser endpoints are bridged to trusted root code without requiring executable PHP under /dev/."
CHANGE "Passkey DEV sessions are reusable, non-rotating and DEV-only."
CHANGE "SQLite stored migration checksums are verified on open."
CHANGE "SQLite legacy event imports count only newly inserted unique events."
CHANGE "SQLite evolution promotion aborts when the verified pre-snapshot fails."
CHANGE "SQLite backup uses verified SQLite3 backup with verified VACUUM INTO fallback."
CHANGE "Added bounded SQLite duplicate/stream diagnostics."
CHANGE "Internal self-update after authenticated transport still uses manifest/SHA/genome/backup/health/rollback but is not TOTP-gated solely by RED risk."
CHANGE "Production/external authority remains protected."
CHANGE "Slack, Mail and Opera/browser acceptance preserved."
END
''',
"next.kcl":'''NEXT kicom
VERSION "0.9.26"
NEXT "Run isolated CI and current-0.9.25 verifier against the complete 0.9.26 package."
NEXT "Install through the ordinary verified self-update path; do not use loose DEV PHP or rescue reader."
NEXT "After install verify HELLO, GENOME_STATUS, SQLITE_HEALTH, SQLITE_DIAGNOSTICS and zero drift."
NEXT "Retest /dev/ passkey, DEV API, extensionless agent bridge and iPhone/Opera JSON behavior."
NEXT "Probe Slack auth/send boundary and Mail IMAP/SMTP/loopback/inbound update path without exposing secrets."
NEXT "Confirm canonical runtime memory migrated/synchronized and archive prior state rather than deleting it."
END
'''
}
for name,content in mem.items(): write(out/"memory"/name,content)

# ---- Genome: g25, same recovery kernel revision, trusted module components.
gp=out/"genome/genome.json"; g=json.loads(gp.read_text())
g["id"]="kicom-0.9.26-g25"; g["version"]="0.9.26"; g["parent"]="kicom-0.9.25-g24"; g["generation"]=25
g["mutation_reason"]="trusted-dev-authority-boundary-sqlite-hardening"
g["created_at"]="2026-09-18T00:00:00+00:00"
inv=[x for x in g.get("invariants",[]) if x!="critical-actions-totp-bound"]
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
