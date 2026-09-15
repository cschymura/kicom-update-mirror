from pathlib import Path
import hashlib, json, re, shutil, sys, zipfile

if len(sys.argv) != 3:
    raise SystemExit('usage: build_095_goal_memory.py <base-zip> <output-zip>')
base_zip = Path(sys.argv[1])
out_zip = Path(sys.argv[2])
repo = Path.cwd()
work = Path('/tmp/kicom095-goal-memory')
if work.exists(): shutil.rmtree(work)
work.mkdir(parents=True)
with zipfile.ZipFile(base_zip) as zf:
    zf.extractall(work)

def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

def replace_once(path: Path, old: str, new: str, label: str):
    s = path.read_text()
    if old not in s:
        raise SystemExit(f'{label}: anchor not found')
    path.write_text(s.replace(old, new, 1))

# New protected Goal Layer / Memory Archive module.
shutil.copy2(repo/'build/0.9.5/goals_memory.php', work/'goals_memory.php')

# Core library: version, bounded archive payload, new module, scoped bridge intent.
lib = work/'lib.php'
s = lib.read_text()
s = s.replace("const KICOM_VERSION = '0.9.4';", "const KICOM_VERSION = '0.9.5';", 1)
s = s.replace("const KICOM_MAX_POST_BYTES = 32768;", "const KICOM_MAX_POST_BYTES = 524288;\nconst KICOM_MAX_MEMORY_ARCHIVE_SNAPSHOT_BYTES = 262144;", 1)
s = s.replace("require_once __DIR__ . '/living.php';", "require_once __DIR__ . '/living.php';\nrequire_once __DIR__ . '/goals_memory.php';", 1)
old_allowed = "$allowed=['STAGE_PROPOSE','WORKSPACE_PROPOSE','WORKSPACE_ROLLBACK','MEMORY_PROPOSE','MEMORY_PATCH_PROPOSE','MEMORY_ROLLBACK','DEPLOY_PROPOSE','DEPLOY_ROLLBACK','DEPLOY_PACKAGE_PROPOSE','DEPLOY_PACKAGE_ROLLBACK'];"
new_allowed = "$allowed=['STAGE_PROPOSE','WORKSPACE_PROPOSE','WORKSPACE_ROLLBACK','MEMORY_PROPOSE','MEMORY_PATCH_PROPOSE','MEMORY_ROLLBACK','MEMORY_ARCHIVE_BEGIN','DEPLOY_PROPOSE','DEPLOY_ROLLBACK','DEPLOY_PACKAGE_PROPOSE','DEPLOY_PACKAGE_ROLLBACK'];"
if old_allowed not in s: raise SystemExit('lib intent allowlist anchor not found')
s = s.replace(old_allowed, new_allowed, 1)
lib.write_text(s)

# Living architecture: Goal Layer becomes a first-class invariant; never trim old goals.
living = work/'living.php'
s = living.read_text()
s = s.replace('/* KiCom Living Architecture 0.9.1 */','/* KiCom Living Architecture 0.9.5 */',1)
s = s.replace("'unknown-code-quarantine-before-use'];", "'unknown-code-quarantine-before-use','goal-layer-no-permission-grants','memory-archive-no-hard-delete'];", 1)
s = s.replace("foreach(['lib.php','index.php','api.php','admin.php','living.php','recovery.php','guardian.php','.htaccess','robots.txt','genome/.htaccess','memory/.htaccess','stage/.htaccess','var/.htaccess'] as $p)", "foreach(['lib.php','index.php','api.php','admin.php','living.php','goals_memory.php','recovery.php','guardian.php','.htaccess','robots.txt','genome/.htaccess','memory/.htaccess','stage/.htaccess','var/.htaccess'] as $p)", 1)
s = s.replace("    if(count($goals)>80)$goals=array_slice($goals,-80);$store=['schema'=>1,'updated_at'=>gmdate('c'),'goals'=>$goals];kicomEvolutionAtomicJson(kicomEvolutionGoalsFile(),$store);\n    if($opened>0)$state['epoch_stats']['goals_opened']=(int)($state['epoch_stats']['goals_opened']??0)+$opened;return $store;", "    $store=['schema'=>1,'updated_at'=>gmdate('c'),'policy'=>'archive-never-hard-delete','goals'=>$goals];kicomEvolutionAtomicJson(kicomEvolutionGoalsFile(),$store);\n    if($opened>0)$state['epoch_stats']['goals_opened']=(int)($state['epoch_stats']['goals_opened']??0)+$opened;\n    if(function_exists('kicomGoalSyncSystemExperience'))kicomGoalSyncSystemExperience();\n    return $store;", 1)
living.write_text(s)

