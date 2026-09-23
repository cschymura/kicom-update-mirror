#!/usr/bin/env python3
"""KiCom DEV-96: fail-closed, read-only exact-parent/native-candidate verifier.

Never installs, edits files or signs/approves a release. Input ZIP/tree must already
exist; private runtime directories and personal data are never examined.
"""
import argparse
import hashlib
import json
import pathlib
import re
import stat
import sys
import zipfile

PARENT_SHA = '0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7'
HASH_ENTRY = re.compile(r'^([a-f0-9]{64})  ([A-Za-z0-9._/-]+)$')
GENOME_EXCLUDED = {'genome/genome.json', *(f'memory/{p}.kcl' for p in
                    ('architecture','changelog','decisions','next','project_state','protocol'))}
CRITICAL_UNCHANGED = ('guardian.php', 'recovery.php', 'modules/dev/PasskeyBridge.php')
MINIMUM_WRITE_MODULES = (
    'KiComEngramWriteGrant.php','KiComEngramMutationSchema.php',
    'KiComEngramRevisionAdapter.php','KiComEngramVerifiedWriteProvenance.php',
    'KiComEngramCanonicalMutationService.php','KiComEngramCanonicalMcpMutationController.php',
    'KiComEngramPrivateWriteConsentReader.php','KiComEngramNativeMcpWriteGate.php',
    'KiComEngramNativeWriteFactory.php','KiComEngramOAuthGrantForm.php',
    'KiComEngramOAuthOptionalParams.php','KiComEngramActiveUpgradeAdminHttp.php',
    'KiComEngramActiveSchemaUpgrade.php',
)

class Invalid(ValueError):
    pass

def digest(data):
    return hashlib.sha256(data).hexdigest()

def clean_path(path):
    p = pathlib.PurePosixPath(path)
    if (path != p.as_posix() or p.is_absolute() or '\\' in path or '\x00' in path
        or any(x in ('', '.', '..') for x in path.split('/')) or path.startswith('-')):
        raise Invalid('UNSAFE_ARCHIVE_PATH')
    return path

def checked_json(raw, name):
    try: return json.loads(raw)
    except Exception as e: raise Invalid('INVALID_JSON_'+name) from e

def verify_manifest(files):
    if 'MANIFEST.sha256' not in files:
        raise Invalid('MISSING_MANIFEST')
    rows = files['MANIFEST.sha256'].decode('ascii').splitlines()
    seen = {}
    for row in rows:
        match = HASH_ENTRY.fullmatch(row)
        if not match: raise Invalid('INVALID_MANIFEST_ROW')
        sha, path = match.groups(); clean_path(path)
        if path in seen or path == 'MANIFEST.sha256': raise Invalid('DUPLICATE_MANIFEST_ENTRY')
        seen[path] = sha
    if set(files) != set(seen) | {'MANIFEST.sha256'}:
        raise Invalid('MANIFEST_COVERAGE_MISMATCH')
    for path, expected in seen.items():
        if digest(files[path]) != expected:
            raise Invalid('MANIFEST_SHA_MISMATCH:'+path)
    return seen

def verify_genome(files, manifest):
    g = checked_json(files['genome/genome.json'], 'GENOME')
    m = checked_json(files['genome/modules.json'], 'MODULES')
    if not isinstance(g, dict) or not isinstance(m, dict): raise Invalid('INVALID_METADATA_TYPE')
    components = g.get('components'); modules = m.get('modules')
    if not isinstance(components,list) or not isinstance(modules,list):
        raise Invalid('INVALID_METADATA_CONTENT')
    known = {}
    for component in components:
        if not isinstance(component,dict): raise Invalid('INVALID_GENOME_COMPONENT')
        path = clean_path(component.get('path',''))
        if path in known or path not in files: raise Invalid('INVALID_GENOME_PATH:'+path)
        if component.get('sha256') != digest(files[path]):
            raise Invalid('GENOME_SHA_MISMATCH:'+path)
        known[path] = component
    if set(manifest) - set(known) != GENOME_EXCLUDED:
        raise Invalid('GENOME_COMPONENT_COVERAGE_MISMATCH')
    declared = set()
    for module in modules:
        if not isinstance(module,dict): raise Invalid('INVALID_MODULE_ENTRY')
        path = clean_path(module.get('path',''))
        if path in declared or path not in known or not path.endswith('.php'):
            raise Invalid('UNTRACKED_MODULE:'+path)
        if type(module.get('enabled')) is not bool: raise Invalid('INVALID_MODULE_ENABLED')
        declared.add(path)
    return g,m,known

