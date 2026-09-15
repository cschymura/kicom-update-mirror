#!/usr/bin/env python3
import hashlib, json, re, shutil, subprocess, sys, tempfile, zipfile
from pathlib import Path
from datetime import datetime, timezone

BASE_SHA='06a8dfeec40f8cd9cb6a1bc972242fe750405f3dd694c73d8a967375476f800d'
VERSION='0.9.9'
GENOME_ID='kicom-0.9.9-g10'
PARENT='kicom-0.9.8-g9'
GENERATION=10


def sha(b:bytes)->str:return hashlib.sha256(b).hexdigest()
def run(cmd,check=True):
    print('+',' '.join(map(str,cmd)))
    return subprocess.run(cmd,text=True,capture_output=True,check=check)

def function_span(src:str,name:str):
    needle='function '+name+'('
    start=src.find(needle)
    if start<0: raise RuntimeError('function not found: '+name)
    brace=src.find('{',start)
    if brace<0: raise RuntimeError('function brace missing: '+name)
    depth=0; quote=None; esc=False; i=brace
    while i<len(src):
        c=src[i]
        if quote is not None:
            if esc: esc=False
            elif c=='\\': esc=True
            elif c==quote: quote=None
        else:
            if c in ('"',"'"): quote=c
            elif c=='{': depth+=1
            elif c=='}':
                depth-=1
                if depth==0:return start,i+1
        i+=1
    raise RuntimeError('unclosed function: '+name)

def replace_function(src:str,name:str,new:str)->str:
    a,b=function_span(src,name);return src[:a]+new.rstrip()+src[b:]
def insert_after_function(src:str,name:str,block:str)->str:
    a,b=function_span(src,name);return src[:b]+'\n'+block.strip()+'\n'+src[b:]
def replace_once(src:str,old:str,new:str,label:str)->str:
    n=src.count(old)
    if n!=1: raise RuntimeError(f'{label}: expected 1 match, got {n}')
    return src.replace(old,new,1)

