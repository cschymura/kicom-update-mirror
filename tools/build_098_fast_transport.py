#!/usr/bin/env python3
import hashlib, json, shutil, subprocess, sys, tempfile, zipfile
from pathlib import Path
from datetime import datetime, timezone

BASE_SHA='608b886d6adab24e4226e1fc6d2c2f07a5937f12e7817ffa2fd0817b5902690f'
VERSION='0.9.8'
GENOME_ID='kicom-0.9.8-g9'
PARENT='kicom-0.9.7-g8'
GENERATION=9

def sha(b): return hashlib.sha256(b).hexdigest()
def run(cmd,check=True):
    print('+',' '.join(map(str,cmd)))
    return subprocess.run(cmd,text=True,capture_output=True,check=check)
def replace_once(s,a,b,label):
    if a not in s: raise RuntimeError('anchor missing: '+label)
    return s.replace(a,b,1)
def replace_php_function(src,name,new):
    start=src.find('function '+name+'(')
    if start<0: raise RuntimeError('function not found: '+name)
    brace=src.find('{',start); depth=0; i=brace; quote=None; esc=False
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
                if depth==0: return src[:start]+new.rstrip()+src[i+1:]
        i+=1
    raise RuntimeError('unclosed function: '+name)
def append_after_function(src,name,extra):
    start=src.find('function '+name+'(')
    if start<0: raise RuntimeError('function not found: '+name)
    brace=src.find('{',start); depth=0; i=brace; quote=None; esc=False
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
                if depth==0:return src[:i+1]+'\n'+extra.rstrip()+src[i+1:]
        i+=1
    raise RuntimeError('unclosed function: '+name)