# KCL read surface plus bounded, transient chunk transport for private archive proposals.
index = work/'index.php'
s = index.read_text()
s = s.replace("'LINK living_insights=\"?q=LIVING_INSIGHTS\"','LINK genome_status=\"?q=GENOME_STATUS\"'", "'LINK living_insights=\"?q=LIVING_INSIGHTS\"','LINK goals=\"?q=GOALS\"','LINK memory_archive_status=\"?q=MEMORY_ARCHIVE_STATUS\"','LINK genome_status=\"?q=GENOME_STATUS\"'", 1)
bridge_anchor = "    elseif(in_array($operation,['DEPLOY_PROPOSE','DEPLOY_PACKAGE_PROPOSE'],true)){$a=kicomDeployTargetAlias($target);"
bridge_new = "    elseif($operation==='MEMORY_ARCHIVE_BEGIN'){if($target!=='chatgpt_memory')out([KCL_PROTOCOL,'ERROR bridge_intent','FACT request_id='.kclString($rid),'FACT code=\"INVALID_TARGET\"','END'],400);}\n    elseif(in_array($operation,['DEPLOY_PROPOSE','DEPLOY_PACKAGE_PROPOSE'],true)){$a=kicomDeployTargetAlias($target);"
if bridge_anchor not in s: raise SystemExit('index bridge anchor not found')
s = s.replace(bridge_anchor, bridge_new, 1)
insert_anchor = "case 'EVOLUTION_CANDIDATES':"
insert_cases = r'''case 'GOALS':
    $gs=kicomGoals(true);$rows=$gs['goals']??[];$lines=[KCL_PROTOCOL,'OK goals','FACT request_id='.kclString($rid),'FACT policy="archive-never-hard-delete"','FACT count='.count($rows)];
    foreach(array_slice(array_reverse($rows),0,100) as $i=>$g)if(is_array($g))$lines[]='GOAL #'.($i+1).' id='.kclString((string)($g['id']??'')).' source='.kclString((string)($g['source']??'')).' status='.kclString((string)($g['status']??'open')).' priority='.kclString((string)($g['priority']??'medium')).' title='.kclString((string)($g['title']??''),180).' description='.kclString((string)($g['description']??''),500).' success_criteria='.kclString((string)($g['success_criteria']??''),500);
    $lines[]='RULE goals-never-grant-runtime-permissions';$lines[]='END';out($lines);
case 'MEMORY_ARCHIVE_STATUS':
    $st=kicomMemoryArchiveStatus();$latest=$st['latest']??null;$lines=[KCL_PROTOCOL,'OK memory_archive_status','FACT request_id='.kclString($rid),'FACT policy='.kclString((string)$st['policy']),'FACT snapshots='.(int)$st['count'],'FACT bytes='.(int)$st['bytes']];if(is_array($latest)){$lines[]='FACT latest_id='.kclString((string)($latest['id']??''));$lines[]='FACT latest_at='.kclString((string)($latest['created_at']??''));$lines[]='FACT latest_sha256='.kclString((string)($latest['payload_sha256']??''));}$lines[]='RULE snapshot-content-not-public-via-kcl';$lines[]='END';out($lines);
case 'MEMORY_ARCHIVE_LIST':
    $rows=kicomMemoryArchiveList(100);$lines=[KCL_PROTOCOL,'OK memory_archive_list','FACT request_id='.kclString($rid),'FACT count='.count($rows),'FACT policy="archive-never-hard-delete"'];foreach($rows as $i=>$m)$lines[]='SNAPSHOT #'.($i+1).' id='.kclString((string)($m['id']??'')).' created_at='.kclString((string)($m['created_at']??'')).' source='.kclString((string)($m['source']??'')).' bytes='.(int)($m['payload_bytes']??0).' sha256='.kclString((string)($m['payload_sha256']??'')).' goals='.count($m['goal_ids']??[]);$lines[]='END';out($lines);
case 'MEMORY_ARCHIVE_BEGIN':
    requireGetMutationIntent('MEMORY_ARCHIVE_BEGIN','chatgpt_memory',$rid);$r=kicomMemoryArchiveUploadBegin((string)($_GET['label']??'ChatGPT memory snapshot'));if(!$r['ok'])out([KCL_PROTOCOL,'ERROR memory_archive_begin','FACT request_id='.kclString($rid),'FACT code='.kclString((string)$r['code']),'END'],429);out([KCL_PROTOCOL,'READY memory_archive_upload','FACT request_id='.kclString($rid),'FACT upload_id='.kclString((string)$r['upload_id']),'FACT upload_token='.kclString((string)$r['token']),'FACT expires_in='.(int)$r['expires_in'],'FACT max_chunk_bytes='.(int)$r['max_chunk_bytes'],'FACT max_bytes='.(int)$r['max_bytes'],'RULE transient-upload-only','RULE final-archive-requires-human-approval','END']);
case 'MEMORY_ARCHIVE_CHUNK':
    $r=kicomMemoryArchiveUploadChunk(strtolower((string)($_GET['upload_id']??'')),strtolower((string)($_GET['token']??'')),(int)($_GET['seq']??-1),(string)($_GET['data']??''));if(!$r['ok'])out([KCL_PROTOCOL,'ERROR memory_archive_chunk','FACT request_id='.kclString($rid),'FACT code='.kclString((string)$r['code']),'END'],422);out([KCL_PROTOCOL,'OK memory_archive_chunk','FACT request_id='.kclString($rid),'FACT next_seq='.(int)$r['next_seq'],'FACT bytes='.(int)$r['bytes'],'END']);
case 'MEMORY_ARCHIVE_FINISH':
    $r=kicomMemoryArchiveUploadFinish(strtolower((string)($_GET['upload_id']??'')),strtolower((string)($_GET['token']??'')));if(!$r['ok'])out([KCL_PROTOCOL,'ERROR memory_archive_finish','FACT request_id='.kclString($rid),'FACT code='.kclString((string)$r['code']),'END'],422);out([KCL_PROTOCOL,'READY memory_archive_proposal','FACT request_id='.kclString($rid),'FACT proposal_id='.kclString((string)$r['proposal_id']),'FACT bytes='.(int)$r['bytes'],'FACT sha256='.kclString((string)$r['sha256']),'REQUIRES human_approval=true','LINK admin="admin.php"','END'],202);
'''
if insert_anchor not in s: raise SystemExit('index goals anchor not found')
s = s.replace(insert_anchor, insert_cases + insert_anchor, 1)
s = s.replace("'LINK capabilities=\"?q=DESCRIBE\"','SUMMARY \"KiCom 0.9.2 adds a human-focused control plane plus redundant pull and authenticated push update channels; both converge on one verifier and risk policy.\"','NEXT_ACTION \"Configure or verify the pull feed and push channel, schedule UPDATE_AGENT for idle-time checks, and let green updates install automatically while yellow/red changes require proportionate human attention.\"'", "'LINK capabilities=\"?q=DESCRIBE\"','LINK goals=\"?q=GOALS\"','LINK memory_archive=\"?q=MEMORY_ARCHIVE_STATUS\"','SUMMARY \"KiCom 0.9.5 adds a persistent Goal Layer and append-only ChatGPT memory archive while preserving the Living Architecture trust boundaries.\"','NEXT_ACTION \"Use Experience and approved ChatGPT-memory snapshots as goal sources; keep goals permissionless, archive memory instead of deleting it, and derive future capability candidates from evidence.\"'", 1)
s = s.replace("'CAPABILITY EVOLUTION candidate_upload_static_fitness_human_gated_promotion','RULE deploy-targets-human-configured-and-allowlisted'", "'CAPABILITY EVOLUTION candidate_upload_static_fitness_human_gated_promotion','CAPABILITY GOAL_LAYER sources:{human,chatgpt_memory,system_experience} archive_supersede no_permission_grants','CAPABILITY MEMORY_ARCHIVE append_only snapshot_metadata chunked_get_ingest human_gated_final_write','RULE deploy-targets-human-configured-and-allowlisted'", 1)
s = s.replace("'RULE memory-cannot-change-runtime-policy','RULE optimistic-concurrency-for-workspace-writes'", "'RULE memory-cannot-change-runtime-policy','RULE goal-layer-does-not-grant-runtime-permissions','RULE goal-history-is-archived-not-hard-deleted','RULE memory-archive-append-only-no-hard-delete','RULE memory-archive-content-not-public-via-kcl','RULE memory-archive-ingest-transient-final-write-human-approved','RULE optimistic-concurrency-for-workspace-writes'", 1)
index.write_text(s)