def main():
    if len(sys.argv)!=3: raise SystemExit('usage: build_099_low_roundtrip.py BASE098.zip OUT099.zip')
    base=Path(sys.argv[1]);out=Path(sys.argv[2])
    if sha(base.read_bytes())!=BASE_SHA: raise SystemExit('BASE_SHA_MISMATCH')
    root=Path(tempfile.mkdtemp(prefix='kicom099-'))
    try:
        with zipfile.ZipFile(base) as z:z.extractall(root)

        # Core version and PHP validator: genuine syntax failures stay ERROR; hosting/CLI failures become WARN.
        libp=root/'lib.php';lib=libp.read_text()
        lib=replace_once(lib,"const KICOM_VERSION = '0.9.8';","const KICOM_VERSION = '0.9.9';",'version')
        lib=replace_function(lib,'kicomValidateContent',r'''function kicomValidateContent(string $path, string $content): array {
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
}''')
        libp.write_text(lib)

        livingp=root/'living.php';living=livingp.read_text()
        living=replace_once(living,"/* KiCom Living Architecture 0.9.6 */","/* KiCom Living Architecture 0.9.9 */",'living comment')
        living=replace_once(living,
            "function kicomAuthUploadsDir(): string { return kicomAuthDir().'/uploads'; }",
            "function kicomAuthUploadsDir(): string { return kicomAuthDir().'/uploads'; }\nfunction kicomAuthReadLeasesDir(): string { return kicomAuthDir().'/read_leases'; }",'read lease dir')
        living=replace_once(living,
            "foreach([kicomAuthDir(),kicomAuthSessionsDir(),kicomAuthApprovalsDir(),kicomAuthUploadsDir()] as $d){",
            "foreach([kicomAuthDir(),kicomAuthSessionsDir(),kicomAuthApprovalsDir(),kicomAuthUploadsDir(),kicomAuthReadLeasesDir()] as $d){",'auth ensure')
        living=replace_once(living,
            "function kicomAutonomyRevokeSessions(): void {foreach(glob(kicomAuthSessionsDir().'/*.json')?:[] as $f)@unlink($f);kicomLivingEvent('autonomy_sessions_revoked','warn');}",
            "function kicomAutonomyRevokeSessions(): void {foreach(glob(kicomAuthSessionsDir().'/*.json')?:[] as $f)@unlink($f);foreach(glob(kicomAuthReadLeasesDir().'/*.json')?:[] as $f)@unlink($f);kicomLivingEvent('autonomy_sessions_revoked','warn');}",'revoke leases')
        read_lease=r'''function kicomAuthReadLeaseFile(string $id): string {return kicomAuthReadLeasesDir().'/'.$id.'.json';}
function kicomAuthReadLeaseCleanup(): void {
    if(!kicomAuthEnsure())return;$now=time();foreach(glob(kicomAuthReadLeasesDir().'/*.json')?:[] as $f){$r=kicomAuthJsonRead($f);if(!is_array($r)||(int)($r['expires_at']??0)<$now)@unlink($f);}
}
function kicomAutonomyReadLeaseOpen(string $sessionId): array {
    if(!kicomAuthEnsure())return ['ok'=>false,'code'=>'AUTH_STORAGE_UNAVAILABLE'];$sessionId=strtolower(trim($sessionId));$sf=kicomAutonomySessionFile($sessionId);$s=kicomAuthJsonRead($sf);$now=time();if(!is_array($s))return ['ok'=>false,'code'=>'SESSION_NOT_FOUND'];$abs=(int)($s['absolute_expires_at']??0);$idle=(int)($s['idle_expires_at']??0);if($abs<$now||$idle<$now)return ['ok'=>false,'code'=>'SESSION_EXPIRED'];kicomAuthReadLeaseCleanup();$id=substr(kicomAuthRandomHex(10),0,20);$token=kicomAuthRandomHex(32);$expires=min($abs,$idle,$now+600);$row=['schema'=>1,'id'=>$id,'session_id'=>$sessionId,'token_hash'=>hash('sha256',$token),'created_at'=>gmdate('c'),'expires_at'=>$expires,'uses'=>0,'max_uses'=>64,'scope'=>'trusted_source_read'];if(!kicomAuthJsonWrite(kicomAuthReadLeaseFile($id),$row))return ['ok'=>false,'code'=>'READ_LEASE_WRITE_FAILED'];return ['ok'=>true,'lease_id'=>$id,'lease_token'=>$token,'expires_in'=>max(0,$expires-$now),'max_uses'=>64,'scope'=>'trusted_source_read'];
}
function kicomAutonomyReadLeaseCheck(string $id,string $token): array {
    $id=strtolower(trim($id));$token=strtolower(trim($token));if(!preg_match('/^[a-f0-9]{20}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$token))return ['ok'=>false,'code'=>'READ_LEASE_INVALID'];$f=kicomAuthReadLeaseFile($id);$r=kicomAuthJsonRead($f);$now=time();if(!is_array($r))return ['ok'=>false,'code'=>'READ_LEASE_NOT_FOUND'];if((int)($r['expires_at']??0)<$now){@unlink($f);return ['ok'=>false,'code'=>'READ_LEASE_EXPIRED'];}$s=kicomAuthJsonRead(kicomAutonomySessionFile((string)($r['session_id']??'')));if(!is_array($s)||(int)($s['absolute_expires_at']??0)<$now||(int)($s['idle_expires_at']??0)<$now){@unlink($f);return ['ok'=>false,'code'=>'READ_LEASE_SESSION_EXPIRED'];}if(!hash_equals((string)($r['token_hash']??''),hash('sha256',$token)))return ['ok'=>false,'code'=>'READ_LEASE_REJECTED'];$uses=(int)($r['uses']??0);$max=(int)($r['max_uses']??64);if($uses>=$max){@unlink($f);return ['ok'=>false,'code'=>'READ_LEASE_EXHAUSTED'];}$r['uses']=$uses+1;$r['last_used_at']=gmdate('c');if(!kicomAuthJsonWrite($f,$r))return ['ok'=>false,'code'=>'READ_LEASE_WRITE_FAILED'];return ['ok'=>true,'lease_id'=>$id,'expires_at'=>(int)$r['expires_at'],'uses_left'=>max(0,$max-(int)$r['uses']),'scope'=>'trusted_source_read'];
}'''
        living=insert_after_function(living,'kicomAutonomySessionCount',read_lease)

        prepare=r'''function kicomFastBuildPrepareRelease(string $sessionId,string $id,string $version,string $mutationReason,string $summary=''): array {
    $x=kicomFastBuildMeta($sessionId,$id);if(!$x['ok'])return $x;$m=$x['meta'];if(($m['status']??'')!=='open')return ['ok'=>false,'code'=>'BUILD_NOT_OPEN'];$version=trim($version);$mutationReason=trim($mutationReason);if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',$version)||version_compare($version,KICOM_VERSION,'<='))return ['ok'=>false,'code'=>'BUILD_VERSION_INVALID'];if($mutationReason===''||strlen($mutationReason)>1200)return ['ok'=>false,'code'=>'BUILD_REASON_INVALID'];$src=$x['dir'].'/src';$atomic=function(string $file,string $raw): bool {$tmp=$file.'.tmp-'.strtolower(kicomRequestId());if(@file_put_contents($tmp,$raw,LOCK_EX)===false)return false;@chmod($tmp,0600);if(!@rename($tmp,$file)){@unlink($tmp);return false;}return true;};
    $libf=$src.'/lib.php';$lib=(string)@file_get_contents($libf);$beforeLib=hash('sha256',$lib);$next=preg_replace("/const\\s+KICOM_VERSION\\s*=\\s*'[^']+';/","const KICOM_VERSION = '".$version."';",$lib,1,$n);if(!is_string($next)||$n!==1)return ['ok'=>false,'code'=>'BUILD_VERSION_ANCHOR'];$v=kicomValidateContent('lib.php',$next);if(($v['status']??'')==='error')return ['ok'=>false,'code'=>'VALIDATION_FAILED','validation'=>$v['message']];if(!$atomic($libf,$next))return ['ok'=>false,'code'=>'BUILD_WRITE_FAILED'];
    $current=kicomGenomeCurrent();if(!is_array($current))return ['ok'=>false,'code'=>'BUILD_CURRENT_GENOME_UNAVAILABLE'];$gp=$src.'/genome/genome.json';$g=json_decode((string)@file_get_contents($gp),true);if(!is_array($g))return ['ok'=>false,'code'=>'BUILD_GENOME_INVALID'];$beforeGenome=hash_file('sha256',$gp)?:'';$generation=(int)($current['generation']??0)+1;$gid='kicom-'.$version.'-g'.$generation;$g['id']=$gid;$g['version']=$version;$g['parent']=(string)($current['id']??'');$g['generation']=$generation;$g['created_at']=gmdate('c');$g['mutation_reason']=$mutationReason;$gj=json_encode($g,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($gj===false||!$atomic($gp,$gj."\n"))return ['ok'=>false,'code'=>'BUILD_GENOME_WRITE_FAILED'];
    $ps=$src.'/memory/project_state.kcl';if(is_file($ps)){$raw=(string)@file_get_contents($ps);$raw=preg_replace('/^VERSION\s+"[0-9]+\.[0-9]+\.[0-9]+"/m','VERSION "'.$version.'"',$raw,1,$pn);if($pn===1&&!$atomic($ps,$raw))return ['ok'=>false,'code'=>'BUILD_SEED_WRITE_FAILED'];}
    $summary=trim($summary);if($summary!==''&&strlen($summary)<=800){$cf=$src.'/memory/changelog.kcl';if(is_file($cf)){$cr=(string)@file_get_contents($cf);if(!str_contains($cr,'RELEASE "'.$version.'"')&&str_contains($cr,'END_CHANGELOG kicom')){$safe=str_replace(["\r","\n",'"'],[' ',' ',"'"],$summary);$cr=str_replace('END_CHANGELOG kicom','RELEASE "'.$version.'" date="'.gmdate('Y-m-d').'" change="'.$safe.'"'."\n".'END_CHANGELOG kicom',$cr);if(!$atomic($cf,$cr))return ['ok'=>false,'code'=>'BUILD_CHANGELOG_WRITE_FAILED'];}}}
    $m['patches'][]=['path'=>'lib.php','at'=>gmdate('c'),'before'=>$beforeLib,'after'=>hash_file('sha256',$libf)?:'','kind'=>'release_prepare'];$m['patches'][]=['path'=>'genome/genome.json','at'=>gmdate('c'),'before'=>$beforeGenome,'after'=>hash_file('sha256',$gp)?:'','kind'=>'release_prepare'];$m['expires_at']=time()+7200;if(!kicomAuthJsonWrite($x['dir'].'/meta.json',$m))return ['ok'=>false,'code'=>'BUILD_META_FAILED'];@touch($x['dir']);return ['ok'=>true,'build_id'=>$id,'candidate_version'=>$version,'genome_id'=>$gid,'generation'=>$generation,'lib_sha256'=>hash_file('sha256',$libf)?:'','genome_sha256'=>hash_file('sha256',$gp)?:'','patches'=>count($m['patches'])];
}'''
        living=insert_after_function(living,'kicomFastBuildStatus',prepare)
        livingp.write_text(living)

        indexp=root/'index.php';idx=indexp.read_text()
        auth_anchor="case 'AUTH_SESSION_OPEN':\n    $r=kicomAutonomySessionOpen((string)($_GET['code']??''));if(!$r['ok'])out([KCL_PROTOCOL,'ERROR auth_session_open','FACT request_id='.kclString($rid),'FACT code='.kclString((string)$r['code']),'END'],401);out([KCL_PROTOCOL,'OK auth_session_open','FACT request_id='.kclString($rid),'FACT session_id='.kclString((string)$r['session_id']),'FACT token='.kclString((string)$r['token'],100),'FACT expires_in='.(int)$r['expires_in'],'FACT idle_expires_in='.(int)$r['idle_expires_in'],'RULE token-rotates-after-every-authorized-request','END']);"
        auth_new=auth_anchor+r'''
case 'AUTONOMY_READ_LEASE_OPEN':
    $ss=requireAutonomySession($rid);$r=kicomAutonomyReadLeaseOpen((string)$ss['session_id']);$lines=[KCL_PROTOCOL,($r['ok']?'OK':'ERROR').' autonomy_read_lease_open','FACT request_id='.kclString($rid),'FACT code='.kclString((string)($r['code']??'OK'))];$lines=array_merge($lines,autonomySessionFacts($ss));if($r['ok']){$lines[]='FACT lease_id='.kclString((string)$r['lease_id']);$lines[]='FACT lease_token='.kclString((string)$r['lease_token'],100);$lines[]='FACT expires_in='.(int)$r['expires_in'];$lines[]='FACT max_uses='.(int)$r['max_uses'];$lines[]='RULE read-only-trusted-source-no-main-token-rotation';}$lines[]='END';out($lines,$r['ok']?200:422);
case 'AUTONOMY_SOURCE_LIST_LEASE':
    $lr=kicomAutonomyReadLeaseCheck((string)($_GET['lease_id']??''),(string)($_GET['lease_token']??''));if(!$lr['ok'])out([KCL_PROTOCOL,'ERROR autonomy_source_list_lease','FACT request_id='.kclString($rid),'FACT code='.kclString((string)$lr['code']),'END'],401);$rows=kicomAutonomySourceList();$lines=[KCL_PROTOCOL,'OK autonomy_source_list_lease','FACT request_id='.kclString($rid),'FACT count='.count($rows),'FACT lease_id='.kclString((string)$lr['lease_id']),'FACT uses_left='.(int)$lr['uses_left']];foreach($rows as $i=>$f)$lines[]='FILE #'.($i+1).' path='.kclString((string)$f['path']).' bytes='.(int)$f['bytes'].' sha256='.kclString((string)$f['sha256']);$lines[]='RULE trusted-manifest-install-files-only';$lines[]='END';out($lines);
case 'AUTONOMY_SOURCE_READ_LEASE':
    $lr=kicomAutonomyReadLeaseCheck((string)($_GET['lease_id']??''),(string)($_GET['lease_token']??''));if(!$lr['ok'])out([KCL_PROTOCOL,'ERROR autonomy_source_read_lease','FACT request_id='.kclString($rid),'FACT code='.kclString((string)$lr['code']),'END'],401);$r=kicomAutonomySourceRead((string)($_GET['path']??''),(int)($_GET['offset']??0),(int)($_GET['length']??49152));$lines=[KCL_PROTOCOL,($r['ok']?'OK':'ERROR').' autonomy_source_read_lease','FACT request_id='.kclString($rid),'FACT code='.kclString((string)($r['code']??'OK')),'FACT lease_id='.kclString((string)$lr['lease_id']),'FACT uses_left='.(int)$lr['uses_left']];if($r['ok']){$lines[]='FACT path='.kclString((string)$r['path']);$lines[]='FACT bytes='.(int)$r['bytes'];$lines[]='FACT sha256='.kclString((string)$r['sha256']);$lines[]='FACT offset='.(int)$r['offset'];$lines[]='FACT next_offset='.(int)$r['next_offset'];$lines[]='FACT eof='.($r['eof']?'true':'false');$lines[]='DATA encoding="base64url" value='.kclString((string)$r['data'],70000);}$lines[]='END';out($lines,$r['ok']?200:422);'''
        idx=replace_once(idx,auth_anchor,auth_new,'read lease endpoints')

        batch_anchor="case 'AUTONOMY_MEMORY_PATCH':"
        batch=r'''case 'AUTONOMY_COMPACT_BATCH':
    $ss=requireAutonomySession($rid);$raw=kicomDecodeBase64Url((string)($_GET['payload']??''),false);if($raw===null||strlen($raw)>6144)out([KCL_PROTOCOL,'ERROR autonomy_compact_batch','FACT request_id='.kclString($rid),'FACT code="BATCH_PAYLOAD_INVALID"','FACT next_token='.kclString((string)$ss['next_token'],100),'END'],400);$ops=json_decode($raw,true);if(!is_array($ops)||count($ops)<1||count($ops)>16)out([KCL_PROTOCOL,'ERROR autonomy_compact_batch','FACT request_id='.kclString($rid),'FACT code="BATCH_INVALID"','FACT next_token='.kclString((string)$ss['next_token'],100),'END'],400);$lines=[KCL_PROTOCOL,'OK autonomy_compact_batch','FACT request_id='.kclString($rid),'FACT session_id='.kclString((string)$ss['session_id']),'FACT next_token='.kclString((string)$ss['next_token'],100),'FACT operations='.count($ops)];$all=true;foreach($ops as $i=>$o){if(!is_array($o)){$r=['ok'=>false,'code'=>'OP_INVALID'];}else{$kind=strtolower((string)($o['kind']??''));$find=(string)($o['find']??'');$replace=(string)($o['replace']??'');if($kind==='memory_patch')$r=kicomAutonomyMemoryPatchDirect((string)($o['resource']??''),(string)($o['base_sha256']??''),$find,$replace);elseif($kind==='workspace_patch')$r=kicomAutonomyWorkspacePatchDirect((string)($o['path']??''),(string)($o['base_sha256']??''),$find,$replace);elseif($kind==='build_patch')$r=kicomFastBuildPatch((string)$ss['session_id'],(string)($o['build_id']??''),(string)($o['path']??''),(string)($o['base_sha256']??''),$find,$replace);else$r=['ok'=>false,'code'=>'OP_KIND_UNSUPPORTED'];}$all=$all&&!empty($r['ok']);$lines[]='OP #'.($i+1).' ok='.(!empty($r['ok'])?'true':'false').' code='.kclString((string)($r['code']??'OK')).(isset($r['sha256'])?' sha256='.kclString((string)$r['sha256']):'');if(empty($r['ok']))break;}$lines[]='FACT all_ok='.($all?'true':'false');$lines[]='RULE ordered-stop-on-error-not-atomic';$lines[]='RULE existing-allowlists-and-base-sha256-still-apply';$lines[]='END';out($lines,$all?200:207);
'''+batch_anchor
        idx=replace_once(idx,batch_anchor,batch,'compact batch')

        status_anchor="case 'AUTONOMY_BUILD_STATUS':\n    $ss=requireAutonomySession($rid);$r=kicomFastBuildStatus((string)$ss['session_id'],(string)($_GET['build_id']??''));$lines=[KCL_PROTOCOL,($r['ok']?'OK':'ERROR').' autonomy_build_status','FACT request_id='.kclString($rid),'FACT code='.kclString((string)($r['code']??'OK'))];$lines=array_merge($lines,autonomySessionFacts($ss));foreach(['build_id','status','base_version','candidate_version','genome_id','patches','files'] as $k)if(isset($r[$k]))$lines[]='FACT '.$k.'='.kclString((string)$r[$k]);$lines[]='END';out($lines,$r['ok']?200:422);"
        status_new=status_anchor+r'''
case 'AUTONOMY_BUILD_PREPARE_RELEASE':
    $ss=requireAutonomySession($rid);$r=kicomFastBuildPrepareRelease((string)$ss['session_id'],(string)($_GET['build_id']??''),(string)($_GET['version']??''),(string)($_GET['reason']??''),(string)($_GET['summary']??''));$lines=[KCL_PROTOCOL,($r['ok']?'OK':'ERROR').' autonomy_build_prepare_release','FACT request_id='.kclString($rid),'FACT code='.kclString((string)($r['code']??'OK'))];$lines=array_merge($lines,autonomySessionFacts($ss));foreach(['build_id','candidate_version','genome_id','generation','lib_sha256','genome_sha256','patches'] as $k)if(isset($r[$k])&&is_scalar($r[$k]))$lines[]='FACT '.$k.'='.kclString((string)$r[$k]);$lines[]='RULE metadata-preparation-does-not-promote-or-execute-candidate';$lines[]='END';out($lines,$r['ok']?200:422);'''
        idx=replace_once(idx,status_anchor,status_new,'release prepare endpoint')
        idx=idx.replace('FACT transport="https:get-read+post-mutation+guarded-get-bridge"','FACT transport="https:get-read+compact-get-batch+post-mutation+guarded-get-bridge"',1)
        idx=idx.replace("'SUMMARY \"KiCom 0.9.6 adds a bounded Autonomy Envelope and FreeOTP Human Authorization Bridge, with direct trusted-source reads, direct update uploads and first-party release archival.\"'","'SUMMARY '.kclString('KiCom '.KICOM_VERSION.' is the current trusted baseline; canonical memory and DESCRIBE define current capabilities.')",1)
        idx=idx.replace("'NEXT_ACTION \"Enroll FreeOTP once, open bounded autonomy sessions from chat, keep critical RED/production/kernel actions transaction-bound to TOTP, and use sandbox/archive/primary feed as the first-party development path.\"'","'NEXT_ACTION '.kclString('Use bounded autonomy and low-roundtrip transport inside the trust envelope; keep RED, production and kernel changes transaction-bound to FreeOTP.')",1)
        idx=replace_once(idx,
            "'CAPABILITY DIRECT_SOURCE trusted_manifest_chunk_read fast_read_48k','CAPABILITY AUTONOMY_PATCH memory workspace exact_base_sha256','CAPABILITY AUTONOMY_BUILD trusted_source_copy exact_patch server_side_package same_verifier','CAPABILITY AUTONOMY_BATCH post_patch_operations single_session_rotation'",
            "'CAPABILITY DIRECT_SOURCE trusted_manifest_chunk_read fast_read_48k','CAPABILITY READ_LEASE trusted_source_only ttl600 max_uses64 nonrotating','CAPABILITY AUTONOMY_PATCH memory workspace exact_base_sha256','CAPABILITY AUTONOMY_BUILD trusted_source_copy exact_patch server_side_package same_verifier release_prepare','CAPABILITY AUTONOMY_BATCH post_patch_operations single_session_rotation compact_get_batch'",'describe capabilities')
        idx=replace_once(idx,
            "'RULE high-throughput-transport-does-not-expand-permission-boundaries'",
            "'RULE read-lease-is-source-only-and-cannot-mutate','RULE compact-get-batch-uses-one-session-rotation-and-existing-allowlists','RULE high-throughput-transport-does-not-expand-permission-boundaries'",'describe rules')
        indexp.write_text(idx)

        gp=root/'genome/genome.json';g=json.loads(gp.read_text());g['id']=GENOME_ID;g['version']=VERSION;g['parent']=PARENT;g['generation']=GENERATION;g['created_at']=datetime.now(timezone.utc).isoformat();g['mutation_reason']='Reduce roundtrips for chat-based development without widening authority: read-only nonrotating source leases, compact GET batch patching, semantic server-side release preparation, dynamic bootstrap text, and robust PHP lint fallback while retaining the normal verifier and rollback.'
        for c in g['components']:
            if c['path'] in ('lib.php','living.php','index.php'): c['sha256']=sha((root/c['path']).read_bytes())
        gp.write_text(json.dumps(g,indent=2,ensure_ascii=False)+'\n')

        ps=root/'memory/project_state.kcl';p=ps.read_text();p=p.replace('VERSION "0.9.8"','VERSION "0.9.9"',1);p=p.replace('FACT genome_id="kicom-0.9.6-g7"\nFACT genome_generation=7','FACT genome_id="kicom-0.9.9-g10"\nFACT genome_generation=10',1)
        marker='FACT canonical_memory_via="?q=BOOTSTRAP"'
        extra='FACT read_lease="trusted-source-only, ttl600, max_uses64, nonrotating"\nFACT compact_get_batch="up to 16 exact patch operations, one session rotation"\nFACT release_prepare="server-side semver and genome-lineage preparation"\n'+marker
        p=replace_once(p,marker,extra,'seed project transport')
        p=p.replace('END_PROJECT kicom','MILESTONE "Fast transport" status=implemented version="0.9.8"\nMILESTONE "Low-roundtrip control plane" status=implemented version="0.9.9"\nEND_PROJECT kicom')
        ps.write_text(p)
        ch=root/'memory/changelog.kcl';c=ch.read_text();c=c.replace('END_CHANGELOG kicom','RELEASE "0.9.9" date="2026-09-15" change="Low-roundtrip control plane: source read leases, compact GET batch, semantic release preparation and robust PHP lint fallback"\nEND_CHANGELOG kicom');ch.write_text(c)
        pr=root/'memory/protocol.kcl';q=pr.read_text();q=q.replace('END_PROTOCOL KCL/1','AUTONOMY read_lease="source-only; 600s; 64 uses; no main-token rotation"\nAUTONOMY compact_batch="base64url JSON over GET; up to 16 exact patch operations; one session rotation"\nAUTONOMY release_prepare="server-side version/genome lineage metadata; candidate remains non-executable until normal verifier"\nEND_PROTOCOL KCL/1');pr.write_text(q)
        nx=root/'memory/next.kcl';n=nx.read_text();n=n.replace('END_NEXT kicom','PRIORITY 1 goal="Use low-roundtrip read leases and compact batches for ChatGPT-KiCom work; preserve transaction-TOTP gates at RED/production/kernel boundaries"\nEND_NEXT kicom');nx.write_text(n)

        component_paths=[c['path'] for c in g['components']]
        package_paths=component_paths+['genome/genome.json','memory/project_state.kcl','memory/architecture.kcl','memory/protocol.kcl','memory/decisions.kcl','memory/changelog.kcl','memory/next.kcl']
        package_paths=sorted(set(package_paths))
        manifest=''.join(f'{sha((root/p).read_bytes())}  {p}\n' for p in package_paths)
        (root/'MANIFEST.sha256').write_text(manifest)
        out.parent.mkdir(parents=True,exist_ok=True);out.unlink(missing_ok=True)
        with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
            for p in sorted(package_paths+['MANIFEST.sha256']):z.write(root/p,p)
        print('PACKAGE_SHA256='+sha(out.read_bytes()))

        verify=Path(tempfile.mkdtemp(prefix='k098verify-'))
        try:
            with zipfile.ZipFile(base) as z:z.extractall(verify)
            code=f'''require {str(verify/'lib.php')!r};$x=kicomSelfUpdateZipInspect({str(out)!r});if(empty($x["ok"])){{echo "INSPECT=".($x["code"]??"?")."\\n";exit(31);}}$r=kicomSelfUpdateRiskClass($x);echo "RISK=".($r["class"]??"?")."\\n";echo "VERSION=".($x["version"]??"?")."\\n";if(($x["version"]??"")!=="0.9.9"||($x["genome"]["id"]??"")!=="kicom-0.9.9-g10")exit(32);'''
            rr=run(['php','-r',code]);print(rr.stdout,end='')
        finally:shutil.rmtree(verify,ignore_errors=True)

        for p in sorted(root.rglob('*.php')):
            rr=run(['php','-l',str(p)]);print(rr.stdout,end='')

        fresh=Path(tempfile.mkdtemp(prefix='k099fresh-'))
        try:
            with zipfile.ZipFile(out) as z:z.extractall(fresh)
            test=Path('/tmp/test099.php')
            test.write_text(f'''<?php
chdir({str(fresh)!r});require {str(fresh/'lib.php')!r};if(KICOM_VERSION!=="0.9.9")exit(41);if(!kicomEnsureStorage())exit(42);
$v=kicomValidateContent('x.php',"<?php echo 1;\\n");if(($v['status']??'')==='error'){{var_dump($v);exit(43);}}$bad=kicomValidateContent('x.php',"<?php if ( ;\\n");if(($bad['status']??'')!=='error'){{var_dump($bad);exit(44);}}
$setup=kicomTotpSetupBegin('CI');$counter=intdiv(time(),30);$code=kicomTotpCodeForCounter($setup['secret'],$counter,6);if(empty(kicomTotpSetupConfirm($code)['ok']))exit(45);$cfg=kicomAuthJsonRead(kicomTotpFile());$cfg['last_counter']=$counter-1;kicomAuthJsonWrite(kicomTotpFile(),$cfg);$s=kicomAutonomySessionOpen($code);if(empty($s['ok']))exit(46);$lease=kicomAutonomyReadLeaseOpen($s['session_id']);if(empty($lease['ok'])){{var_dump($lease);exit(47);}}$a=kicomAutonomyReadLeaseCheck($lease['lease_id'],$lease['lease_token']);$b=kicomAutonomyReadLeaseCheck($lease['lease_id'],$lease['lease_token']);if(empty($a['ok'])||empty($b['ok'])||$b['uses_left']>= $a['uses_left'])exit(48);
$build=kicomFastBuildBegin($s['session_id']);if(empty($build['ok'])){{var_dump($build);exit(49);}}$bf=kicomFastBuildMeta($s['session_id'],$build['build_id']);$lp=$bf['dir'].'/src/living.php';$h=hash_file('sha256',$lp);$p=kicomFastBuildPatch($s['session_id'],$build['build_id'],'living.php',$h,'/* KiCom Living Architecture 0.9.9 */','/* KiCom Living Architecture 0.9.9 test */');if(empty($p['ok'])){{var_dump($p);exit(50);}}$prep=kicomFastBuildPrepareRelease($s['session_id'],$build['build_id'],'0.9.10','CI semantic release preparation','CI release');if(empty($prep['ok'])||($prep['genome_id']??'')!=="kicom-0.9.10-g11"){{var_dump($prep);exit(51);}}echo "LOW_ROUNDTRIP_OK\\n";
''')
            rr=run(['php',str(test)]);print(rr.stdout,end='')
        finally:shutil.rmtree(fresh,ignore_errors=True)
    finally:shutil.rmtree(root,ignore_errors=True)

if __name__=='__main__':main()