def main():
    if len(sys.argv)!=3: raise SystemExit('usage: build_098 BASE097.zip OUT098.zip')
    base=Path(sys.argv[1]); out=Path(sys.argv[2])
    if sha(base.read_bytes())!=BASE_SHA: raise SystemExit('BASE_SHA_MISMATCH')
    root=Path(tempfile.mkdtemp(prefix='k098-'))
    try:
        with zipfile.ZipFile(base) as z:z.extractall(root)
        p=root/'lib.php'; s=p.read_text()
        s=replace_once(s,"const KICOM_VERSION = '0.9.7';","const KICOM_VERSION = '0.9.8';",'version')
        s=replace_once(s,"const KICOM_MAX_POST_BYTES = 524288;","const KICOM_MAX_POST_BYTES = 524288;\nconst KICOM_AUTONOMY_SOURCE_READ_BYTES = 262144;\nconst KICOM_AUTONOMY_GET_CHUNK_BYTES = 12000;",'limits')
        p.write_text(s)

        p=root/'living.php'; s=p.read_text()
        s=replace_php_function(s,'kicomAutonomySourceRead',r'''function kicomAutonomySourceRead(string $path,int $offset=0,int $length=262144): array {
    $path=kicomSafeUpdatePath($path);if($path===null||!in_array($path,kicomAutonomySourcePaths(),true))return ['ok'=>false,'code'=>'SOURCE_PATH_FORBIDDEN'];$f=kicomBaseDir().'/'.$path;if(!is_file($f))return ['ok'=>false,'code'=>'SOURCE_FILE_MISSING'];$size=(int)filesize($f);$offset=max(0,$offset);$length=max(1,min(KICOM_AUTONOMY_SOURCE_READ_BYTES,$length));if($offset>$size)return ['ok'=>false,'code'=>'SOURCE_OFFSET_INVALID'];$h=@fopen($f,'rb');if(!$h)return ['ok'=>false,'code'=>'SOURCE_READ_FAILED'];fseek($h,$offset);$raw=(string)fread($h,$length);fclose($h);$next=$offset+strlen($raw);return ['ok'=>true,'path'=>$path,'bytes'=>$size,'sha256'=>hash_file('sha256',$f)?:'','offset'=>$offset,'next_offset'=>$next,'eof'=>$next>=$size,'data'=>kicomEncodeBase64Url($raw)];
}''')
        s=append_after_function(s,'kicomAutonomySessionConsume',r'''function kicomAutonomySessionReadAuth(string $id,string $token): array {
    $policy=kicomAutonomyPolicy();if(empty($policy['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];$id=strtolower(trim($id));$token=strtolower(trim($token));if(!preg_match('/^[a-f0-9]{24}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$token))return ['ok'=>false,'code'=>'SESSION_AUTH_INVALID'];$r=kicomAuthJsonRead(kicomAutonomySessionFile($id));if(!is_array($r))return ['ok'=>false,'code'=>'SESSION_NOT_FOUND'];$now=time();if((int)($r['absolute_expires_at']??0)<$now||(int)($r['idle_expires_at']??0)<$now)return ['ok'=>false,'code'=>'SESSION_EXPIRED'];$present=hash('sha256',$token);$current=(string)($r['token_hash']??'');if($current===''||!hash_equals($current,$present))return ['ok'=>false,'code'=>'SESSION_TOKEN_REJECTED'];return ['ok'=>true,'session_id'=>$id,'token_unchanged'=>true,'expires_at'=>(int)$r['absolute_expires_at'],'idle_expires_at'=>(int)$r['idle_expires_at']];
}''')
        s=replace_php_function(s,'kicomAutonomyUploadBegin',r'''function kicomAutonomyUploadBegin(string $sessionId,string $kind,array $meta): array {
    kicomAutonomyUploadCleanup();$allowed=['workspace','memory_snapshot','memory_resource','self_update'];if(!in_array($kind,$allowed,true))return ['ok'=>false,'code'=>'UPLOAD_KIND_INVALID'];$id=substr(kicomAuthRandomHex(10),0,20);$dir=kicomAutonomyUploadDir($id);if(!@mkdir($dir,0700,true)&&!is_dir($dir))return ['ok'=>false,'code'=>'UPLOAD_CREATE_FAILED'];$max=$kind==='self_update'?KICOM_MAX_SELF_UPDATE_ZIP_BYTES:($kind==='memory_snapshot'?KICOM_MAX_MEMORY_ARCHIVE_SNAPSHOT_BYTES:1048576);$row=['schema'=>1,'id'=>$id,'session_id'=>$sessionId,'kind'=>$kind,'meta'=>$meta,'created_at'=>gmdate('c'),'expires_at'=>time()+900,'next_seq'=>0,'bytes'=>0,'max_bytes'=>$max];if(!kicomAuthJsonWrite($dir.'/meta.json',$row))return ['ok'=>false,'code'=>'UPLOAD_META_FAILED'];return ['ok'=>true,'upload_id'=>$id,'max_chunk_bytes'=>KICOM_AUTONOMY_GET_CHUNK_BYTES,'max_bytes'=>$max,'expires_in'=>900];
}''')
        s=replace_php_function(s,'kicomAutonomyUploadChunk',r'''function kicomAutonomyUploadChunk(string $sessionId,string $id,int $seq,string $encoded): array {
    $x=kicomAutonomyUploadMeta($sessionId,$id);if(!$x['ok'])return $x;$m=$x['meta'];if($seq!==(int)($m['next_seq']??0)||$seq<0||$seq>4096)return ['ok'=>false,'code'=>'UPLOAD_SEQUENCE_INVALID'];$raw=kicomDecodeBase64Url($encoded,true);if($raw===null||strlen($raw)>KICOM_AUTONOMY_GET_CHUNK_BYTES)return ['ok'=>false,'code'=>'UPLOAD_CHUNK_INVALID'];$bytes=(int)$m['bytes']+strlen($raw);if($bytes>(int)$m['max_bytes'])return ['ok'=>false,'code'=>'UPLOAD_TOO_LARGE'];$file=$x['dir'].'/'.sprintf('%05d.part',$seq);if(@file_put_contents($file,$raw,LOCK_EX)===false)return ['ok'=>false,'code'=>'UPLOAD_CHUNK_WRITE_FAILED'];$m['next_seq']=$seq+1;$m['bytes']=$bytes;$m['expires_at']=time()+900;kicomAuthJsonWrite($x['dir'].'/meta.json',$m);return ['ok'=>true,'next_seq'=>$m['next_seq'],'bytes'=>$bytes];
}''')
        p.write_text(s)

        p=root/'index.php'; s=p.read_text()
        anchor="""function autonomySessionFacts(array $s): array {
    return ['FACT session_id='.kclString((string)$s['session_id']),'FACT next_token='.kclString((string)$s['next_token'],100),'FACT session_expires_at='.(int)$s['expires_at'],'FACT session_idle_expires_at='.(int)$s['idle_expires_at']];
}"""
        extra=r'''function requireAutonomyReadSession(string $rid): array {$r=kicomAutonomySessionReadAuth(strtolower((string)($_GET['session_id']??'')),strtolower((string)($_GET['token']??'')));if(!$r['ok'])out([KCL_PROTOCOL,'ERROR autonomy_read_session','FACT request_id='.kclString($rid),'FACT code='.kclString((string)$r['code']),'REQUIRES freeotp_session=true','END'],401);return $r;}
function autonomyReadFacts(array $s): array {return ['FACT session_id='.kclString((string)$s['session_id']),'FACT token_unchanged=true','FACT session_expires_at='.(int)$s['expires_at'],'FACT session_idle_expires_at='.(int)$s['idle_expires_at']];}'''
        s=replace_once(s,anchor,anchor+'\n'+extra,'read auth helpers')
        s=replace_once(s,"case 'AUTONOMY_SOURCE_LIST':\n    $ss=requireAutonomySession($rid);","case 'AUTONOMY_SOURCE_LIST':\n    $ss=requireAutonomyReadSession($rid);",'source list auth')
        s=replace_once(s,"$lines=array_merge($lines,autonomySessionFacts($ss));foreach($rows as $i=>$f)$lines[]='FILE #'.($i+1)","$lines=array_merge($lines,autonomyReadFacts($ss));foreach($rows as $i=>$f)$lines[]='FILE #'.($i+1)",'source list facts')
        old="""case 'AUTONOMY_SOURCE_READ':
    $ss=requireAutonomySession($rid);$r=kicomAutonomySourceRead((string)($_GET['path']??''),(int)($_GET['offset']??0),(int)($_GET['length']??3000));$lines=[KCL_PROTOCOL,($r['ok']?'OK':'ERROR').' autonomy_source_read','FACT request_id='.kclString($rid),'FACT code='.kclString((string)($r['code']??'OK'))];$lines=array_merge($lines,autonomySessionFacts($ss));if($r['ok']){$lines[]='FACT path='.kclString((string)$r['path']);$lines[]='FACT bytes='.(int)$r['bytes'];$lines[]='FACT sha256='.kclString((string)$r['sha256']);$lines[]='FACT offset='.(int)$r['offset'];$lines[]='FACT next_offset='.(int)$r['next_offset'];$lines[]='FACT eof='.($r['eof']?'true':'false');$lines[]='DATA encoding=\"base64url\" value='.kclString((string)$r['data'],5000);}$lines[]='END';out($lines,$r['ok']?200:422);"""
        new="""case 'AUTONOMY_SOURCE_READ':
    $ss=requireAutonomyReadSession($rid);$r=kicomAutonomySourceRead((string)($_GET['path']??''),(int)($_GET['offset']??0),(int)($_GET['length']??KICOM_AUTONOMY_SOURCE_READ_BYTES));$lines=[KCL_PROTOCOL,($r['ok']?'OK':'ERROR').' autonomy_source_read','FACT request_id='.kclString($rid),'FACT code='.kclString((string)($r['code']??'OK'))];$lines=array_merge($lines,autonomyReadFacts($ss));if($r['ok']){$lines[]='FACT path='.kclString((string)$r['path']);$lines[]='FACT bytes='.(int)$r['bytes'];$lines[]='FACT sha256='.kclString((string)$r['sha256']);$lines[]='FACT offset='.(int)$r['offset'];$lines[]='FACT next_offset='.(int)$r['next_offset'];$lines[]='FACT eof='.($r['eof']?'true':'false');$lines[]='DATA encoding=\"base64url\" value='.kclString((string)$r['data'],400000);}$lines[]='END';out($lines,$r['ok']?200:422);"""
        s=replace_once(s,old,new,'source read case')
        s=s.replace('CAPABILITY DIRECT_SOURCE trusted_manifest_chunk_read','CAPABILITY DIRECT_SOURCE trusted_manifest_large_read stable_read_token')
        s=s.replace('RULE autonomy-session-token-rolls-after-every-request','RULE autonomy-session-token-rolls-after-every-mutation\nRULE read-only-source-auth-does-not-rotate-session-token')
        s=s.replace('FACT transport="https:get-read+post-mutation+guarded-get-bridge"','FACT transport="https:large-get-read+post-mutation+guarded-get-fallback"')
        p.write_text(s)

        ps=root/'memory/project_state.kcl'; s=ps.read_text().replace('VERSION "0.9.7"','VERSION "0.9.8"',1)
        if 'fast_transport' not in s:s=s.replace('END_PROJECT kicom','FACT fast_transport="262144-byte source reads; 12000-byte GET fallback chunks; read token stable"\nEND_PROJECT kicom')
        ps.write_text(s)
        ch=root/'memory/changelog.kcl'; s=ch.read_text();
        if 'RELEASE "0.9.8"' not in s:s=s.replace('END_CHANGELOG kicom','RELEASE "0.9.8" date="2026-09-15" change="Fast transport bootstrap: large manifest-bound source reads without token rotation and 12 KiB GET fallback chunks"\nEND_CHANGELOG kicom')
        ch.write_text(s)

        gp=root/'genome/genome.json'; g=json.loads(gp.read_text());g['id']=GENOME_ID;g['version']=VERSION;g['parent']=PARENT;g['generation']=GENERATION;g['created_at']=datetime.now(timezone.utc).isoformat();g['mutation_reason']='Performance goal phase 1: large manifest-bound source reads without token rotation and larger GET fallback upload chunks while preserving all critical trust boundaries.'
        for c in g['components']:
            if c['path'] in {'lib.php','living.php','index.php'}:c['sha256']=sha((root/c['path']).read_bytes())
        gp.write_text(json.dumps(g,indent=2,ensure_ascii=False)+'\n')

        paths=[x.relative_to(root).as_posix() for x in root.rglob('*') if x.is_file() and x.relative_to(root).as_posix()!='MANIFEST.sha256']
        (root/'MANIFEST.sha256').write_text(''.join(f'{sha((root/x).read_bytes())}  {x}\n' for x in sorted(paths)))
        out.parent.mkdir(parents=True,exist_ok=True);out.unlink(missing_ok=True)
        with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
            for x in sorted(paths+['MANIFEST.sha256']):z.write(root/x,x)
        print('PACKAGE_SHA256='+sha(out.read_bytes()))
        for f in ['lib.php','living.php','index.php','api.php','admin.php','recovery.php','guardian.php']:
            r=run(['php','-l',str(root/f)],False);print(r.stdout,end='');print(r.stderr,end='')
            if r.returncode:raise SystemExit('PHP_LINT_FAILED:'+f)
        verify=Path(tempfile.mkdtemp(prefix='k097verify-'))
        try:
            with zipfile.ZipFile(base) as z:z.extractall(verify)
            code=f'''require {str(verify/'lib.php')!r};$x=kicomSelfUpdateZipInspect({str(out)!r});if(empty($x["ok"])){{echo "INSPECT=".($x["code"]??"?")."\\n";exit(31);}}$r=kicomSelfUpdateRiskClass($x);echo "RISK=".($r["class"]??"?")."\\n";echo "VERSION=".($x["version"]??"?")."\\n";if(($x["version"]??"")!=="0.9.8")exit(32);'''
            q=run(['php','-r',code]);print(q.stdout,end='')
        finally:shutil.rmtree(verify,ignore_errors=True)
        fresh=Path(tempfile.mkdtemp(prefix='k098fresh-'))
        try:
            with zipfile.ZipFile(out) as z:z.extractall(fresh)
            t=Path('/tmp/test098.php');t.write_text(f'''<?php
chdir({str(fresh)!r});require {str(fresh/'lib.php')!r};if(KICOM_VERSION!=="0.9.8")exit(41);if(!kicomEnsureStorage())exit(42);$setup=kicomTotpSetupBegin("CI");$ctr=intdiv(time(),30);$code=kicomTotpCodeForCounter($setup["secret"],$ctr,6);if(empty(kicomTotpSetupConfirm($code)["ok"]))exit(43);$cfg=kicomAuthJsonRead(kicomTotpFile());$cfg["last_counter"]=$ctr-1;kicomAuthJsonWrite(kicomTotpFile(),$cfg);$sess=kicomAutonomySessionOpen($code);if(empty($sess["ok"]))exit(44);$r=kicomAutonomySessionReadAuth($sess["session_id"],$sess["token"]);if(empty($r["ok"])||empty($r["token_unchanged"]))exit(45);$src=kicomAutonomySourceRead("lib.php",0,65536);if(empty($src["ok"])||strlen(kicomDecodeBase64Url($src["data"],true))<10000)exit(46);if(KICOM_AUTONOMY_GET_CHUNK_BYTES<=3000)exit(47);echo "FAST_TRANSPORT_OK\\n";
''')
            q=run(['php',str(t)]);print(q.stdout,end='')
        finally:shutil.rmtree(fresh,ignore_errors=True)
    finally:shutil.rmtree(root,ignore_errors=True)
if __name__=='__main__':main()