# POST machine client path for goal and memory proposals; existing human approval remains mandatory.
api = work/'api.php'
s = api.read_text()
api_insert = r'''case 'GOAL_PROPOSE':
    $content=apiContent($data,true);if($content===null||strlen($content)>16384)apiOut([KCL_PROTOCOL,'ERROR goal_propose','FACT request_id='.apiKclString($rid),'FACT code="INVALID_CONTENT"','END'],400);$g=json_decode($content,true);if(!is_array($g)||trim((string)($g['title']??''))===''||trim((string)($g['description']??''))==='')apiOut([KCL_PROTOCOL,'ERROR goal_propose','FACT request_id='.apiKclString($rid),'FACT code="GOAL_JSON_INVALID"','END'],422);$pr=kicomCreateProposal(['kind'=>'goal_proposal','resource'=>'GOAL_LAYER','bytes'=>strlen($content),'sha256'=>hash('sha256',$content),'content_b64'=>base64_encode($content),'source'=>'chatgpt_memory','validation'=>['status'=>'ok','message'=>'GOAL_JSON_VALID'],'transport'=>'POST']);if(!$pr['ok'])apiOut([KCL_PROTOCOL,'ERROR goal_propose','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString((string)$pr['code']),'END'],500);apiOut([KCL_PROTOCOL,'READY goal_proposal','FACT request_id='.apiKclString($rid),'FACT proposal_id='.apiKclString((string)$pr['id']),'REQUIRES human_approval=true','LINK admin="admin.php"','END'],202);

case 'CHATGPT_MEMORY_SNAPSHOT_PROPOSE':
    $content=apiContent($data,true);if($content===null||strlen($content)>KICOM_MAX_MEMORY_ARCHIVE_SNAPSHOT_BYTES)apiOut([KCL_PROTOCOL,'ERROR memory_archive_propose','FACT request_id='.apiKclString($rid),'FACT code="SNAPSHOT_SIZE_INVALID"','END'],413);if(!is_array(json_decode($content,true)))apiOut([KCL_PROTOCOL,'ERROR memory_archive_propose','FACT request_id='.apiKclString($rid),'FACT code="SNAPSHOT_JSON_INVALID"','END'],422);$label=kicomGoalText((string)($data['label']??'ChatGPT memory snapshot'),160);$pr=kicomCreateProposal(['kind'=>'memory_archive_snapshot','resource'=>'CHATGPT_MEMORY_ARCHIVE','bytes'=>strlen($content),'sha256'=>hash('sha256',$content),'content_b64'=>base64_encode($content),'label'=>$label,'source'=>'chatgpt_memory','validation'=>['status'=>'ok','message'=>'SNAPSHOT_JSON_VALID'],'transport'=>'POST']);if(!$pr['ok'])apiOut([KCL_PROTOCOL,'ERROR memory_archive_propose','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString((string)$pr['code']),'END'],500);apiOut([KCL_PROTOCOL,'READY memory_archive_proposal','FACT request_id='.apiKclString($rid),'FACT proposal_id='.apiKclString((string)$pr['id']),'FACT bytes='.strlen($content),'FACT sha256='.apiKclString(hash('sha256',$content)),'REQUIRES human_approval=true','LINK admin="admin.php"','END'],202);

'''
if "case 'WORKSPACE_PROPOSE':" not in s: raise SystemExit('api switch anchor not found')
s = s.replace("case 'WORKSPACE_PROPOSE':", api_insert + "case 'WORKSPACE_PROPOSE':", 1)
api.write_text(s)

