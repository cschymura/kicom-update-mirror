#!/usr/bin/env python3
"""DEV-100: STAGED-ONLY metadata. Does NOT make a deployable release ZIP.
Only run in a private nonproduction directory after the exact SHA-verified
0.9.37 one-tree stage has passed zero-fuzz patches and complete PHP lint.
"""
import argparse,datetime,hashlib,json,pathlib,sys
def sha(data):return hashlib.sha256(data).hexdigest()
def build(root):
    gp=root/'genome/genome.json';mp=root/'genome/modules.json'
    if not gp.is_file() or not mp.is_file():raise ValueError('ORIGINAL_GENOME_MISSING')
    old=json.loads(gp.read_text()); modules=json.loads(mp.read_text())
    if old.get('version')!='0.9.37' or old.get('generation')!=36:
        raise ValueError('NOT_ORIGINAL_0937_STAGING')
    parent=old['id'];old['id']='kicom-0.9.38-g37-mirage-mcp-write-staged'
    old['version']='0.9.38';old['parent']=parent;old['generation']=37
    old['created_at']=datetime.datetime.now(datetime.timezone.utc).isoformat()
    old['mutation_reason']=(
      'NONPRODUCTION staging: one-tree native read/write and OAuth refresh merge; '
      'write feature disabled without separate original operator/passkey approval.')
    excluded={'genome/genome.json'}|{f'memory/{n}.kcl' for n in (
      'architecture','changelog','decisions','next','project_state','protocol')}
    previous={e['path']:e for e in old['components']}
    paths=sorted(p.relative_to(root).as_posix() for p in root.rglob('*')
      if p.is_file() and p.name!='MANIFEST.sha256')
    if not {'admin.php','api.php','genome/modules.json',
        'modules/engram/KiComEngramNativeWriteFactory.php'}.issubset(paths):
        raise ValueError('NATIVE_STAGING_INCOMPLETE')
    for name in paths:
        if name in excluded:continue
        fingerprint=sha((root/name).read_bytes())
        if name in previous:previous[name]['sha256']=fingerprint
        else:
            previous[name]={'path':name,'sha256':fingerprint,
              'role':'human-control-plane-presentation' if name.startswith('assets/')
                 else 'engram-oauth-operator-gated-inactive','auto_heal':True}
    known={m['path'] for m in modules['modules']}
    for name in paths:
        if name.startswith('modules/engram/') and name.endswith('.php') and name not in known:
            modules['modules'].append({'path':name,'enabled':False})
    modules['modules'].sort(key=lambda m:m['path'])
    mp.write_text(json.dumps(modules,ensure_ascii=False,indent=2)+'\n')
    previous['genome/modules.json']['sha256']=sha(mp.read_bytes())
    old['components']=[previous[k] for k in sorted(previous)]
    gp.write_text(json.dumps(old,ensure_ascii=False,indent=2)+'\n')
    (root/'MANIFEST.sha256').write_text(''.join(
        sha((root/k).read_bytes())+'  '+k+'\n' for k in paths))
    return {'status':'STATIC_STAGING_ONLY','installable':False,
        'genome_id':old['id'],'components':len(old['components']),
        'modules':len(modules['modules']),'manifest_entries':len(paths),
        'production_changes':False}
if __name__=='__main__':
    ap=argparse.ArgumentParser();ap.add_argument('--stage',type=pathlib.Path,required=True)
    args=ap.parse_args()
    if not args.stage.is_dir() or args.stage.is_symlink():raise SystemExit('INVALID_STAGE')
    print(json.dumps(build(args.stage),sort_keys=True))
