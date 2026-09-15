#!/usr/bin/env python3
import hashlib, json, re, shutil, subprocess, sys, tempfile, zipfile
from pathlib import Path
from datetime import datetime, timezone

BASE_SHA='b9c18d0d729a6ed83ce934c5f96e1c3dbed4dbd8d52a6378501cbe728c5aa723'
VERSION='0.9.10'
GENOME_ID='kicom-0.9.10-g11'
PARENT='kicom-0.9.9-g10'
GENERATION=11

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

def replace_function(src,name,new):
    a,b=function_span(src,name);return src[:a]+new.rstrip()+src[b:]
def insert_after_function(src,name,block):
    a,b=function_span(src,name);return src[:b]+'\n'+block.strip()+'\n'+src[b:]
def replace_once(src,old,new,label):
    n=src.count(old)
    if n!=1: raise RuntimeError(f'{label}: expected 1 match, got {n}')
    return src.replace(old,new,1)

TX_FUNCS = r'''function kicomAutonomySessionPeek(string $id,string $token): array {
    $p=kicomAutonomyPolicy();if(empty($p['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];
    $id=strtolower(trim($id));$token=strtolower(trim($token));
    if(!preg_match('/^[a-f0-9]{24}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$token))return ['ok'=>false,'code'=>'SESSION_AUTH_INVALID'];
    $r=kicomAuthJsonRead(kicomAutonomySessionFile($id));$now=time();
    if(!is_array($r))return ['ok'=>false,'code'=>'SESSION_NOT_FOUND'];
    if((int)($r['absolute_expires_at']??0)<$now||(int)($r['idle_expires_at']??0)<$now)return ['ok'=>false,'code'=>'SESSION_EXPIRED'];
    if(!hash_equals((string)($r['token_hash']??''),hash('sha256',$token)))return ['ok'=>false,'code'=>'SESSION_TOKEN_REJECTED'];
    return ['ok'=>true,'session_id'=>$id,'expires_at'=>(int)$r['absolute_expires_at'],'idle_expires_at'=>(int)$r['idle_expires_at']];
}
function kicomAuthTxFile(string $id): string {return kicomAuthDir().'/tx-'.$id.'.json';}
function kicomAuthTxCleanup(): void {
    $now=time();foreach(glob(kicomAuthDir().'/tx-*.json')?:[] as $f){$r=kicomAuthJsonRead($f);if(!is_array($r)||(int)($r['expires_at']??0)<$now)@unlink($f);}
}
function kicomAutonomyTxGet(string $id,string $token): array {
    $id=strtolower(trim($id));$token=strtolower(trim($token));
    if(!preg_match('/^[a-f0-9]{20}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$token))return ['ok'=>false,'code'=>'TX_INVALID'];
    $f=kicomAuthTxFile($id);$r=kicomAuthJsonRead($f);
    if(!is_array($r))return ['ok'=>false,'code'=>'TX_NOT_FOUND'];
    if((int)($r['expires_at']??0)<time()){@unlink($f);return ['ok'=>false,'code'=>'TX_EXPIRED'];}
    if(!hash_equals((string)($r['token_hash']??''),hash('sha256',$token)))return ['ok'=>false,'code'=>'TX_REJECTED'];
    return ['ok'=>true,'file'=>$f,'row'=>$r];
}
function kicomAutonomyTxBegin(string $sessionId,string $sessionToken): array {
    $s=kicomAutonomySessionPeek($sessionId,$sessionToken);if(empty($s['ok']))return $s;kicomAuthTxCleanup();
    $n=0;foreach(glob(kicomAuthDir().'/tx-*.json')?:[] as $f){$r=kicomAuthJsonRead($f);if(is_array($r)&&hash_equals((string)($r['session_id']??''),$sessionId))$n++;}
    if($n>=4)return ['ok'=>false,'code'=>'TX_LIMIT'];
    $id=substr(kicomAuthRandomHex(10),0,20);$tok=kicomAuthRandomHex(32);$now=time();$exp=min((int)$s['expires_at'],(int)$s['idle_expires_at'],$now+600);
    $row=['schema'=>1,'id'=>$id,'session_id'=>$sessionId,'token_hash'=>hash('sha256',$tok),'created_at'=>gmdate('c'),'expires_at'=>$exp,'next_seq'=>0,'bytes'=>0,'payload'=>'','max_bytes'=>65536,'max_fragment_bytes'=>1024];
    if(!kicomAuthJsonWrite(kicomAuthTxFile($id),$row))return ['ok'=>false,'code'=>'TX_WRITE_FAILED'];
    return ['ok'=>true,'tx_id'=>$id,'tx_token'=>$tok,'expires_in'=>max(0,$exp-$now),'max_bytes'=>65536,'max_fragment_bytes'=>1024];
}
function kicomAutonomyTxAppend(string $id,string $token,int $seq,string $data): array {
    $x=kicomAutonomyTxGet($id,$token);if(empty($x['ok']))return $x;$r=$x['row'];$chunk=kicomDecodeBase64Url($data,true);
    if($chunk===null)return ['ok'=>false,'code'=>'TX_CHUNK_ENCODING'];
    if($seq!==(int)($r['next_seq']??0))return ['ok'=>false,'code'=>'TX_SEQ','next_seq'=>(int)($r['next_seq']??0)];
    if(strlen($chunk)>(int)($r['max_fragment_bytes']??1024))return ['ok'=>false,'code'=>'TX_FRAGMENT_TOO_LARGE'];
    $payload=(string)($r['payload']??'').$chunk;
    if(strlen($payload)>(int)($r['max_bytes']??65536))return ['ok'=>false,'code'=>'TX_TOO_LARGE'];
    $r['payload']=$payload;$r['bytes']=strlen($payload);$r['next_seq']=$seq+1;$r['updated_at']=gmdate('c');
    if(!kicomAuthJsonWrite($x['file'],$r))return ['ok'=>false,'code'=>'TX_WRITE_FAILED'];
    return ['ok'=>true,'tx_id'=>$id,'next_seq'=>$seq+1,'bytes'=>strlen($payload),'sha256'=>hash('sha256',$payload)];
}
function kicomAutonomyPatchOps(array $ops,string $sessionId,int $maxOps=32): array {
    if(count($ops)<1||count($ops)>$maxOps)return ['ok'=>false,'code'=>'BATCH_INVALID','results'=>[]];
    $results=[];$all=true;
    foreach($ops as $i=>$o){
        if(!is_array($o))$r=['ok'=>false,'code'=>'OP_INVALID'];
        else{
            $kind=strtolower((string)($o['kind']??''));$find=(string)($o['find']??'');$replace=(string)($o['replace']??'');
            if($kind==='memory_patch')$r=kicomAutonomyMemoryPatchDirect((string)($o['resource']??''),(string)($o['base_sha256']??''),$find,$replace);
            elseif($kind==='workspace_patch')$r=kicomAutonomyWorkspacePatchDirect((string)($o['path']??''),(string)($o['base_sha256']??''),$find,$replace);
            elseif($kind==='build_patch')$r=kicomFastBuildPatch($sessionId,(string)($o['build_id']??''),(string)($o['path']??''),(string)($o['base_sha256']??''),$find,$replace);
            else $r=['ok'=>false,'code'=>'OP_KIND_UNSUPPORTED'];
        }
        $results[]=$r;$all=$all&&!empty($r['ok']);if(empty($r['ok']))break;
    }
    return ['ok'=>$all,'code'=>$all?'OK':'BATCH_PARTIAL','results'=>$results,'operations'=>count($ops)];
}
function kicomAutonomyTxCommit(string $id,string $txToken,string $sessionToken,string $expectedSha): array {
    $x=kicomAutonomyTxGet($id,$txToken);if(empty($x['ok']))return $x;$r=$x['row'];$payload=(string)($r['payload']??'');
    $expectedSha=strtolower(trim($expectedSha));if(!preg_match('/^[a-f0-9]{64}$/',$expectedSha)||!hash_equals(hash('sha256',$payload),$expectedSha))return ['ok'=>false,'code'=>'TX_SHA_MISMATCH','sha256'=>hash('sha256',$payload)];
    $ops=json_decode($payload,true);if(!is_array($ops))return ['ok'=>false,'code'=>'TX_JSON_INVALID'];
    $ss=kicomAutonomySessionConsume((string)($r['session_id']??''),$sessionToken);if(empty($ss['ok']))return $ss;
    $a=kicomAutonomyPatchOps($ops,(string)$ss['session_id'],32);@unlink($x['file']);
    kicomLivingEvent('autonomy_tx_committed',!empty($a['ok'])?'info':'warn',['tx_id'=>$id,'operations'=>count($ops),'result'=>(string)($a['code']??'')]);
    return $a+['session_id'=>(string)$ss['session_id'],'next_token'=>(string)$ss['next_token'],'expires_at'=>(int)$ss['expires_at'],'idle_expires_at'=>(int)$ss['idle_expires_at'],'tx_id'=>$id,'sha256'=>$expectedSha];
}
function kicomAutonomyTxAbort(string $id,string $txToken): array {
    $x=kicomAutonomyTxGet($id,$txToken);if(empty($x['ok']))return $x;@unlink($x['file']);return ['ok'=>true,'code'=>'TX_ABORTED','tx_id'=>$id];
}'''