# Human Control Plane: approve archive/goal proposals; human goals; archive instead of delete.
admin = work/'admin.php'
s = admin.read_text()
old = "                    elseif(str_starts_with($kind,'memory_')){\n                        $resource=strtoupper((string)($p['resource']??''));$base=(string)($p['base_sha256']??'');$v=kicomValidateMemoryContent($resource,$content);"
new = "                    elseif($kind==='memory_archive_snapshot'){\n                        $result=kicomMemoryArchiveSnapshotStore($content,'chatgpt_memory',(string)($p['label']??'ChatGPT memory snapshot'));\n                        if(!$result['ok'])$err='Memory-Archiv konnte nicht geschrieben werden: '.($result['code']??'UNKNOWN');\n                        else{@unlink($file);$msg='Freigegeben → ChatGPT-Memory archiviert: '.($result['snapshot']['id']??'').' · '.count($result['goals_ingested']??[]).' Ziel(e) übernommen.';}\n                    } elseif($kind==='goal_proposal'){\n                        $g=json_decode($content,true);if(!is_array($g))$err='Goal-Vorschlag ist ungültig.';else{$result=kicomGoalUpsert('chatgpt_memory',(string)($g['title']??''),(string)($g['description']??''),(string)($g['priority']??'medium'),(string)($g['evidence']??''),(string)($g['success_criteria']??''),(string)($g['source_ref']??''),true);if(!$result['ok'])$err='Goal-Vorschlag konnte nicht übernommen werden: '.($result['code']??'UNKNOWN');else{@unlink($file);$msg='Freigegeben → Ziel '.($result['goal']['id']??'').' aus ChatGPT-Memory.';}}\n                    } elseif(str_starts_with($kind,'memory_')){\n                        $resource=strtoupper((string)($p['resource']??''));$base=(string)($p['base_sha256']??'');$v=kicomValidateMemoryContent($resource,$content);"
if old not in s: raise SystemExit('admin approval anchor not found')
s = s.replace(old,new,1)
action_anchor = "        } elseif($action==='toggle_evolution_autonomy'){"
action_new = r'''        } elseif($action==='create_goal'){
            $r=kicomGoalUpsert('human',(string)($_POST['goal_title']??''),(string)($_POST['goal_description']??''),(string)($_POST['goal_priority']??'medium'),(string)($_POST['goal_evidence']??''),(string)($_POST['goal_success']??''),'admin:'.gmdate('YmdHis'),true);if(!$r['ok'])$err='Ziel konnte nicht angelegt werden: '.($r['code']??'UNKNOWN');else $msg='Menschliches Ziel angelegt: '.($r['goal']['id']??'');
        } elseif($action==='archive_goal'){
            $r=kicomGoalArchive((string)($_POST['goal_id']??''),(string)($_POST['goal_reason']??'Manuell archiviert'));if(!$r['ok'])$err='Ziel konnte nicht archiviert werden: '.($r['code']??'UNKNOWN');else $msg='Ziel archiviert – Historie bleibt vollständig erhalten.';
        } elseif($action==='toggle_evolution_autonomy'){'''
