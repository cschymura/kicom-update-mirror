#!/usr/bin/env python3
import hashlib, json, os, re, shutil, subprocess, sys, tempfile, zipfile
from pathlib import Path
from datetime import datetime, timezone

BASE_SHA = '02120f28a953f01987fa1f650a77ee6bfe4dc3c0bf685ca2ef48d512987baef9'
VERSION = '0.9.7'
GENOME_ID = 'kicom-0.9.7-g8'
PARENT = 'kicom-0.9.6-g7'
GENERATION = 8


def sha(b: bytes) -> str:
    return hashlib.sha256(b).hexdigest()


def replace_php_function(src: str, name: str, new: str) -> str:
    needle = 'function ' + name + '('
    start = src.find(needle)
    if start < 0:
        raise RuntimeError(f'function not found: {name}')
    brace = src.find('{', start)
    if brace < 0:
        raise RuntimeError(f'function brace not found: {name}')
    depth = 0
    i = brace
    quote = None
    esc = False
    while i < len(src):
        c = src[i]
        if quote is not None:
            if esc:
                esc = False
            elif c == '\\':
                esc = True
            elif c == quote:
                quote = None
        else:
            if c in ('\"', "'"):
                quote = c
            elif c == '{':
                depth += 1
            elif c == '}':
                depth -= 1
                if depth == 0:
                    return src[:start] + new.rstrip() + src[i+1:]
        i += 1
    raise RuntimeError(f'unclosed function: {name}')


def run(cmd, **kw):
    print('+', ' '.join(map(str, cmd)))
    return subprocess.run(cmd, check=True, text=True, capture_output=True, **kw)