APPROVAL_CREATE = r'''function kicomAuthApprovalCreate(string $sessionId,string $action,array $binding,array $payload,string $risk): array {
    $ttl=strtolower($risk)==='red'?3600:120;
    $id=substr(kicomAuthRandomHex(12),0,24);$digest=hash('sha256',json_encode([$action,$binding],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'');
    $row=['schema'=>1,'id'=>$id,'session_id'=>$sessionId,'action'=>$action,'risk'=>$risk,'binding'=>$binding,'binding_sha256'=>$digest,'payload'=>$payload,'created_at'=>gmdate('c'),'expires_at'=>time()+$ttl,'status'=>'pending'];
    if(!kicomAuthJsonWrite(kicomAuthApprovalFile($id),$row))return ['ok'=>false,'code'=>'APPROVAL_WRITE_FAILED'];
    kicomLivingEvent('totp_approval_prepared','info',['approval_id'=>$id,'action'=>$action,'risk'=>$risk,'binding_sha256'=>$digest,'expires_in'=>$ttl]);
    return ['ok'=>true,'approval_id'=>$id,'binding_sha256'=>$digest,'risk'=>$risk,'expires_in'=>$ttl,'binding'=>$binding];
}'''

APPROVAL_EXEC = r'''function kicomAuthApprovalExecute(string $id,string $code): array {
    $row=kicomAuthApprovalGet($id);if(!is_array($row))return ['ok'=>false,'code'=>'APPROVAL_NOT_FOUND'];if(($row['status']??'')!=='pending')return ['ok'=>false,'code'=>'APPROVAL_NOT_PENDING'];
    if((int)($row['expires_at']??0)<time()){$row['status']='expired';kicomAuthJsonWrite(kicomAuthApprovalFile($id),$row);return ['ok'=>false,'code'=>'APPROVAL_EXPIRED'];}
    $v=kicomTotpVerifyConsume($code,'approval:'.$id.':'.(string)$row['action']);if(!$v['ok'])return $v;$action=(string)$row['action'];$payload=is_array($row['payload']??null)?$row['payload']:[];$result=['ok'=>false,'code'=>'APPROVAL_ACTION_UNKNOWN'];
    if($action==='self_update_install'){
        $pending=kicomSelfUpdatePending();$b=$row['binding']??[];
        $changed=!is_array($pending)
            ||!hash_equals((string)($b['from_version']??''),KICOM_VERSION)
            ||!hash_equals((string)($b['to_version']??''),(string)($pending['to_version']??''))
            ||!hash_equals((string)($b['package_sha256']??''),(string)($pending['zip_sha256']??''))
            ||!hash_equals((string)($b['risk_class']??''),(string)($pending['risk_class']??''))
            ||((bool)($b['kernel_update']??false)!==(bool)($pending['kernel_update']??false));
        $result=$changed?['ok'=>false,'code'=>'APPROVAL_BINDING_CHANGED']:kicomApplySelfUpdate($pending);
    }elseif($action==='deploy_write')$result=kicomApplyDeployProposal($payload['proposal']??[]);
    elseif($action==='deploy_package')$result=kicomApplyDeployProposal($payload['proposal']??[]);
    elseif($action==='deploy_rollback')$result=kicomApplyDeployProposal($payload['proposal']??[]);
    elseif($action==='self_update_rollback')$result=kicomRollbackSelfUpdate((string)($payload['history_id']??''));
    $row['status']=!empty($result['ok'])?'used':'failed';$row['used_at']=gmdate('c');$row['result_code']=(string)($result['code']??(!empty($result['ok'])?'OK':'FAILED'));
    kicomAuthJsonWrite(kicomAuthApprovalFile($id),$row);kicomLivingEvent('totp_approval_executed',!empty($result['ok'])?'info':'warn',['approval_id'=>$id,'action'=>$action,'result'=>$row['result_code']]);
    return $result+['approval_id'=>$id,'binding_sha256'=>(string)$row['binding_sha256']];
}'''

