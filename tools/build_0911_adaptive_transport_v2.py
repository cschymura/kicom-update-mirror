#!/usr/bin/env python3
import subprocess,sys,tempfile
from pathlib import Path
src=Path('tools/build_0911_adaptive_transport.py').read_text()
src=src.replace('COMPONENT transaction_buffer role="inert server-side fragment buffer; begin/append do not rotate main session, commit validates and rotates once"','COMPONENT transaction_buffer role="inert bounded fragment buffer; commit executes existing patch allowlists with one main-session rotation"')
src=src.replace('AUTONOMY transaction_buffer="AUTONOMY_TX_BEGIN -> one or more inert AUTONOMY_TX_APPEND fragments -> AUTONOMY_TX_COMMIT with payload SHA; main session rotates once at commit"','AUTONOMY transaction_buffer="BEGIN nonrotating -> APPEND <=1024-byte inert fragments -> COMMIT sha256 + one main-session rotation; max 64KiB/600s"')
old='''ps=r/'memory/project_state.kcl'; x=ps.read_text();x=ps.read_text().replace('VERSION "0.9.10"','VERSION "0.9.11"',1).replace('FACT transaction_buffer="inert fragments up to 1KiB, aggregate up to 64KiB, 10m TTL, one session rotation at commit"','FACT transaction_buffer="adaptive per-profile fragments with learned safe/probe sizing, backoff feedback and idempotent retry; aggregate up to 64KiB, 10m TTL, one rotation at commit"',1);ps.write_text(x)'''
# Match the actual compact builder line instead of relying on whitespace.
start="ps=r/'memory/project_state.kcl'; x=ps.read_text();x=ps.read_text()"
if start not in src:
    start="ps=r/'memory/project_state.kcl'; x=ps.read_text();x=ps.read_text()"
# Direct exact segment from v1.
seg="ps=r/'memory/project_state.kcl'; x=ps.read_text();x=ps.read_text().replace('VERSION \"0.9.10\"','VERSION \"0.9.11\"',1).replace('FACT transaction_buffer=\"inert fragments up to 1KiB, aggregate up to 64KiB, 10m TTL, one session rotation at commit\"','FACT transaction_buffer=\"adaptive per-profile fragments with learned safe/probe sizing, backoff feedback and idempotent retry; aggregate up to 64KiB, 10m TTL, one rotation at commit\"',1);ps.write_text(x)"
if seg not in src:
    seg="ps=r/'memory/project_state.kcl'; x=ps.read_text();x=ps.read_text().replace('VERSION \"0.9.10\"','VERSION \"0.9.11\"',1).replace('FACT transaction_buffer=\"inert fragments up to 1KiB, aggregate up to 64KiB, 10m TTL, one session rotation at commit\"','FACT transaction_buffer=\"adaptive per-profile fragments with learned safe/probe sizing, backoff feedback and idempotent retry; aggregate up to 64KiB, 10m TTL, one rotation at commit\"',1);ps.write_text(x)"
replacement="ps=r/'memory/project_state.kcl';x=ps.read_text().replace('VERSION \\\"0.9.10\\\"','VERSION \\\"0.9.11\\\"',1);x=h.replace_once(x,'END_PROJECT kicom','MILESTONE \\\"Adaptive transport sizing\\\" status=implemented version=\\\"0.9.11\\\"\\nEND_PROJECT kicom','seed project');ps.write_text(x)"
# Easier robust regex-like literal edits on the builder's source lines.
lines=[]
for line in src.splitlines():
    if line.lstrip().startswith("ps=r/'memory/project_state.kcl';"):
        lines.append("  ps=r/'memory/project_state.kcl';x=ps.read_text().replace('VERSION \\\"0.9.10\\\"','VERSION \\\"0.9.11\\\"',1);x=h.replace_once(x,'END_PROJECT kicom','MILESTONE \\\"Adaptive transport sizing\\\" status=implemented version=\\\"0.9.11\\\"\\nEND_PROJECT kicom','seed project');ps.write_text(x)")
    elif line.lstrip().startswith("p=r/'memory/next.kcl';"):
        lines.append("  p=r/'memory/next.kcl';x=p.read_text();x=h.replace_once(x,'END_NEXT kicom','PRIORITY 1 goal=\\\"After adaptive fragment sizing is live, evaluate alternative transport paths and compression strategies with the human\\\"\\nEND_NEXT kicom','next');p.write_text(x)")
    else: lines.append(line)
src='\n'.join(lines)+'\n'
p=Path(tempfile.gettempdir())/'build_0911_adaptive_transport_fixed.py';p.write_text(src)
r=subprocess.run([sys.executable,str(p),sys.argv[1],sys.argv[2]])
raise SystemExit(r.returncode)
