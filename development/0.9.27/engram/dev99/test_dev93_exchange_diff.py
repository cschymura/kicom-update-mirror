#!/usr/bin/env python3
"""Detect DEV93 OAuth exchange hunk accidentally treating a NEW if as old context.
Optionally confirm byte-exact ORIGINAL 0.9.37 ZIP contains old-side context.
Never installs, modifies private data, or uploads original source.
"""
import argparse,hashlib,pathlib,re,zipfile
BASE=pathlib.Path(__file__).resolve().parents[1]
PATCH=BASE/'dev93/NATIVE-original-oauth-write-consent.patch'
SHA='0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7'
def audit(original=None):
    patch=PATCH.read_text().splitlines()
    blocks=[];current=None
    for line in patch:
        if line.startswith('@@ '):
            match=re.match(r'^@@ -(\d+),(\d+) \+(\d+),(\d+) @@',line)
            if not match:raise ValueError('INVALID_HUNK_HEADER')
            current={'header':line,'old':int(match.group(2)),
                     'new':int(match.group(4)),'lines':[]}
            blocks.append(current)
        elif current is not None:
            if line and line[0] in ' +-':current['lines'].append(line)
            elif line:raise ValueError('INVALID_PATCH_LINE')
    assert blocks and len(blocks)>=7
    exchange=[b for b in blocks if any('if($requested===self::COMBINED_SCOPE)' in s for s in b['lines'])
       and any("return ['access_token'" in s for s in b['lines'])]
    assert len(exchange)==1,'EXCHANGE_HUNK_NOT_UNIQUE'
    h=exchange[0];combined=[s for s in h['lines'] if 'if($requested===self::COMBINED_SCOPE)' in s]
    assert combined==['+            if($requested===self::COMBINED_SCOPE) {'], \
        'NEW_OAUTH_WRITE_BRANCH_WRONGLY_TAGGED_AS_ORIGINAL_CONTEXT'
    for b in blocks:
        old=sum(s.startswith((' ','-')) for s in b['lines'])
        new=sum(s.startswith((' ','+')) for s in b['lines'])
        assert (old,new)==(b['old'],b['new']), 'MALFORMED_HUNK '+b['header']
    if original is not None:
        assert hashlib.sha256(original.read_bytes()).hexdigest()==SHA,'WRONG_ORIGINAL_ZIP'
        with zipfile.ZipFile(original) as z:
            parent=z.read('modules/engram/KiComEngramOAuthTransactions.php').decode()
        old=''.join((line[1:]+'\n') for line in h['lines'] if line.startswith((' ','-')))
        assert old in parent,'DEV93_OAUTH_EXCHANGE_OLD_CONTEXT_NOT_PRESENT_IN_VERIFIED_PARENT'
        assert 'if($requested===self::COMBINED_SCOPE)' not in parent,'NEW_BRANCH_ALREADY_IN_ORIGINAL'
    return len(blocks)
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--original',type=pathlib.Path)
    args=p.parse_args()
    count=audit(args.original)
    print(f'DEV99_EXACT_OAUTH_HUNKS={count}; PARENT_TESTED={args.original is not None}; INSTALLABLE=false')