TX_CASES = r'''case 'AUTONOMY_TX_BEGIN':
    $r=kicomAutonomyTxBegin((string)($_GET['session_id']??''),(string)($_GET['token']??''));$lines=[KCL_PROTOCOL,($r['ok']?'OK':'ERROR').' autonomy_tx_begin','FACT request_id='.kclString($rid),'FACT code='.kclString((string)($r['code']??'OK'))];
    if($r['ok']){$lines[]='FACT tx_id='.kclString((string)$r['tx_id']);$lines[]='FACT tx_token='.kclString((string)$r['tx_token'],100);$lines[]='FACT expires_in='.(int)$r['expires_in'];$lines[]='FACT max_bytes='.(int)$r['max_bytes'];$lines[]='FACT max_fragment_bytes='.(int)$r['max_fragment_bytes'];$lines[]='RULE begin-does-not-rotate-main-session-token';}$lines[]='END';out($lines,$r['ok']?200:401);
case 'AUTONOMY_TX_APPEND':
    $r=kicomAutonomyTxAppend((string)($_GET['tx_id']??''),(string)($_GET['tx_token']??''),(int)($_GET['seq']??-1),(string)($_GET['data']??''));$lines=[KCL_PROTOCOL,($r['ok']?'OK':'ERROR').' autonomy_tx_append','FACT request_id='.kclString($rid),'FACT code='.kclString((string)($r['code']??'OK'))];
    foreach(['tx_id','next_seq','bytes','sha256'] as $k)if(isset($r[$k]))$lines[]='FACT '.$k.'='.kclString((string)$r[$k]);$lines[]='RULE inert-buffer-only-no-project-mutation';$lines[]='END';out($lines,$r['ok']?200:422);
case 'AUTONOMY_TX_STATUS':
    $x=kicomAutonomyTxGet((string)($_GET['tx_id']??''),(string)($_GET['tx_token']??''));$lines=[KCL_PROTOCOL,($x['ok']?'OK':'ERROR').' autonomy_tx_status','FACT request_id='.kclString($rid),'FACT code='.kclString((string)($x['code']??'OK'))];
    if($x['ok']){$r=$x['row'];$lines[]='FACT tx_id='.kclString((string)$r['id']);$lines[]='FACT next_seq='.(int)$r['next_seq'];$lines[]='FACT bytes='.(int)$r['bytes'];$lines[]='FACT sha256='.kclString(hash('sha256',(string)$r['payload']));$lines[]='FACT expires_in='.max(0,(int)$r['expires_at']-time());}$lines[]='END';out($lines,$x['ok']?200:401);
case 'AUTONOMY_TX_ABORT':
    $r=kicomAutonomyTxAbort((string)($_GET['tx_id']??''),(string)($_GET['tx_token']??''));out([KCL_PROTOCOL,($r['ok']?'OK':'ERROR').' autonomy_tx_abort','FACT request_id='.kclString($rid),'FACT code='.kclString((string)($r['code']??'OK')),'END'],$r['ok']?200:422);
case 'AUTONOMY_TX_COMMIT':
    $r=kicomAutonomyTxCommit((string)($_GET['tx_id']??''),(string)($_GET['tx_token']??''),(string)($_GET['token']??''),(string)($_GET['sha256']??''));$lines=[KCL_PROTOCOL,($r['ok']?'OK':'WARN').' autonomy_tx_commit','FACT request_id='.kclString($rid),'FACT code='.kclString((string)($r['code']??'OK'))];
    foreach(['tx_id','session_id','next_token','operations','sha256'] as $k)if(isset($r[$k]))$lines[]='FACT '.$k.'='.kclString((string)$r[$k],100);if(isset($r['results'])&&is_array($r['results']))foreach($r['results'] as $i=>$o)$lines[]='OP #'.($i+1).' ok='.(!empty($o['ok'])?'true':'false').' code='.kclString((string)($o['code']??'OK'));$lines[]='RULE commit-rotates-main-session-token-once';$lines[]='RULE ordered-stop-on-error-not-atomic';$lines[]='END';out($lines,$r['ok']?200:207);
'''

