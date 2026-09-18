#!/usr/bin/env python3
from __future__ import annotations
import argparse, hashlib, json
from pathlib import Path


def sha(p: Path) -> str:
    return hashlib.sha256(p.read_bytes()).hexdigest()


def function_span(text: str, signature: str) -> tuple[int, int, int]:
    start = text.find(signature)
    if start < 0:
        raise SystemExit(f"missing function: {signature}")
    brace = text.find('{', start)
    if brace < 0:
        raise SystemExit("function opening brace missing")
    depth = 0
    quote = None
    escape = False
    i = brace
    while i < len(text):
        ch = text[i]
        if quote is not None:
            if escape:
                escape = False
            elif ch == '\\':
                escape = True
            elif ch == quote:
                quote = None
        else:
            if ch in ("'", '"'):
                quote = ch
            elif ch == '{':
                depth += 1
            elif ch == '}':
                depth -= 1
                if depth == 0:
                    return start, brace, i + 1
        i += 1
    raise SystemExit("unterminated function")


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument('--source', required=True)
    a = ap.parse_args()
    root = Path(a.source)
    libp = root / 'lib.php'
    gp = root / 'genome/genome.json'
    if not libp.is_file() or not gp.is_file():
        raise SystemExit('0.9.26 source tree incomplete')

    lib = libp.read_text(encoding='utf-8')
    sig = 'function kicomReleaseMemorySync0926(): bool '
    start, brace, end = function_span(lib, sig)
    fn = lib[start:end]
    if 'static $syncing=false;' in fn:
        raise SystemExit('reentrancy guard already present')
    body = fn[brace - start + 1:-1]
    guarded = (
        fn[:brace - start + 1]
        + "\n    static $syncing=false;\n"
        + "    if($syncing)return true;\n"
        + "    $syncing=true;\n"
        + "    try {\n"
        + body
        + "\n    } finally { $syncing=false; }\n"
        + "}"
    )
    lib = lib[:start] + guarded + lib[end:]
    libp.write_text(lib, encoding='utf-8')

    g = json.loads(gp.read_text(encoding='utf-8'))
    g['id'] = 'kicom-0.9.26-g25r3'
    g['mutation_reason'] = 'trusted-dev-authority-boundary-sqlite-hardening-memory-sync-reentrancy-r3'
    found = False
    for c in g.get('components', []):
        p = root / c['path']
        if not p.is_file():
            raise SystemExit(f"genome component missing: {c['path']}")
        c['sha256'] = sha(p)
        if c['path'] == 'lib.php':
            found = True
    if not found:
        raise SystemExit('lib.php is not genome-covered')
    gp.write_text(json.dumps(g, indent=4, ensure_ascii=False) + '\n', encoding='utf-8')

    rows = []
    for p in sorted(root.rglob('*')):
        if not p.is_file():
            continue
        rel = p.relative_to(root).as_posix()
        if rel in {'MANIFEST.sha256', 'SOURCE-SNAPSHOT.json'}:
            continue
        rows.append(f"{sha(p)}  {rel}\n")
    (root / 'MANIFEST.sha256').write_text(''.join(rows), encoding='utf-8')
    print(json.dumps({'ok': True, 'genome': g['id'], 'files': len(rows), 'fix': 'release-memory-sync-reentrancy'}, indent=2))


if __name__ == '__main__':
    main()
