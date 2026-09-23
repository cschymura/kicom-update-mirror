#!/usr/bin/env python3
"""Synthetic fail-closed checks; no live system and no release archive produced."""
import hashlib, pathlib, tempfile, zipfile
from verify_native_release import (Invalid,read_parent,read_tree,
                                   verify_candidate,verify_manifest)
import argparse

ap=argparse.ArgumentParser()
ap.add_argument('--parent',required=True,type=pathlib.Path)
args=ap.parse_args()
parent,genome,_=read_parent(args.parent)
checks=0
def good(test,label):
    global checks
    if not test: raise AssertionError(label)
    checks+=1; print('PASS',label)
def denied(fn,code,label):
    try:fn()
    except Invalid as exc:good(str(exc).startswith(code),label);return
    raise AssertionError('ACCEPTED '+label)
good(len(parent)==82 and genome['version']=='0.9.37','exact original archive verified, 82 files')
with tempfile.TemporaryDirectory() as d:
    root=pathlib.Path(d)
    original=root/'original';original.mkdir()
    for name,blob in parent.items():
        file=original/name;file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(blob)
    good(len(read_tree(original))==82,'original extracted without path loss')
    denied(lambda:verify_candidate(parent,genome,read_tree(original)),
           'NON_DESCENDANT_GENOME','unchanged package not advertised as new release')
    (original/'admin.php').write_bytes(parent['admin.php']+b'\n// test change\n')
    denied(lambda:verify_candidate(parent,genome,read_tree(original)),
           'MANIFEST_SHA_MISMATCH','changed admin rejected without updated manifest')
    rows=(original/'MANIFEST.sha256').read_text()
    rows=rows.replace(hashlib.sha256(parent['admin.php']).hexdigest()+'  admin.php',
        hashlib.sha256((original/'admin.php').read_bytes()).hexdigest()+'  admin.php')
    (original/'MANIFEST.sha256').write_text(rows)
    denied(lambda:verify_candidate(parent,genome,read_tree(original)),
           'GENOME_SHA_MISMATCH','updated manifest alone cannot bypass genome')
    (original/'admin.php').write_bytes(parent['admin.php'])
    (original/'MANIFEST.sha256').write_bytes(parent['MANIFEST.sha256'])
    (original/'recovery.php').write_bytes(parent['recovery.php']+b'\n// test recovery drift\n')
    denied(lambda:verify_candidate(parent,genome,read_tree(original)),
           'MANIFEST_SHA_MISMATCH','unmanifested protected recovery mutation rejected')
    (original/'recovery.php').write_bytes(parent['recovery.php'])
    good(verify_manifest(read_tree(original))==verify_manifest(parent),
         'restored original manifest passes SHA verification')
    (original/'modules'/'engram'/'KiComEngramOAuthTransactions.php').unlink()
    denied(lambda:verify_candidate(parent,genome,read_tree(original)),
           'MANIFEST_COVERAGE_MISMATCH','missing original OAuth module rejected')
    with zipfile.ZipFile(root/'test-slip.zip','w') as z:z.writestr('../evil.php','<?php')
    good((root/'evil.php').exists() is False,'test-only unsafe zip never extracted')
print('DEV96_RELEASE_GATES='+str(checks))