if action_anchor not in s: raise SystemExit('admin action anchor not found')
s = s.replace(action_anchor,action_new,1)
ui_anchor = '<h3>Autonome Evolution</h3>'
ui = r'''<h3>Goal Layer &amp; Gedächtnisarchiv</h3>
<?php $goalStore=kicomGoals(true);$goalRows=array_reverse($goalStore['goals']??[]);$openGoalRows=array_values(array_filter($goalRows,fn($g)=>is_array($g)&&($g['status']??'open')==='open'));$ma=kicomMemoryArchiveStatus();$ml=kicomMemoryArchiveList(5);?>
<p class="muted">Ziele können aus <strong>Mensch</strong>, <strong>ChatGPT-Memory</strong> oder <strong>Systemerfahrung</strong> stammen. Ein Ziel erweitert niemals Berechtigungen. Historie wird nicht hart gelöscht: erledigte oder überholte Ziele werden archiviert bzw. superseded.</p>
<div class="grid"><div class="card"><strong>Offene Ziele</strong><span class="big-state"><?=h((string)count($openGoalRows))?></span><p class="muted">Gesamt <?=h((string)count($goalRows))?> · Quellen bleiben nachvollziehbar.</p></div><div class="card"><strong>Memory-Snapshots</strong><span class="big-state"><?=h((string)$ma['count'])?></span><p class="muted"><?=h((string)$ma['bytes'])?> Bytes archiviert · Policy: archive-never-hard-delete.</p></div></div>
<details><summary>Menschliches Ziel anlegen</summary><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="create_goal"><label>Titel<input name="goal_title" maxlength="180" required></label><label>Beschreibung<textarea name="goal_description" maxlength="1200" required></textarea></label><label>Priorität<select name="goal_priority"><option>low</option><option selected>medium</option><option>high</option><option>critical</option></select></label><label>Evidenz<textarea name="goal_evidence" maxlength="1200"></textarea></label><label>Erfolgskriterium<textarea name="goal_success" maxlength="1200"></textarea></label><button class="approve">Ziel anlegen</button></form></details>
<?php if($openGoalRows):?><details open><summary>Offene Ziele</summary><div class="grid"><?php foreach(array_slice($openGoalRows,0,12) as $g):?><div class="card"><strong><?=h((string)($g['title']??''))?></strong> <span class="tag"><?=h((string)($g['priority']??''))?></span><p class="muted">Quelle <?=h((string)($g['source']??''))?> · ID <?=h((string)($g['id']??''))?><br><?=h((string)($g['description']??''))?></p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="goal_id" value="<?=h((string)($g['id']??''))?>"><input type="hidden" name="goal_reason" value="Manuell archiviert"><button name="action" value="archive_goal">Archivieren</button></form></div><?php endforeach;?></div></details><?php endif;?>
<?php if($ml):?><details><summary>Letzte Memory-Snapshots</summary><?php foreach($ml as $m):?><div class="card"><strong><?=h((string)($m['id']??''))?></strong><p class="muted"><?=h((string)($m['created_at']??''))?> · <?=h((string)($m['payload_bytes']??0))?> Bytes · SHA-256 <?=h((string)($m['payload_sha256']??''))?><br>Inhalt liegt ausschließlich im geschützten var-Bereich und wird nicht über das öffentliche KCL ausgegeben.</p></div><?php endforeach;?></details><?php endif;?>
<h3>Autonome Evolution</h3>'''
if ui_anchor not in s: raise SystemExit('admin UI anchor not found')
s = s.replace(ui_anchor,ui,1)
admin.write_text(s)

