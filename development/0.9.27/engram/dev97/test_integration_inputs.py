#!/usr/bin/env python3
"""Fail-closed syntactic audit of exactly the ONE-tree native patch plan.
Run from repo checkout. Does not need/upload private original ZIP and does
not claim patch content matches original: --fuzz=0 staging must prove that.
"""
import pathlib,re,sys
ROOT=pathlib.Path(__file__).resolve().parents[1]
HERE=pathlib.Path(__file__).resolve().parent
sys.path.insert(0,str(HERE))
import stage_native_one_tree as stage
count=0; failed=[]
for name in stage.PATCHES:
    p=ROOT/name
    if not p.is_file():
        failed.append((name,'MISSING_FILE'));continue
    lines=p.read_text().splitlines()
    i=0;hunks=0
    while i<len(lines):
        match=re.match(r'^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@',lines[i])
        if not match:
            i+=1;continue
        hunks+=1;old=new=0;i+=1
        while i<len(lines) and not lines[i].startswith('@@ -') and not lines[i].startswith('--- a/'):
            line=lines[i]
            if not line: failed.append((name,'UNEXPECTED_BLANK_PATCH_LINE'));break
            if line.startswith((' ','-')):old+=1
            if line.startswith((' ','+')):new+=1
            if not line.startswith((' ','-','+','\\')):
                failed.append((name,'INVALID_DIFF_LINE'))
            i+=1
        declared=(int(match.group(2) or 1),int(match.group(4) or 1))
        if (old,new)!=declared:
            failed.append((name,f'MALFORMED_HUNK {declared} != {(old,new)}'))
    if not lines[0].startswith('--- a/') or not lines[1].startswith('+++ b/') or not hunks:
        failed.append((name,'INVALID_UNIFIED_PATCH'))
    if not any(f[0]==name for f in failed):
        count+=1
        print('PASS zero-malformed-hunks',name,'hunks',hunks)
for name in stage.SOURCES+stage.ASSETS:
    if not (ROOT/name).is_file():failed.append((name,'MISSING_SOURCE'))
    else:count+=1
if failed:
    for f in failed:print('FAIL',*f)
    raise SystemExit(1)
print('DEV97_INTEGRATION_INPUTS_VALID='+str(count))
print('STAGED_ONLY: parent-matching, source flattening, native runtime and updater NOT verified here')
