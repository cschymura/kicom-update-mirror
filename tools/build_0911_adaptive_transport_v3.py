#!/usr/bin/env python3
import subprocess,sys,tempfile
from pathlib import Path
src=Path('tools/build_0911_adaptive_transport.py').read_text()
lines=[]
for line in src.splitlines():
    s=line.lstrip()
    if s.startswith("for f,old,new in [('architecture.kcl'"):
        lines.append("  p=r/'memory/architecture.kcl';x=p.read_text();x=h.replace_once(x,'END_ARCHITECTURE kicom','COMPONENT adaptive_transport_profile role=\\\"per-client safe/probe fragment sizing with success learning, backoff feedback and idempotent retry\\\"\\nEND_ARCHITECTURE kicom','seed architecture');p.write_text(x)")
        lines.append("  p=r/'memory/protocol.kcl';x=p.read_text();x=h.replace_once(x,'END_PROTOCOL KCL/1','AUTONOMY adaptive_transaction_buffer=\\\"BEGIN(profile) returns safe/probe -> APPEND learns successful size -> BACKOFF records smaller transport ceiling -> COMMIT rotates once; duplicate last fragment is idempotent\\\"\\nEND_PROTOCOL KCL/1','seed protocol');p.write_text(x)")
    elif s.startswith("ps=r/'memory/project_state.kcl';"):
        lines.append("  ps=r/'memory/project_state.kcl';x=ps.read_text().replace('VERSION \\\"0.9.10\\\"','VERSION \\\"0.9.11\\\"',1);x=h.replace_once(x,'END_PROJECT kicom','MILESTONE \\\"Adaptive transport sizing\\\" status=implemented version=\\\"0.9.11\\\"\\nEND_PROJECT kicom','seed project');ps.write_text(x)")
    elif s.startswith("p=r/'memory/next.kcl';"):
        lines.append("  p=r/'memory/next.kcl';x=p.read_text();x=h.replace_once(x,'END_NEXT kicom','PRIORITY 1 goal=\\\"After adaptive fragment sizing is live, evaluate alternative transport paths and compression strategies with the human\\\"\\nEND_NEXT kicom','seed next');p.write_text(x)")
    else:
        lines.append(line)
p=Path(tempfile.gettempdir())/'build_0911_adaptive_transport_fixed_v3.py';p.write_text('\n'.join(lines)+'\n')
r=subprocess.run([sys.executable,str(p),sys.argv[1],sys.argv[2]])
raise SystemExit(r.returncode)
