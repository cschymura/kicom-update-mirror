#!/usr/bin/env python3
import json, os, shutil, subprocess, sys, tempfile, zipfile, hashlib
from pathlib import Path

MEMORY=['memory/project_state.kcl','memory/architecture.kcl','memory/protocol.kcl','memory/decisions.kcl','memory/changelog.kcl','memory/next.kcl']

def sha(b): return hashlib.sha256(b).hexdigest()

def run(cmd, check=True):
    print('+',' '.join(map(str,cmd)))
    return subprocess.run(cmd,text=True,capture_output=True,check=check)

def main():
    if len(sys.argv)!=3: raise SystemExit('usage: v2 BASE096.zip OUT097.zip')
    base=Path(sys.argv[1]); out=Path(sys.argv[2])
    # Stage one creates the verified 0.9.7 core package. Its old fresh-install test may fail
    # because it intentionally did not yet carry updated memory seeds; that is repaired below.
    r=run([sys.executable,'tools/build_097_resilient_autonomy.py',str(base),str(out)],check=False)
    print(r.stdout,end=''); print(r.stderr,end='')
    if not out.is_file(): raise SystemExit('STAGE1_PACKAGE_MISSING')
    work=Path(tempfile.mkdtemp(prefix='k097v2-'))
    try:
        with zipfile.ZipFile(out) as z: z.extractall(work)
        with zipfile.ZipFile(base) as z:
            for p in MEMORY:
                if not (work/p).exists():
                    (work/p).parent.mkdir(parents=True,exist_ok=True)
                    (work/p).write_bytes(z.read(p))
        ps=work/'memory/project_state.kcl'
        s=ps.read_text()
        if 'VERSION "0.9.6"' not in s: raise SystemExit('PROJECT_STATE_VERSION_ANCHOR_MISSING')
        ps.write_text(s.replace('VERSION "0.9.6"','VERSION "0.9.7"',1))
        ch=work/'memory/changelog.kcl'; s=ch.read_text()
        marker='END_CHANGELOG kicom'
        if marker in s and 'RELEASE "0.9.7"' not in s:
            s=s.replace(marker,'RELEASE "0.9.7" date="2026-09-15" change="Resilient autonomy session recovery, stale FreeOTP setup cleanup and semantically idempotent append-only release archival"\n'+marker)
            ch.write_text(s)
        nx=work/'memory/next.kcl'; s=nx.read_text()
        marker='END_NEXT kicom'
        if marker in s and 'archive backfill' not in s.lower():
            s=s.replace(marker,'PRIORITY 1 goal="Verify first-party kicomArchive backfill and primary feed after 0.9.7 installation"\n'+marker)
            nx.write_text(s)
        paths=[]
        for p in work.rglob('*'):
            if p.is_file() and p.relative_to(work).as_posix()!='MANIFEST.sha256': paths.append(p.relative_to(work).as_posix())
        manifest=''.join(f'{sha((work/p).read_bytes())}  {p}\n' for p in sorted(paths))
        (work/'MANIFEST.sha256').write_text(manifest)
        out.unlink(missing_ok=True)
        with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
            for p in sorted(paths+['MANIFEST.sha256']): z.write(work/p,p)
        print('FINAL_PACKAGE_SHA256='+sha(out.read_bytes()))
        # Exact 0.9.6 verifier + yellow classification.
        verify=Path(tempfile.mkdtemp(prefix='k096verify-'))
        try:
            with zipfile.ZipFile(base) as z:z.extractall(verify)
            code=f'''require {str(verify/'lib.php')!r};$x=kicomSelfUpdateZipInspect({str(out)!r});if(empty($x["ok"])){{echo "INSPECT=".($x["code"]??"?")."\\n";exit(31);}}$r=kicomSelfUpdateRiskClass($x);echo "RISK=".($r["class"]??"?")."\\n";if(($r["class"]??"")!=="yellow")exit(32);'''
            q=run(['php','-r',code]);print(q.stdout,end='')
        finally: shutil.rmtree(verify,ignore_errors=True)
        # True fresh-install storage test: package alone must initialize its canonical memory seeds.
        fresh=Path(tempfile.mkdtemp(prefix='k097fresh-')); archive=Path(tempfile.mkdtemp(prefix='k097archive-'))
        try:
            with zipfile.ZipFile(out) as z:z.extractall(fresh)
            (archive/'.htaccess').write_text('Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n# custom local rule\n')
            test=Path('/tmp/test097v2.php')
            test.write_text(f'''<?php
chdir({str(fresh)!r});require {str(fresh/'lib.php')!r};if(KICOM_VERSION!=="0.9.7")exit(41);if(!kicomEnsureStorage())exit(42);
$targets=['kicomarchive'=>['root'=>{str(archive)!r},'health_url'=>'','enabled'=>true,'label'=>'archive','class'=>'staging']];if(!kicomSaveDeployTargets($targets))exit(43);$d=kicomArchiveEnsureDeny();if(empty($d['ok'])){{var_dump($d);exit(44);}}
$setup=kicomTotpSetupBegin('CI');$counter=intdiv(time(),30);$code=kicomTotpCodeForCounter($setup['secret'],$counter,6);if(empty(kicomTotpSetupConfirm($code)['ok']))exit(45);$cfg=kicomAuthJsonRead(kicomTotpFile());$cfg['last_counter']=$counter-1;kicomAuthJsonWrite(kicomTotpFile(),$cfg);$s=kicomAutonomySessionOpen($code);if(empty($s['ok']))exit(46);$a=kicomAutonomySessionConsume($s['session_id'],$s['token']);if(empty($a['ok']))exit(47);$b=kicomAutonomySessionConsume($s['session_id'],$s['token']);if(empty($b['ok'])||empty($b['recovered']))exit(48);$old=kicomAutonomySessionConsume($s['session_id'],$a['next_token']);if(!empty($old['ok']))exit(49);$st=kicomTotpPublicStatus();if(!empty($st['pending']))exit(50);echo "FRESH_RESILIENT_AUTONOMY_OK\\n";
''')
            q=run(['php',str(test)]);print(q.stdout,end='')
        finally: shutil.rmtree(fresh,ignore_errors=True);shutil.rmtree(archive,ignore_errors=True)
    finally: shutil.rmtree(work,ignore_errors=True)

if __name__=='__main__':main()
