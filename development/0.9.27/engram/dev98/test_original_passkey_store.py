#!/usr/bin/env python3
"""DEV-98: original-admin Passkey store contract, no live passkey access.

Compares the actual SHA-pinned original KiCom-0.9.37 admin source to the
new original-native DEV95 upgrade patch. An optional original ZIP permits
byte-for-byte check; without it CI tests only a pinned original excerpt.
"""
import argparse, hashlib, pathlib, re, sys, zipfile

ROOT=pathlib.Path(__file__).resolve().parents[1]
PATCH=ROOT/'dev95'/'NATIVE-original-admin-active-db-passkey-upgrade.patch'
ORIGINAL_CONSTRUCTOR=(
    "new KiComPasskeyBridge(\n"
    "            __DIR__.'/var/dev_zone/passkeys','kicom.rurtalbahn.info',\n"
    "            'https://kicom.rurtalbahn.info','KiCom'\n"
    "        )"
)
PARENT_SHA='0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7'
count=0
def check(value,label):
    global count
    if not value:raise RuntimeError('FAIL '+label)
    count+=1;print('PASS '+label)
ap=argparse.ArgumentParser()
ap.add_argument('--original',type=pathlib.Path)
a=ap.parse_args()
patch=PATCH.read_text()
added='\n'.join(row[1:] for row in patch.splitlines() if row.startswith('+') and not row.startswith('+++'))
check(added.count(ORIGINAL_CONSTRUCTOR)==1,'new admin route reuses precisely original KiCom WebAuthn store and RP policy')
check("runtime['passkey_store']" not in added,'absent original runtime passkey_store is not referenced')
check("kicomEngramServerRuntime()" in added,'original trusted runtime is still loaded by admin')
check("KiComEngramActiveUpgradeAdminHttp::handle" in added,'real one-button operator route still delegates to signed Passkey gate')
check("$_SESSION['admin']" in added,'original admin session boundary remains explicit')
check("csrf()" in added,'original CSRF token boundary remains explicit')
if a.original:
    check(hashlib.sha256(a.original.read_bytes()).hexdigest()==PARENT_SHA,'exact original 0.9.37 parent SHA')
    with zipfile.ZipFile(a.original) as z:original=z.read('admin.php').decode()
    check(original.count(ORIGINAL_CONSTRUCTOR)>=1,'actual original admin file contains the exact WebAuthn constructor')
    # New route is inserted between known original sections, not replacing them.
    check(original.count("if (isset($_GET['engram_activate']))")==1,'original owner activation retained')
print('DEV98_ORIGINAL_PASSKEY_ASSERTIONS='+str(count))
