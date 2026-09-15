#!/usr/bin/env python3
import hashlib, json, shutil, subprocess, sys, tempfile, zipfile
from pathlib import Path

def sha(b: bytes) -> str: return hashlib.sha256(b).hexdigest()
def run(cmd):
    print('+',' '.join(map(str,cmd)))
    return subprocess.run(cmd,text=True,capture_output=True,check=True)

def main():
    if len(sys.argv)!=4:
        raise SystemExit('usage: finalize_098_fast_transport.py BASE097.zip CANDIDATE098.zip OUT098.zip')
    base=Path(sys.argv[1]); cand=Path(sys.argv[2]); out=Path(sys.argv[3])
    if not cand.is_file(): raise SystemExit('CANDIDATE_MISSING')
    root=Path(tempfile.mkdtemp(prefix='k098final-'))
    try:
        with zipfile.ZipFile(cand) as z:z.extractall(root)
        ps=root/'memory/project_state.kcl'
        if not ps.is_file(): raise SystemExit('PROJECT_STATE_SEED_MISSING')
        s=ps.read_text()
        if 'VERSION "0.9.7"' not in s and 'VERSION "0.9.8"' not in s:
            raise SystemExit('PROJECT_STATE_VERSION_ANCHOR_MISSING')
        ps.write_text(s.replace('VERSION "0.9.7"','VERSION "0.9.8"',1))
        # Keep the packaged seed self-consistent for future fresh installs.
        ch=root/'memory/changelog.kcl'
        if ch.is_file():
            s=ch.read_text(); marker='END_CHANGELOG kicom'
            if marker in s and 'RELEASE "0.9.8"' not in s:
                s=s.replace(marker,'RELEASE "0.9.8" date="2026-09-15" change="Fast transport: larger trusted-source reads, large POST transport, batch/direct patch operations and protected server-side candidate builder using the unchanged verifier"\n'+marker)
                ch.write_text(s)
        nx=root/'memory/next.kcl'
        if nx.is_file():
            s=nx.read_text(); marker='END_NEXT kicom'
            if marker in s and 'transport performance' not in s.lower():
                s=s.replace(marker,'PRIORITY 1 goal="Verify transport performance target: routine memory/source operations in 1-3 requests and server-side candidate build without GitHub dependency"\n'+marker)
                nx.write_text(s)
        paths=[]
        for p in root.rglob('*'):
            if p.is_file() and p.relative_to(root).as_posix()!='MANIFEST.sha256':
                paths.append(p.relative_to(root).as_posix())
        manifest=''.join(f'{sha((root/p).read_bytes())}  {p}\n' for p in sorted(paths))
        (root/'MANIFEST.sha256').write_text(manifest)
        out.unlink(missing_ok=True)
        with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
            for p in sorted(paths+['MANIFEST.sha256']):z.write(root/p,p)
        print('FINAL_PACKAGE_SHA256='+sha(out.read_bytes()))
        # Exact 0.9.7 verifier must accept. Risk may be RED and must not be lowered.
        verify=Path(tempfile.mkdtemp(prefix='k097verify-final-'))
        try:
            with zipfile.ZipFile(base) as z:z.extractall(verify)
            code=f'''require {str(verify/'lib.php')!r};$x=kicomSelfUpdateZipInspect({str(out)!r});if(empty($x["ok"])){{echo "INSPECT=".($x["code"]??"?")."\\n";exit(31);}}$r=kicomSelfUpdateRiskClass($x);echo "RISK=".($r["class"]??"?")."\\n";echo "VERSION=".($x["version"]??"?")."\\n";if(($x["version"]??"")!=="0.9.8"||($x["genome"]["id"]??"")!=="kicom-0.9.8-g9")exit(32);'''
            rr=run(['php','-r',code]); print(rr.stdout,end='')
        finally: shutil.rmtree(verify,ignore_errors=True)
        # True fresh install: canonical memory must seed cleanly.
        fresh=Path(tempfile.mkdtemp(prefix='k098fresh-final-'))
        try:
            with zipfile.ZipFile(out) as z:z.extractall(fresh)
            test=Path('/tmp/test098final.php')
            test.write_text(f'''<?php
chdir({str(fresh)!r});require {str(fresh/'lib.php')!r};
if(KICOM_VERSION!=="0.9.8")exit(41);
if(!kicomEnsureStorage())exit(42);
$r=kicomReadMemoryResource('PROJECT_STATE');if(!is_array($r)||!str_contains($r['raw'],'VERSION "0.9.8"'))exit(43);
$x=kicomSelfUpdateZipInspect({str(out)!r});if(empty($x['ok']))exit(44);
echo "FRESH_INSTALL_OK\\n";
''')
            rr=run(['php',str(test)]); print(rr.stdout,end='')
        finally: shutil.rmtree(fresh,ignore_errors=True)
    finally: shutil.rmtree(root,ignore_errors=True)

if __name__=='__main__':main()
