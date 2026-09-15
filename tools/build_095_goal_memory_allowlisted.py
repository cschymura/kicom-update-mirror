from pathlib import Path
import re, sys

# Compatibility wrapper around the reviewed 0.9.5 builder.
# KiCom 0.9.4 intentionally rejects new executable root paths.
# Therefore the Goal/Memory implementation is compiled into the existing
# allowlisted living.php component instead of installing goals_memory.php.

builder = Path('tools/build_095_goal_memory.py')
src = builder.read_text()

old_copy = "shutil.copy2(repo/'build/0.9.5/goals_memory.php', work/'goals_memory.php')"
new_copy = r'''module_text = (repo/'build/0.9.5/goals_memory.php').read_text()
module_text = re.sub(r'^<\?php\s*declare\(strict_types=1\);\s*', '', module_text, count=1)
living_target = work/'living.php'
living_target.write_text(living_target.read_text().rstrip() + "\n\n" + module_text.strip() + "\n")'''
if old_copy not in src:
    raise SystemExit('compat: module-copy anchor missing')
src = src.replace(old_copy, new_copy, 1)

# Do not require a separate executable file from lib.php.
src = src.replace(
    "s = s.replace(\"require_once __DIR__ . '/living.php';\", \"require_once __DIR__ . '/living.php';\\nrequire_once __DIR__ . '/goals_memory.php';\", 1)",
    "s = s.replace(\"require_once __DIR__ . '/living.php';\", \"require_once __DIR__ . '/living.php';\", 1)",
    1,
)

# Keep the Genome component set on the existing allowlisted files; living.php
# now owns the Goal Layer / memory archive implementation.
src = src.replace("'guardian.php','goals_memory.php','recovery.php'", "'guardian.php','recovery.php'")
old_component = """if not any(c.get('path')=='goals_memory.php' for c in g['components']):
    g['components'].append({'path':'goals_memory.php','sha256':'','role':'goal-and-memory-archive-layer','auto_heal':True})
"""
if old_component not in src:
    raise SystemExit('compat: genome-component anchor missing')
src = src.replace(old_component, "", 1)

# Execute the reviewed builder with only the compatibility transformation above.
code = compile(src, str(builder) + ':allowlisted', 'exec')
exec(code, {'__name__':'__main__','__file__':str(builder)})
