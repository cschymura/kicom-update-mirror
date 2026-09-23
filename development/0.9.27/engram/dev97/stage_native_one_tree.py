#!/usr/bin/env python3
"""DEV-97 one-tree staging ONLY. Never modifies live KiCom or emits install ZIP.
Input: exact 0.9.37 parent ZIP, local checkout of the selected GitHub branch.
Fails on missing sources, unclean patch hunks, PHP syntax, or leftover DEV imports.
"""
import argparse, hashlib, json, pathlib, shutil, stat, subprocess, sys, tempfile, zipfile
PARENT_SHA='0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7'
BASE='development/0.9.27/engram'
PATCHES=[
 'dev100/NATIVE-exact-original-mcp-optional-write-protocol.patch',
 'dev93/NATIVE-original-oauth-write-consent.patch',
 'dev93/NATIVE-first-party-passkey-ui-and-oauth-http-write-consent.patch',
 'dev94/NATIVE-after-DEV93-original-oauth-both-scope-atomic-refresh.patch',
 'dev94/NATIVE-original-0937-oauth-optional-locale-after-dev93.patch',
 'dev100/NATIVE-exact-original-oauth-http-refresh-discovery.patch',
 'dev91/native-0937-separate-combined-scope-verify.patch',
 'dev97/NATIVE-exact-original-host-gated-write-dispatch.patch',
 'dev100/NATIVE-exact-original-api-first-party-write-factory.patch',
 'dev95/NATIVE-original-admin-active-db-passkey-upgrade.patch',
 'dev95/NATIVE-original-admin-menu-link.patch',
]
SOURCES=[
 'dev72/KiComEngramWriteGrant.php',
 'dev75/KiComEngramRevisionAdapter.php',
 'dev76/KiComEngramCanonicalMutationService.php',
 'dev76/KiComEngramCanonicalMcpMutationController.php',
 'dev87/KiComEngramMutationSchema.php',
 'dev88/KiComEngramVerifiedWriteProvenance.php',
 'dev89/KiComEngramPrivateWriteConsentReader.php',
 'dev91/KiComEngramNativeMcpWriteGate.php',
 'dev92/KiComEngramNativeWriteFactory.php',
 'dev94/KiComEngramOAuthGrantForm.php',
 'dev94/KiComEngramOAuthOptionalParams.php',
 'dev95/KiComEngramActiveSchemaUpgrade.php',
 'dev95/KiComEngramActiveUpgradeAdminHttp.php',
]
ASSETS=['dev95/mirage-active-upgrade-client.js']
class Blocked(Exception): pass
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def manifest(root):
    out={}
    for row in (root/'MANIFEST.sha256').read_text().splitlines():
        digest,sep,name=row.partition('  ')
        if not sep or len(digest)!=64 or name in out:raise Blocked('ORIGINAL_MANIFEST_INVALID')
        out[name]=digest
    for name,digest in out.items():
        path=root/name
        if not path.is_file() or sha(path)!=digest:raise Blocked('ORIGINAL_MANIFEST_MISMATCH '+name)
    return out
def safe_extract(z,path):
    names=set()
    for m in z.infolist():
        n=pathlib.PurePosixPath(m.filename)
        if m.filename!=n.as_posix() or n.is_absolute() or '..' in n.parts or m.is_dir() or m.filename in names:
            raise Blocked('UNSAFE_PARENT_ZIP')
        if stat.S_IFMT(m.external_attr>>16) not in (0,stat.S_IFREG):
            raise Blocked('UNSAFE_PARENT_ENTRY')
        names.add(m.filename)
    z.extractall(path)
def stage(parent,repo,output):
    if sha(parent)!=PARENT_SHA:raise Blocked('WRONG_0937_PARENT_SHA')
    if output.exists():raise Blocked('DESTINATION_ALREADY_EXISTS')
    # Resolve every source BEFORE creating any staged output.
    for name in PATCHES+SOURCES+ASSETS:
        file=repo/BASE/name
        if not file.is_file() or file.is_symlink():raise Blocked('MISSING_GITHUB_SOURCE '+name)
    with tempfile.TemporaryDirectory(prefix='kicom-dev97-') as temp:
        root=pathlib.Path(temp)/'native'
        root.mkdir()
        with zipfile.ZipFile(parent) as z:safe_extract(z,root)
        old=manifest(root)
        result=[]
        for name in PATCHES:
            patch=repo/BASE/name
            run=subprocess.run(['patch','--batch','--fuzz=0','--forward','--no-backup-if-mismatch',
                '-p1','-d',str(root),'--input',str(patch)],capture_output=True,text=True)
            if run.returncode!=0:
                raise Blocked('PATCH_NOT_EXACT '+name+' :: '+run.stdout[-550:]+run.stderr[-300:])
            result.append({'source':name,'status':'applied_zero_fuzz'})
        for name in SOURCES:
            content=(repo/BASE/name).read_text()
            # Only rewrite exact development-relative module imports. Missing
            # referenced classes fail syntax/integration checks downstream.
            import re
            content=re.sub(r"__DIR__\s*\.\s*'/\.\./dev\d+/(KiCom[A-Za-z0-9]+\.php)'",
                           r"__DIR__.'/\1'",content)
            if "../dev" in content:raise Blocked('UNRESOLVED_DEV_MODULE_IMPORT '+name)
            dest=root/'modules/engram'/pathlib.Path(name).name
            if dest.exists():raise Blocked('NATIVE_MODULE_COLLISION '+name)
            dest.write_text(content)
        for name in ASSETS:
            target=root/'assets'/pathlib.Path(name).name
            if target.exists():raise Blocked('NATIVE_ASSET_COLLISION '+name)
            shutil.copyfile(repo/BASE/name,target)
        # A staged tree MUST preserve 0.9.37 recovery, guardian, passkey kernel.
        for critical in ['guardian.php','recovery.php','modules/dev/PasskeyBridge.php']:
            if critical not in old or sha(root/critical)!=old[critical]:
                raise Blocked('CRITICAL_PARENT_DRIFT '+critical)
        linted=0
        for php in root.rglob('*.php'):
            run=subprocess.run(['php','-l',str(php)],capture_output=True,text=True)
            if run.returncode:raise Blocked('PHP_LINT_FAILURE '+php.relative_to(root).as_posix()+' '+run.stdout[-300:])
            linted+=1
        # Never pretend stale original MANIFEST/genome represent this tree.
        if all(sha(root/name)==digest for name,digest in old.items()):
            raise Blocked('NO_SOURCE_CHANGES')
        shutil.copytree(root,output)
    return {'status':'STAGED_ONLY','installable':False,'parent_sha256':PARENT_SHA,
            'zero_fuzz_patches':result,'added_modules':len(SOURCES),'php_lint_ok':linted,
            'source_tree':str(output),'release_blocker':'MANIFEST+Genome+original updater/rollback and original native HTTP PDO/WebAuthn E2E still required',
            'production_changes':False}
if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--parent',type=pathlib.Path,required=True)
    p.add_argument('--repo',type=pathlib.Path,required=True)
    p.add_argument('--output',type=pathlib.Path,required=True)
    a=p.parse_args()
    try:print(json.dumps(stage(a.parent,a.repo,a.output),ensure_ascii=False))
    except (Blocked,OSError,subprocess.SubprocessError,zipfile.BadZipFile) as err:
        print(json.dumps({'status':'BLOCKED','reason':str(err),'installable':False,'production_changes':False}))
        sys.exit(2)