def main():
    if len(sys.argv)!=3: raise SystemExit('usage: build_0910_transaction_buffer.py BASE099.zip OUT0910.zip')
    base=Path(sys.argv[1]);out=Path(sys.argv[2])
    if sha(base.read_bytes())!=BASE_SHA: raise SystemExit('BASE_SHA_MISMATCH')
    root=Path(tempfile.mkdtemp(prefix='kicom0910-'))
    try:
        with zipfile.ZipFile(base) as z:z.extractall(root)
        libp=root/'lib.php';lib=libp.read_text();lib=replace_once(lib,"const KICOM_VERSION = '0.9.9';","const KICOM_VERSION = '0.9.10';",'version');libp.write_text(lib)
        livingp=root/'living.php';living=livingp.read_text();living=replace_once(living,'/* KiCom Living Architecture 0.9.9 */','/* KiCom Living Architecture 0.9.10 */','living comment');living=insert_after_function(living,'kicomAutonomyReadLeaseCheck',TX_FUNCS);living=replace_function(living,'kicomAuthApprovalCreate',APPROVAL_CREATE);living=replace_function(living,'kicomAuthApprovalExecute',APPROVAL_EXEC);livingp.write_text(living)
        indexp=root/'index.php';idx=indexp.read_text();idx=replace_once(idx,'https:get-read+compact-get-batch+post-mutation+guarded-get-bridge','https:get-read+transaction-buffer+compact-get-batch+post-mutation+guarded-get-bridge','hello transport');idx=replace_once(idx,"case 'AUTONOMY_COMPACT_BATCH':",TX_CASES+"\ncase 'AUTONOMY_COMPACT_BATCH':",'tx cases');idx=replace_once(idx,"'CAPABILITY AUTONOMY_BATCH post_patch_operations single_session_rotation compact_get_batch',","'CAPABILITY AUTONOMY_BATCH post_patch_operations single_session_rotation compact_get_batch','CAPABILITY TRANSACTION_BUFFER begin_nonrotating append_inert_1k commit_single_rotation max64k ttl600',",'describe capability');idx=replace_once(idx,"'RULE compact-get-batch-uses-one-session-rotation-and-existing-allowlists',","'RULE compact-get-batch-uses-one-session-rotation-and-existing-allowlists','RULE transaction-buffer-fragments-are-inert-and-commit-reuses-existing-allowlists-base-sha','RULE exact-red-approval-survives-session-idle-for-3600s-but-still-requires-fresh-totp-and-binding-recheck',",'describe rules');indexp.write_text(idx)
        ps=root/'memory/project_state.kcl';p=ps.read_text();p=re.sub(r'^VERSION "[0-9]+\.[0-9]+\.[0-9]+"','VERSION "0.9.10"',p,count=1,flags=re.M);p=re.sub(r'FACT genome_id="[^"]+"','FACT genome_id="kicom-0.9.10-g11"',p,count=1);p=re.sub(r'FACT genome_generation=\d+','FACT genome_generation=11',p,count=1);p=p.replace('END_PROJECT kicom','MILESTONE "Transaction buffer and resilient bound approvals" status=implemented version="0.9.10"\nEND_PROJECT kicom');ps.write_text(p)
        ar=root/'memory/architecture.kcl';a=ar.read_text();a=a.replace('END_ARCHITECTURE kicom','COMPONENT transaction_buffer role="inert bounded fragment buffer; commit executes existing patch allowlists with one main-session rotation"\nFLOW bound_red_resume="prepared exact binding may outlive session idle up to 3600s -> fresh FreeOTP -> binding recheck -> execute"\nEND_ARCHITECTURE kicom');ar.write_text(a)
        pr=root/'memory/protocol.kcl';q=pr.read_text();q=q.replace('END_PROTOCOL KCL/1','AUTONOMY transaction_buffer="BEGIN nonrotating -> APPEND <=1024-byte inert fragments -> COMMIT sha256 + one main-token rotation; max 64KiB/600s"\nAUTH bound_red_ttl="3600s; execution still requires fresh one-use FreeOTP and exact binding recheck"\nEND_PROTOCOL KCL/1');pr.write_text(q)
        de=root/'memory/decisions.kcl';d=de.read_text();d=d.replace('END_DECISIONS kicom','DECISION D015 status=accepted title="Buffer large chat transactions inertly and rotate the main session only at commit"\nRATIONALE D015 "Avoid URL and roundtrip overhead without granting fragment tokens any project-mutation authority"\nDECISION D016 status=accepted title="Let exact RED approvals survive session idle for one hour"\nRATIONALE D016 "A prepared binding remains hash- and state-bound; execution still requires a fresh one-use TOTP and binding revalidation"\nEND_DECISIONS kicom');de.write_text(d)
        ch=root/'memory/changelog.kcl';c=ch.read_text();c=c.replace('END_CHANGELOG kicom','RELEASE "0.9.10" date="2026-09-15" change="Transaction buffer for low-bandwidth chat clients and one-code execution of still-valid exact RED bindings after session idle"\nEND_CHANGELOG kicom');ch.write_text(c)
        nx=root/'memory/next.kcl';n=nx.read_text();n=n.replace('END_NEXT kicom','PRIORITY 1 goal="Use transaction buffer when compact GET would exceed client URL limits; keep fragments inert and commit-bound"\nEND_NEXT kicom');nx.write_text(n)
        gp=root/'genome/genome.json';g=json.loads(gp.read_text());g['id']=GENOME_ID;g['version']=VERSION;g['parent']=PARENT;g['generation']=GENERATION;g['created_at']=datetime.now(timezone.utc).isoformat();g['mutation_reason']='Reduce remaining chat transport friction with inert transaction buffering and longer-lived exact RED bindings, without weakening TOTP, verifier, allowlist, SHA or rollback boundaries.'
        for comp in g['components']:
            pth=comp['path']
            if (root/pth).is_file():comp['sha256']=sha((root/pth).read_bytes())
        gp.write_text(json.dumps(g,indent=2,ensure_ascii=False)+'\n')
        component_paths=[c['path'] for c in g['components']];package_paths=sorted(set(component_paths+['genome/genome.json','memory/project_state.kcl','memory/architecture.kcl','memory/protocol.kcl','memory/decisions.kcl','memory/changelog.kcl','memory/next.kcl']));(root/'MANIFEST.sha256').write_text(''.join(f'{sha((root/p).read_bytes())}  {p}\n' for p in package_paths));out.parent.mkdir(parents=True,exist_ok=True);out.unlink(missing_ok=True)
        with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
            for pth in sorted(package_paths+['MANIFEST.sha256']):z.write(root/pth,pth)
        print('PACKAGE_SHA256='+sha(out.read_bytes()))
        verify=Path(tempfile.mkdtemp(prefix='k099verify-'))
        try:
            with zipfile.ZipFile(base) as z:z.extractall(verify)
            code=f'''require {str(verify/'lib.php')!r};$x=kicomSelfUpdateZipInspect({str(out)!r});if(empty($x["ok"])){{echo "INSPECT=".($x["code"]??"?")."\\n";exit(31);}}$r=kicomSelfUpdateRiskClass($x);echo "RISK=".($r["class"]??"?")."\\n";echo "VERSION=".($x["version"]??"?")."\\n";if(($x["version"]??"")!=="0.9.10"||($x["genome"]["id"]??"")!=="kicom-0.9.10-g11")exit(32);''';rr=run(['php','-r',code]);print(rr.stdout,end='')
        finally:shutil.rmtree(verify,ignore_errors=True)
        for pth in sorted(root.rglob('*.php')):
            rr=run(['php','-l',str(pth)]);print(rr.stdout,end='')
        fresh=Path(tempfile.mkdtemp(prefix='k0910fresh-'))
        try:
            with zipfile.ZipFile(out) as z:z.extractall(fresh)
            test=Path('/tmp/test0910.php');test.write_text(f'''<?php
chdir({str(fresh)!r});require {str(fresh/'lib.php')!r};if(KICOM_VERSION!=="0.9.10")exit(41);if(!kicomEnsureStorage())exit(42);$setup=kicomTotpSetupBegin('CI');$counter=intdiv(time(),30);$code=kicomTotpCodeForCounter($setup['secret'],$counter,6);if(empty(kicomTotpSetupConfirm($code)['ok']))exit(43);$cfg=kicomAuthJsonRead(kicomTotpFile());$cfg['last_counter']=$counter-1;kicomAuthJsonWrite(kicomTotpFile(),$cfg);$s=kicomAutonomySessionOpen($code);if(empty($s['ok']))exit(44);$peek=kicomAutonomySessionPeek($s['session_id'],$s['token']);if(empty($peek['ok']))exit(45);$tx=kicomAutonomyTxBegin($s['session_id'],$s['token']);if(empty($tx['ok'])){{var_dump($tx);exit(46);}}$mr=kicomReadMemoryResource('PROJECT_STATE');$ops=[['kind'=>'memory_patch','resource'=>'PROJECT_STATE','base_sha256'=>$mr['sha256'],'find'=>'STATE active','replace'=>'STATE active']];$payload=json_encode($ops,JSON_UNESCAPED_SLASHES);$parts=str_split($payload,127);foreach($parts as $i=>$part){{$a=kicomAutonomyTxAppend($tx['tx_id'],$tx['tx_token'],$i,kicomEncodeBase64Url($part));if(empty($a['ok'])){{var_dump($a);exit(47);}}}}$before=kicomAutonomySessionPeek($s['session_id'],$s['token']);if(empty($before['ok']))exit(48);$commit=kicomAutonomyTxCommit($tx['tx_id'],$tx['tx_token'],$s['token'],hash('sha256',$payload));if(empty($commit['ok'])||empty($commit['next_token'])){{var_dump($commit);exit(49);}}$old=kicomAutonomySessionPeek($s['session_id'],$s['token']);if(!empty($old['ok']))exit(50);$new=kicomAutonomySessionPeek($s['session_id'],$commit['next_token']);if(empty($new['ok']))exit(51);$ap=kicomAuthApprovalCreate($s['session_id'],'ci_exact_red',['x'=>'y'],[],'red');if(empty($ap['ok'])||($ap['expires_in']??0)<3600){{var_dump($ap);exit(52);}}$sf=kicomAutonomySessionFile($s['session_id']);$sr=kicomAuthJsonRead($sf);$sr['idle_expires_at']=time()-1;kicomAuthJsonWrite($sf,$sr);$saved=kicomAuthApprovalGet($ap['approval_id']);if(!is_array($saved)||($saved['status']??'')!=='pending'||($saved['expires_at']??0)<=time())exit(53);echo "TRANSACTION_BUFFER_OK\\n";
''');rr=run(['php',str(test)]);print(rr.stdout,end='')
        finally:shutil.rmtree(fresh,ignore_errors=True)
    finally:shutil.rmtree(root,ignore_errors=True)

if __name__=='__main__':main()