def main():
    if len(sys.argv) != 3:
        raise SystemExit('usage: build_097_resilient_autonomy.py BASE096.zip OUT097.zip')
    base = Path(sys.argv[1]); out = Path(sys.argv[2])
    if sha(base.read_bytes()) != BASE_SHA:
        raise SystemExit('BASE_SHA_MISMATCH')
    root = Path(tempfile.mkdtemp(prefix='kicom097-'))
    try:
        with zipfile.ZipFile(base) as z: z.extractall(root)
        lib = (root/'lib.php').read_text()
        if "const KICOM_VERSION = '0.9.6';" not in lib:
            raise SystemExit('LIB_VERSION_ANCHOR_MISSING')
        lib = lib.replace("const KICOM_VERSION = '0.9.6';", "const KICOM_VERSION = '0.9.7';", 1)
        (root/'lib.php').write_text(lib)

        living = (root/'living.php').read_text()
        living = replace_php_function(living, 'kicomTotpPublicStatus', r'''function kicomTotpPublicStatus(): array {
    $cfg=kicomTotpConfig();$p=kicomTotpPending();
    /* A confirmed configuration is authoritative; stale pending enrollment state is housekeeping only. */
    if($cfg!==null&&$p!==null){@unlink(kicomTotpPendingFile());$p=null;}
    return ['configured'=>$cfg!==null,'pending'=>$p!==null,'digits'=>6,'period'=>30,'algorithm'=>'SHA1'];
}''')
        living = replace_php_function(living, 'kicomAutonomySessionConsume', r'''function kicomAutonomySessionConsume(string $id,string $token): array {
    $policy=kicomAutonomyPolicy();if(empty($policy['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];$id=strtolower(trim($id));$token=strtolower(trim($token));if(!preg_match('/^[a-f0-9]{24}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$token))return ['ok'=>false,'code'=>'SESSION_AUTH_INVALID'];
    $file=kicomAutonomySessionFile($id);$r=kicomAuthJsonRead($file);if(!is_array($r))return ['ok'=>false,'code'=>'SESSION_NOT_FOUND'];$now=time();
    if((int)($r['absolute_expires_at']??0)<$now||(int)($r['idle_expires_at']??0)<$now){@unlink($file);return ['ok'=>false,'code'=>'SESSION_EXPIRED'];}
    $present=hash('sha256',$token);$current=(string)($r['token_hash']??'');$previous=(string)($r['previous_token_hash']??'');$previousUntil=(int)($r['previous_token_until']??0);
    $normal=$current!==''&&hash_equals($current,$present);$recover=!$normal&&$previous!==''&&$previousUntil>=$now&&hash_equals($previous,$present);
    if(!$normal&&!$recover)return ['ok'=>false,'code'=>'SESSION_TOKEN_REJECTED'];
    $next=kicomAuthRandomHex(32);
    if($normal){$r['previous_token_hash']=$current;$r['previous_token_until']=$now+60;}else{$r['previous_token_hash']='';$r['previous_token_until']=0;$r['recovered_at']=gmdate('c');}
    $r['token_hash']=hash('sha256',$next);$r['last_used_at']=gmdate('c');$r['idle_expires_at']=min((int)$r['absolute_expires_at'],$now+(int)$policy['idle_ttl']);
    if(!kicomAuthJsonWrite($file,$r))return ['ok'=>false,'code'=>'SESSION_ROTATE_FAILED'];
    return ['ok'=>true,'session_id'=>$id,'next_token'=>$next,'expires_at'=>(int)$r['absolute_expires_at'],'idle_expires_at'=>(int)$r['idle_expires_at'],'recovered'=>$recover];
}''')
        living = replace_php_function(living, 'kicomArchiveEnsureDeny', r'''function kicomArchiveEnsureDeny(): array {
    $t=kicomArchiveTarget();if($t===null)return ['ok'=>false,'code'=>'ARCHIVE_TARGET_UNAVAILABLE'];
    $root=realpath((string)$t['root']);if($root===false)return ['ok'=>false,'code'=>'ARCHIVE_ROOT_INVALID'];$full=rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.htaccess';
    if(is_file($full)){
        $raw=@file_get_contents($full);if($raw===false)return ['ok'=>false,'code'=>'ARCHIVE_DENY_READ_FAILED'];
        $noIndex=(bool)preg_match('/^\s*Options\s+-Indexes\s*$/mi',$raw);$deny=(bool)preg_match('/Require\s+all\s+denied/i',$raw)||(bool)preg_match('/^\s*Deny\s+from\s+all\s*$/mi',$raw);
        if($noIndex&&$deny)return ['ok'=>true,'code'=>'ARCHIVE_DENY_PRESENT','sha256'=>hash('sha256',$raw)];
        return ['ok'=>false,'code'=>'ARCHIVE_DENY_POLICY_CONFLICT'];
    }
    return kicomArchiveWrite('.htaccess',kicomDenyRules());
}''')
        living = replace_php_function(living, 'kicomArchiveWrite', r'''function kicomArchiveWrite(string $rel,string $raw): array {
    $t=kicomArchiveTarget();$rel=kicomArchiveSafeRel($rel);if($t===null||$rel===null)return ['ok'=>false,'code'=>'ARCHIVE_TARGET_UNAVAILABLE'];$root=(string)$t['root'];$rootReal=realpath($root);if($rootReal===false)return ['ok'=>false,'code'=>'ARCHIVE_ROOT_INVALID'];$full=rtrim($rootReal,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);$dir=dirname($full);if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))return ['ok'=>false,'code'=>'ARCHIVE_DIR_FAILED'];$rootNorm=rtrim(str_replace('\\','/',$rootReal),'/').'/';$dirReal=realpath($dir);if($dirReal===false||!str_starts_with(rtrim(str_replace('\\','/',$dirReal),'/').'/', $rootNorm))return ['ok'=>false,'code'=>'ARCHIVE_PATH_ESCAPE'];$sha=hash('sha256',$raw);
    if(is_file($full)){
        $cur=hash_file('sha256',$full)?:'';if(hash_equals($cur,$sha))return ['ok'=>true,'code'=>'ALREADY_ARCHIVED','sha256'=>$sha];
        /* release.json carries timestamps and provenance; immutable package identity is what makes a repeated archive operation idempotent. */
        if(basename($rel)==='release.json'){$old=json_decode((string)@file_get_contents($full),true);$new=json_decode($raw,true);if(is_array($old)&&is_array($new)){foreach(['product','version','genome_id','parent','generation','package_sha256','manifest_sha256'] as $k){if((string)($old[$k]??'')!==(string)($new[$k]??''))return ['ok'=>false,'code'=>'ARCHIVE_CONFLICT','current_sha256'=>$cur];}return ['ok'=>true,'code'=>'ALREADY_ARCHIVED_METADATA','sha256'=>$cur];}}
        return ['ok'=>false,'code'=>'ARCHIVE_CONFLICT','current_sha256'=>$cur];
    }
    $tmp=$full.'.tmp-'.strtolower(kicomRequestId());if(@file_put_contents($tmp,$raw,LOCK_EX)===false)return ['ok'=>false,'code'=>'ARCHIVE_WRITE_FAILED'];@chmod($tmp,0640);if(!@rename($tmp,$full)){@unlink($tmp);return ['ok'=>false,'code'=>'ARCHIVE_RENAME_FAILED'];}@chmod($full,0640);return ['ok'=>true,'code'=>'ARCHIVED','sha256'=>$sha];
}''')
        (root/'living.php').write_text(living)

        genome_p = root/'genome/genome.json'
        g = json.loads(genome_p.read_text())
        g['id']=GENOME_ID; g['version']=VERSION; g['parent']=PARENT; g['generation']=GENERATION
        g['created_at']=datetime.now(timezone.utc).isoformat()
        g['mutation_reason']='Harden bounded autonomy after live first-party testing: recover rolling sessions after lost error responses, clear stale TOTP enrollment state, and make append-only release archival semantically idempotent without weakening archive denial.'
        # Update component hashes for changed components after final writes.
        for c in g['components']:
            p=c['path']
            if p in ('lib.php','living.php'):
                c['sha256']=sha((root/p).read_bytes())
        genome_p.write_text(json.dumps(g,indent=2,ensure_ascii=False)+'\n')

        component_paths=[c['path'] for c in g['components']]
        package_paths=component_paths+['genome/genome.json']
        # Verify unchanged components exactly match the trusted 0.9.6 genome before packaging.
        oldg=json.loads(zipfile.ZipFile(base).read('genome/genome.json'))
        oldmap={c['path']:c['sha256'] for c in oldg['components']}
        for p in component_paths:
            got=sha((root/p).read_bytes())
            if p not in ('lib.php','living.php') and got!=oldmap[p]:
                raise SystemExit('TRUSTED_COMPONENT_DRIFT:'+p)
        manifest=''.join(f"{sha((root/p).read_bytes())}  {p}\n" for p in sorted(package_paths))
        (root/'MANIFEST.sha256').write_text(manifest)
        out.parent.mkdir(parents=True,exist_ok=True)
        if out.exists(): out.unlink()
        with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
            for p in sorted(package_paths+['MANIFEST.sha256']): z.write(root/p,p)
        print('PACKAGE_SHA256='+sha(out.read_bytes()))
        print('LIVING_SHA256='+sha((root/'living.php').read_bytes()))
        print('LIB_SHA256='+sha((root/'lib.php').read_bytes()))

        # Exact 0.9.6 verifier must accept and classify this as yellow.
        verify = Path(tempfile.mkdtemp(prefix='kicom096-verify-'))
        try:
            with zipfile.ZipFile(base) as z: z.extractall(verify)
            php = f'''require {str(verify/'lib.php')!r};$x=kicomSelfUpdateZipInspect({str(out)!r});if(empty($x["ok"])){{echo "INSPECT=".($x["code"]??"?")."\\n";exit(31);}}$r=kicomSelfUpdateRiskClass($x);echo "RISK=".($r["class"]??"?")."\\n";if(($x["version"]??"")!=="0.9.7"||($x["genome"]["id"]??"")!=="kicom-0.9.7-g8")exit(32);if(($r["class"]??"")!=="yellow")exit(33);'''
            rr=run(['php','-r',php]); print(rr.stdout,end='')
        finally: shutil.rmtree(verify,ignore_errors=True)

        # Functional regression test in a complete 0.9.6 tree upgraded in place to 0.9.7 files.
        testroot=Path(tempfile.mkdtemp(prefix='kicom097-test-')); archive=Path(tempfile.mkdtemp(prefix='kicom097-archive-'))
        try:
            with zipfile.ZipFile(base) as z: z.extractall(testroot)
            with zipfile.ZipFile(out) as z: z.extractall(testroot)
            (archive/'.htaccess').write_text("Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n# local archive policy\n")
            script=Path('/tmp/test097.php')
            script.write_text(f'''<?php
chdir({str(testroot)!r});require {str(testroot/'lib.php')!r};if(KICOM_VERSION!=="0.9.7")exit(41);if(!kicomEnsureStorage())exit(42);
$targets=['kicomarchive'=>['root'=>{str(archive)!r},'health_url'=>'','enabled'=>true,'label'=>'KiCom Langzeitarchiv','class'=>'staging']];if(!kicomSaveDeployTargets($targets))exit(43);
$d=kicomArchiveEnsureDeny();if(empty($d['ok'])){{var_dump($d);exit(44);}}
$setup=kicomTotpSetupBegin('CI');$counter=intdiv(time(),30);$code=kicomTotpCodeForCounter($setup['secret'],$counter,6);if(empty(kicomTotpSetupConfirm($code)['ok']))exit(45);$cfg=kicomAuthJsonRead(kicomTotpFile());$cfg['last_counter']=$counter-1;kicomAuthJsonWrite(kicomTotpFile(),$cfg);$s=kicomAutonomySessionOpen($code);if(empty($s['ok']))exit(46);$a=kicomAutonomySessionConsume($s['session_id'],$s['token']);if(empty($a['ok']))exit(47);$b=kicomAutonomySessionConsume($s['session_id'],$s['token']);if(empty($b['ok'])||empty($b['recovered']))exit(48);$old=kicomAutonomySessionConsume($s['session_id'],$a['next_token']);if(!empty($old['ok']))exit(49);
$st=kicomTotpPublicStatus();if(!empty($st['pending']))exit(50);
echo "RESILIENT_AUTONOMY_OK\\n";
''')
            rr=run(['php',str(script)]); print(rr.stdout,end='')
        finally:
            shutil.rmtree(testroot,ignore_errors=True); shutil.rmtree(archive,ignore_errors=True)
    finally:
        shutil.rmtree(root,ignore_errors=True)

if __name__=='__main__': main()