def read_parent(path):
    if digest(path.read_bytes()) != PARENT_SHA: raise Invalid('WRONG_PARENT_ARCHIVE_SHA')
    files = {}
    with zipfile.ZipFile(path) as z:
        for info in z.infolist():
            name = clean_path(info.filename)
            if info.is_dir() or name in files: raise Invalid('DIRECTORY_OR_DUPLICATE_ENTRY')
            mode = info.external_attr >> 16
            if stat.S_IFMT(mode) not in (0, stat.S_IFREG): raise Invalid('SYMLINK_OR_SPECIAL')
            if info.file_size > 2_000_000: raise Invalid('OVERSIZED_ENTRY')
            files[name] = z.read(info)
    manifest = verify_manifest(files)
    g,m,_ = verify_genome(files,manifest)
    if g.get('version') != '0.9.37': raise Invalid('UNEXPECTED_PARENT_VERSION')
    return files,g,m

def read_tree(root):
    if not root.is_dir() or root.is_symlink(): raise Invalid('INVALID_CANDIDATE_TREE')
    files = {}
    for p in root.rglob('*'):
        rel = clean_path(p.relative_to(root).as_posix())
        if p.is_symlink(): raise Invalid('UNSAFE_CANDIDATE_PATH')
        if p.is_dir(): continue
        if not p.is_file(): raise Invalid('UNSAFE_CANDIDATE_PATH')
        if p.stat().st_size > 2_000_000: raise Invalid('OVERSIZED_CANDIDATE_ENTRY')
        files[rel] = p.read_bytes()
    return files

def verify_candidate(parent_files, parent_g, files):
    manifest = verify_manifest(files)
    g,m,known = verify_genome(files,manifest)
    if g.get('version') == parent_g.get('version') or g.get('parent') != parent_g.get('id'):
        raise Invalid('NON_DESCENDANT_GENOME')
    if g.get('id') == parent_g.get('id') or g.get('generation',0) <= parent_g.get('generation',0):
        raise Invalid('NO_NEW_GENOME_GENERATION')
    for path in CRITICAL_UNCHANGED:
        if files.get(path) != parent_files.get(path): raise Invalid('CRITICAL_PARENT_DRIFT:'+path)
    for filename in MINIMUM_WRITE_MODULES:
        path = 'modules/engram/'+filename
        if path not in files or path not in known:
            raise Invalid('MISSING_OR_UNTRACKED_WRITE_MODULE:'+path)
    required = {'assets/mirage-active-upgrade-client.js','admin.php','api.php'}
    if not required.issubset(files): raise Invalid('MISSING_NATIVE_ENTRYPOINT')
    admin = files['admin.php'].decode('utf-8')
    api = files['api.php'].decode('utf-8')
    if admin.count("isset($_GET['engram_active_upgrade'])") != 1 or \
        admin.count('admin.php?engram_active_upgrade=1') != 1 or \
        'KiComEngramOAuthAuthorizeHttp::handle(' not in admin or \
        'engram_activate' not in admin or \
        'KiComEngramNativeWriteFactory::build(' not in api or \
        'KiComEngramOAuthMcpHostBridge::handle(' not in api:
        raise Invalid('INCOMPLETE_ORIGINAL_NATIVE_ENTRYPOINT_WIRING')
    return g,m

def run(args):
    parent,p_g,_ = read_parent(args.parent)
    if args.candidate is None:
        return {'ok':True,'mode':'parent-only','parent_sha256':PARENT_SHA,
                'original_files':len(parent),'original_genome':p_g.get('id'),
                'candidate_ready':False,'production_changes':False}
    c_g,_ = verify_candidate(parent,p_g,read_tree(args.candidate))
    return {'ok':True,'mode':'candidate-preflight','parent_sha256':PARENT_SHA,
            'candidate_genome':c_g['id'],'candidate_ready':False,
            'reason':'Static lineage and metadata only; separate integrated PHP/HTTP/SQLite tests and native updater still required',
            'production_changes':False}

if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('--parent',type=pathlib.Path,required=True)
    ap.add_argument('--candidate',type=pathlib.Path)
    a=ap.parse_args()
    try: print(json.dumps(run(a),sort_keys=True));sys.exit(0)
    except (Invalid,ValueError,KeyError,UnicodeError,zipfile.BadZipFile,PermissionError,OSError) as e:
        print(json.dumps({'ok':False,'code':str(e),'candidate_ready':False,'production_changes':False}));sys.exit(2)