# README and fresh-install canonical memory seeds.
readme=work/'README.md'
r=readme.read_text();lines=r.splitlines();
if lines: lines[0]='# KiCom 0.9.5 – Goal Layer & Persistent Memory Archive'
r='\n'.join(lines).rstrip()+'''\n\n## 0.9.5 – Goal Layer & Persistent Memory Archive\n- Persistent Goal Layer with sources human, chatgpt_memory and system_experience.\n- Goals can guide evolution but can never grant runtime permissions.\n- Append-only ChatGPT-memory snapshots under protected var storage; no hard-delete interface.\n- Memory snapshots may carry explicit project goal hypotheses which are ingested only after human approval.\n- Public KCL exposes archive metadata and hashes, never snapshot payloads.\n- Bounded chunked GET ingest exists for LLM clients that cannot POST; only transient chunks are written before a human-gated final archive proposal.\n- Every release is now paired with a sanitized source snapshot in the update mirror for reproducible future development.\n- Fresh-install memory seeds are version-consistent again.\n'''
readme.write_text(r)

project_state='''PROJECT kicom
VERSION "0.9.5"
STATE active
FACT canonical_name="KiCom"
FACT purpose="Persistent self-extending capability runtime and controlled HTTPS development gateway for RTB web systems"
FACT protocol="KCL/1"
FACT transport="HTTPS"
FACT mode="living-architecture-with-human-gated-evolution"
FACT production_write="allowlisted-human-approved"
FACT direct_shell=false
FACT arbitrary_remote_fetch=false
FACT canonical_memory_via="?q=BOOTSTRAP"
MILESTONE "Communication, staging and canonical memory" status=passed versions="0.1-0.5.1"
MILESTONE "Allowlisted deployment, production hardening and transactional package deployment" status=passed versions="0.6.0-0.7.0"
MILESTONE "Privacy/search-engine hardening" status=passed version="0.7.1"
MILESTONE "Human-gated self-update controller" status=passed version="0.8.0"
MILESTONE "Genome, recovery kernel and Living Architecture" status=passed versions="0.9.0-0.9.1"
MILESTONE "Human Control Plane and redundant update channels" status=passed version="0.9.2"
MILESTONE "Per-feed update diagnostics" status=passed version="0.9.3"
MILESTONE "Autonomous evolution epochs" status=passed version="0.9.4"
MILESTONE "Goal Layer and append-only ChatGPT memory archive" status=implemented version="0.9.5"
FACT goal_sources="human,chatgpt_memory,system_experience"
FACT goal_permission_authority=false
FACT goal_retention="archive-or-supersede-never-hard-delete"
FACT chatgpt_memory_archive=true
FACT chatgpt_memory_archive_payload_public=false
FACT chatgpt_memory_archive_retention="append-only-no-hard-delete"
FACT release_source_snapshots=true
FACT release_build_reproducibility=true
FACT experience_to_goal_to_candidate_model=true
FACT current_capabilities="workspace,memory,goals,memory_archive,deploy.single,deploy.package,self_update,genome,immune_scan,self_heal,quarantine,experience,insights,evolution"
END_PROJECT kicom
'''
architecture='''ARCHITECTURE kicom
COMPONENT chatgpt role="external reasoning cortex and development client"
COMPONENT https role="transport boundary"
COMPONENT kicom_core role="KCL parser, policy enforcement and response encoder"
COMPONENT canonical_memory role="versioned project knowledge under protected var/project_memory"
COMPONENT goal_layer role="persistent permissionless goals from human, ChatGPT memory and system experience"
COMPONENT memory_archive role="append-only protected ChatGPT-memory snapshots; payload is never public KCL output"
COMPONENT genome role="trusted desired-state blueprint"
COMPONENT recovery_kernel role="independent root of trust"
COMPONENT immune_system role="detect drift, quarantine unknown code, restore LKG"
COMPONENT evolution_lab role="non-executable candidates, lineage, fitness and risk-proportional promotion"
COMPONENT release_mirror role="immutable ZIP plus sanitized source snapshot and reproducible build recipe"
FLOW experience="runtime events -> insights -> system_experience goals"
FLOW memory_goal="approved ChatGPT-memory snapshot -> explicit goal hypotheses -> goal layer"
FLOW human_goal="authenticated Control Plane -> goal layer"
FLOW evolution="goal -> reasoning cortex -> candidate -> fitness -> promotion policy -> observed result -> new experience"
FLOW memory_archive="LLM POST or bounded transient chunk ingest -> pending proposal -> human approval -> immutable protected snapshot"
RULE "Goals never grant runtime permissions"
RULE "Goal history is archived or superseded, never hard deleted"
RULE "Memory archive has no hard-delete interface"
RULE "Snapshot payload stays in protected var storage and is not exposed through public KCL"
RULE "Transient chunk uploads are bounded, expire automatically and are not canonical memory"
RULE "Genome, memory, goals and phenotype remain separate authority domains"
RULE "Autonomous healing restores trusted state only"
RULE "Novel executable capabilities still follow Genome, fitness and risk policy"
RULE "Every release keeps immutable install ZIP and sanitized source snapshot"
DESIGN_GOAL "External recursive capability expansion: the model remains unchanged while its reachable operational action space grows through persistent, controlled tools and software"
END_ARCHITECTURE kicom
'''
protocol='''PROTOCOL KCL/1
SEMANTIC FACT="verified observation returned by KiCom"
SEMANTIC READY="accepted request requiring approval or further action"
GOALS read="?q=GOALS"
MEMORY_ARCHIVE status="?q=MEMORY_ARCHIVE_STATUS"
MEMORY_ARCHIVE list="?q=MEMORY_ARCHIVE_LIST"
MEMORY_ARCHIVE begin="?q=BRIDGE_INTENT&operation=MEMORY_ARCHIVE_BEGIN&target=chatgpt_memory then ?q=MEMORY_ARCHIVE_BEGIN&intent=<token>&label=<label>"
MEMORY_ARCHIVE chunk="?q=MEMORY_ARCHIVE_CHUNK&upload_id=<id>&token=<upload_token>&seq=<n>&data=<base64url>"
MEMORY_ARCHIVE finish="?q=MEMORY_ARCHIVE_FINISH&upload_id=<id>&token=<upload_token>"
POST goal_propose="api.php operation=GOAL_PROPOSE content=<base64url-json>"
POST memory_snapshot_propose="api.php operation=CHATGPT_MEMORY_SNAPSHOT_PROPOSE content=<base64url-json>"
RULE "Goal and memory archive final writes require human approval when supplied externally"
RULE "System-experience goals may be derived internally from trusted Living Insights"
RULE "Goals cannot change runtime permissions"
RULE "Archive payload is not exposed by public KCL"
RULE "No hard-delete operation exists for canonical goals or memory snapshots"
RULE "Transient ingest sessions expire and are bounded to 262144 payload bytes"
END_PROTOCOL KCL/1
'''
decisions='''DECISIONS kicom
DECISION D015 status=accepted title="Adopt Living Architecture"
RATIONALE D015 "KiCom uses genome, phenotype, recovery kernel, immune system, LKG, experience and controlled evolution"
DECISION D016 status=accepted title="Separate genome, memory and phenotype"
RATIONALE D016 "Blueprint, learned history and running state cannot silently redefine one another"
DECISION D017 status=accepted title="Healing restores trusted state; evolution is risk-proportional"
RATIONALE D017 "Known-good repair may be automatic; novel executable capability follows fitness and risk policy"
DECISION D018 status=accepted title="Persist a general Goal Layer"
RATIONALE D018 "Human intent, ChatGPT-memory hypotheses and system experience need a common durable goal representation between memory and evolution"
DECISION D019 status=accepted title="Goals never grant permissions"
RATIONALE D019 "A desired outcome may reveal a capability gap but cannot expand shell, network, deployment or filesystem authority"
DECISION D020 status=accepted title="Archive ChatGPT memory append-only"
RATIONALE D020 "Historical context should remain reconstructable; superseding and archiving are preferred over destructive deletion"
DECISION D021 status=accepted title="Do not expose memory archive payload through public KCL"
RATIONALE D021 "Backup may contain private context; public interfaces return metadata and hashes only"
DECISION D022 status=accepted title="Keep release-derived source snapshots"
RATIONALE D022 "Future capability work must never depend on recovering source through an LLM-hostile binary transport"
END_DECISIONS kicom
'''
changelog='''CHANGELOG kicom
RELEASE "0.9.0" date="2026-09-14" change="Trusted Genome, recovery kernel, LKG and Living Architecture"
RELEASE "0.9.1" date="2026-09-14" change="Autonomous Immune Guardian"
RELEASE "0.9.2" date="2026-09-15" change="Human Control Plane plus redundant HTTPS pull and authenticated push update channels"
RELEASE "0.9.3" date="2026-09-15" change="Per-feed update diagnostics"
RELEASE "0.9.4" date="2026-09-15" change="Autonomous evolution loop, safety stop, separate evolution generations and Epoch reports"
RELEASE "0.9.5" date="2026-09-15" change="Persistent Goal Layer, append-only ChatGPT memory archive, bounded LLM ingest channel, no-hard-delete policy and reproducible release source snapshots"
EVENT "2026-09-15" change="0.9.4 immutable release exported to source/0.9.4 and repository policy established: every future release keeps a sanitized source snapshot"
END_CHANGELOG kicom
'''
nextk='''NEXT kicom
PRIORITY 1 goal="Install and verify KiCom 0.9.5 Goal Layer and persistent memory archive"
PRIORITY 2 goal="Archive first approved ChatGPT project-memory snapshot and ingest its explicit project goal hypotheses"
PRIORITY 3 goal="Use Goal Layer as durable input to the external Reasoning Cortex for capability-gap detection and candidate creation"
PRIORITY 4 goal="Measure whether integrated capabilities reduce repeated work or unlock previously unreachable tasks"
PRIORITY 5 goal="Evolve toward KiCom 1.0 defined by a complete experience -> goal -> capability-gap -> candidate -> fitness -> integration -> measured-result loop"
CONSTRAINT "Goals cannot grant runtime permissions"
CONSTRAINT "Do not hard-delete canonical goals or memory snapshots; archive or supersede instead"
CONSTRAINT "Do not expose memory snapshot payload through public KCL"
CONSTRAINT "Preserve Genome, LKG, recovery-kernel and risk-class boundaries"
END_NEXT kicom
'''
mem=work/'memory'
(mem/'project_state.kcl').write_text(project_state)
(mem/'architecture.kcl').write_text(architecture)
(mem/'protocol.kcl').write_text(protocol)
(mem/'decisions.kcl').write_text(decisions)
(mem/'changelog.kcl').write_text(changelog)
(mem/'next.kcl').write_text(nextk)

# Genome lineage and component hashes.
gp=work/'genome/genome.json';g=json.loads(gp.read_text())
g['id']='kicom-0.9.5-g6';g['version']='0.9.5';g['parent']='kicom-0.9.4-g5';g['generation']=6;g['created_at']='2026-09-15T07:15:00Z';g['mutation_reason']='Persistent permissionless Goal Layer fed by human, ChatGPT memory and system experience; append-only protected memory archive; reproducible release source snapshots.'
for inv in ['goal-layer-no-permission-grants','memory-archive-no-hard-delete']:
    if inv not in g['invariants']: g['invariants'].append(inv)
g.setdefault('evolution',{})['goal_sources']=['human','chatgpt_memory','system_experience']
g['evolution']['goal_permission_authority']=False
g['evolution']['memory_archive']='append-only-protected'
if not any(c.get('path')=='goals_memory.php' for c in g['components']):
    g['components'].append({'path':'goals_memory.php','sha256':'','role':'goal-and-memory-archive-layer','auto_heal':True})
for comp in g['components']:
    p=work/comp['path'];
    if not p.is_file(): raise SystemExit(f"missing genome component {comp['path']}")
    comp['sha256']=sha(p)
gp.write_text(json.dumps(g,indent=2,ensure_ascii=False)+'\n')

# Runtime-state directories are preserved by installer, and release package carries only protective sentinels.
for dname in ['var','stage']:
    d=work/dname
    if d.exists():
        for p in list(d.iterdir()):
            if p.name=='.htaccess': continue
            if p.is_dir(): shutil.rmtree(p)
            else: p.unlink()

# Deterministic manifest.
rows=[]
for p in sorted(work.rglob('*')):
    if not p.is_file(): continue
    rel=p.relative_to(work).as_posix()
    if rel=='MANIFEST.sha256': continue
    rows.append(f'{sha(p)}  {rel}\n')
(work/'MANIFEST.sha256').write_text(''.join(rows))

if out_zip.exists(): out_zip.unlink()
with zipfile.ZipFile(out_zip,'w',zipfile.ZIP_DEFLATED) as zf:
    for p in sorted(work.rglob('*')):
        if p.is_file(): zf.write(p,p.relative_to(work).as_posix())
print(json.dumps({'package':str(out_zip),'sha256':sha(out_zip),'work':str(work)},indent=2))
