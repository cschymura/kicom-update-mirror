<?php
declare(strict_types=1);

/* KiCom Living Architecture 0.9.5 */
function kicomLivingDir(): string { return kicomVarDir().'/living'; }
function kicomEvolutionDir(): string { return kicomVarDir().'/evolution'; }
function kicomEvolutionCandidatesDir(): string { return kicomEvolutionDir().'/candidates'; }
function kicomEvolutionHistoryDir(): string { return kicomEvolutionDir().'/history'; }
function kicomEvolutionReportsDir(): string { return kicomEvolutionDir().'/reports'; }
function kicomEvolutionAutonomyFile(): string { return kicomEvolutionDir().'/autonomy.json'; }
function kicomEvolutionStateFile(): string { return kicomEvolutionDir().'/state.json'; }
function kicomEvolutionGoalsFile(): string { return kicomEvolutionDir().'/goals.json'; }
function kicomGuardianFile(): string { return kicomLivingDir().'/guardian.json'; }
function kicomLivingEventsFile(): string { return kicomLivingDir().'/events.jsonl'; }

function kicomLivingEnsure(): bool {
    foreach([kicomLivingDir(),kicomEvolutionDir(),kicomEvolutionCandidatesDir(),kicomEvolutionHistoryDir(),kicomEvolutionReportsDir(),kicomVarDir().'/quarantine',kicomVarDir().'/genome'] as $d){
        if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;
    }
    if(!defined('KICOM_RECOVERY_EMBEDDED'))define('KICOM_RECOVERY_EMBEDDED',true);
    require_once __DIR__.'/recovery.php';
    if(!rkEnsureDirs())return false;
    if(!is_file(kicomGuardianFile())){
        try{$key=bin2hex(random_bytes(24));}catch(Throwable $e){$key=hash('sha256',uniqid('',true));}
        $row=['key_hash'=>hash('sha256',$key),'created_at'=>gmdate('c'),'hint'=>'Full key is intentionally shown only in admin runtime.','key_plain'=>$key];
        if(@file_put_contents(kicomGuardianFile(),json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false)return false;@chmod(kicomGuardianFile(),0600);
    }
    $trust=rkTrust();if($trust===null){$boot=rkBootstrapTrust(false);if(!$boot['ok'])return false;}
    return true;
}
function kicomLivingEvent(string $type,string $severity='info',array $data=[]): void {
    if(!defined('KICOM_RECOVERY_EMBEDDED'))define('KICOM_RECOVERY_EMBEDDED',true);require_once __DIR__.'/recovery.php';rkEvent($type,$severity,$data);
}
function kicomLivingStatus(): array {
    kicomLivingEnsure();$s=rkScan();$events=0;if(is_file(kicomLivingEventsFile())){$h=@fopen(kicomLivingEventsFile(),'rb');if($h){while(!feof($h)){if(fgets($h)!==false)$events++;}fclose($h);}}
    $candidates=count(glob(kicomEvolutionCandidatesDir().'/*.json')?:[]);
    return $s+['events'=>$events,'candidates'=>$candidates];
}
function kicomGuardianPlainKey(): ?string {
    $r=is_file(kicomGuardianFile())?json_decode((string)@file_get_contents(kicomGuardianFile()),true):null;return is_array($r)&&is_string($r['key_plain']??null)?$r['key_plain']:null;
}
function kicomRotateGuardianKey(): ?string {
    kicomLivingEnsure();try{$key=bin2hex(random_bytes(24));}catch(Throwable $e){$key=hash('sha256',uniqid('',true));}
    $row=['key_hash'=>hash('sha256',$key),'created_at'=>gmdate('c'),'key_plain'=>$key];$j=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($j===false||@file_put_contents(kicomGuardianFile(),$j,LOCK_EX)===false)return null;@chmod(kicomGuardianFile(),0600);kicomLivingEvent('guardian_key_rotated');return $key;
}
function kicomLivingExperience(int $limit=500): array {
    kicomLivingEnsure();$rows=[];$f=kicomLivingEventsFile();if(is_file($f)){
        $lines=@file($f,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];foreach(array_slice($lines,-$limit) as $line){$r=json_decode($line,true);if(is_array($r))$rows[]=$r;}
    }
    $types=[];$paths=[];$severity=[];foreach($rows as $r){$t=(string)($r['type']??'unknown');$types[$t]=($types[$t]??0)+1;$sev=(string)($r['severity']??'info');$severity[$sev]=($severity[$sev]??0)+1;$d=$r['data']??[];if(is_array($d)){foreach(['path','component'] as $k)if(is_string($d[$k]??null)){$p=$d[$k];$paths[$p]=($paths[$p]??0)+1;}foreach(['repaired','quarantined','files'] as $lk)foreach(($d[$lk]??[]) as $p)if(is_string($p))$paths[$p]=($paths[$p]??0)+1;}}
    arsort($types);arsort($paths);arsort($severity);return ['events'=>count($rows),'types'=>$types,'paths'=>$paths,'severity'=>$severity,'recent'=>array_slice(array_reverse($rows),0,20)];
}
function kicomLivingInsights(): array {
    $e=kicomLivingExperience(1000);$insights=[];$add=function(string $kind,string $priority,string $evidence,string $hypothesis,string $suggested)use(&$insights){$insights[]=['kind'=>$kind,'priority'=>$priority,'evidence'=>$evidence,'hypothesis'=>$hypothesis,'suggested_action'=>$suggested];};
    foreach(($e['paths']??[]) as $path=>$count){if((int)$count>=2)$add('repeated_component_event',(int)$count>=4?'high':'medium',$path.' x'.(int)$count,'Repeated events around the same component may indicate a recurring root cause rather than isolated corruption.','Review the component history and consider a candidate mutation that removes the recurring cause or adds an earlier invariant/preflight.');}
    $types=$e['types']??[];
    if((int)($types['healing_blocked']??0)>0)$add('kernel_drift','high','healing_blocked x'.(int)$types['healing_blocked'],'Recovery-kernel drift cannot be healed by the normal phenotype.','Inspect the recovery kernel manually; only promote a kernel mutation with incremented kernel_revision and explicit human confirmation.');
    if((int)($types['unknown_quarantine']??0)>0)$add('unexpected_code','high','unknown_quarantine x'.(int)$types['unknown_quarantine'],'Unexpected executable/control artifacts reached the KiCom tree.','Investigate origin and credentials; keep artifacts quarantined unless a reviewed genome mutation explicitly incorporates them.');
    if((int)($types['candidate_evaluated']??0)>0){$rejected=0;foreach(($e['recent']??[]) as $r)if(($r['type']??'')==='candidate_evaluated'&&($r['severity']??'')==='warn')$rejected++;if($rejected>0)$add('candidate_rejection','medium','recent rejected candidates '.$rejected,'Candidate mutations are failing deterministic fitness checks.','Use the failed fitness evidence to revise the mutation before another promotion attempt.');}
    if(empty($insights))$add('baseline','low','no recurring adverse pattern','No recurring fault pattern is currently visible in the retained experience window.','Continue observing; do not mutate the genome without evidence or an explicit improvement goal.');
    return ['generated_at'=>gmdate('c'),'events_considered'=>(int)($e['events']??0),'insights'=>$insights];
}
function kicomGenomeCurrent(): ?array {
    kicomLivingEnsure();$r=rkLoadGenome();return $r['ok']?$r['genome']:null;
}
function kicomGenomeValidateArray(array $g,array $packageEntries=[]): array {
    if((int)($g['schema']??0)!==1||!is_string($g['id']??null)||!is_string($g['version']??null)||!is_string($g['parent']??null)||!is_array($g['components']??null))return ['ok'=>false,'code'=>'GENOME_SCHEMA_INVALID'];
    if(!preg_match('/^[A-Za-z0-9._-]{3,80}$/',(string)$g['id'])||!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',(string)$g['version']))return ['ok'=>false,'code'=>'GENOME_ID_OR_VERSION_INVALID'];
    if((int)($g['generation']??0)<1||(int)($g['kernel_revision']??0)<1||trim((string)($g['mutation_reason']??''))==='')return ['ok'=>false,'code'=>'GENOME_LINEAGE_METADATA_INVALID'];
    $requiredInvariants=['no-arbitrary-shell','no-arbitrary-sql','no-arbitrary-remote-fetch','human-gated-new-capabilities','separate-genome-memory-phenotype','autonomous-heal-trusted-state-only','production-writes-human-approved','recovery-kernel-not-auto-healed','unknown-code-quarantine-before-use','goal-layer-no-permission-grants','memory-archive-no-hard-delete'];
    $inv=array_values(array_filter($g['invariants']??[],'is_string'));foreach($requiredInvariants as $i)if(!in_array($i,$inv,true))return ['ok'=>false,'code'=>'GENOME_INVARIANT_MISSING','invariant'=>$i];
    $health=array_values(array_filter($g['healthchecks']??[],'is_string'));foreach(['HELLO','BOOTSTRAP','LIVING_STATUS'] as $h)if(!in_array($h,$health,true))return ['ok'=>false,'code'=>'GENOME_HEALTHCHECK_MISSING','healthcheck'=>$h];
    $seen=[];foreach($g['components'] as $c){if(!is_array($c))return ['ok'=>false,'code'=>'GENOME_COMPONENT_INVALID'];$p=kicomSafeUpdatePath((string)($c['path']??''));$sha=strtolower((string)($c['sha256']??''));if($p===null||isset($seen[$p])||!preg_match('/^[a-f0-9]{64}$/',$sha))return ['ok'=>false,'code'=>'GENOME_COMPONENT_INVALID'];$seen[$p]=true;if($packageEntries&&(!isset($packageEntries[$p])||!hash_equals($sha,(string)($packageEntries[$p]['sha256']??''))))return ['ok'=>false,'code'=>'GENOME_COMPONENT_HASH_MISMATCH','path'=>$p];}
    foreach(['lib.php','index.php','api.php','admin.php','living.php','goals_memory.php','recovery.php','guardian.php','.htaccess','robots.txt','genome/.htaccess','memory/.htaccess','stage/.htaccess','var/.htaccess'] as $p)if(!isset($seen[$p]))return ['ok'=>false,'code'=>'GENOME_REQUIRED_COMPONENT_MISSING','path'=>$p];
    return ['ok'=>true,'kernel_revision'=>(int)$g['kernel_revision'],'generation'=>(int)$g['generation'],'components'=>count($seen)];
}
function kicomEvolutionCandidateFile(string $id): string { return kicomEvolutionCandidatesDir().'/'.$id.'.json'; }
function kicomEvolutionPackageFile(string $id): string { return kicomEvolutionCandidatesDir().'/'.$id.'.zip'; }
function kicomEvolutionSafeId(string $id): ?string { $id=strtolower(trim($id));return preg_match('/^[a-f0-9]{16,64}$/',$id)?$id:null; }
function kicomEvolutionAtomicJson(string $file,array $row): bool {
    $json=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)return false;
    $tmp=$file.'.tmp-'.substr(hash('sha256',uniqid('',true)),0,12);
    if(@file_put_contents($tmp,$json."\n",LOCK_EX)===false)return false;@chmod($tmp,0600);
    if(!@rename($tmp,$file)){@unlink($tmp);return false;}@chmod($file,0600);return true;
}
function kicomEvolutionReadJson(string $file): ?array {
    if(!is_file($file))return null;$r=json_decode((string)@file_get_contents($file),true);return is_array($r)?$r:null;
}
function kicomEvolutionAutonomyConfig(): array {
    kicomLivingEnsure();$r=kicomEvolutionReadJson(kicomEvolutionAutonomyFile());
    $defaults=['schema'=>1,'enabled'=>true,'auto_promote_green'=>true,'report_every'=>10,'max_promotions_per_tick'=>1,'created_at'=>gmdate('c')];
    if(!is_array($r)){$r=$defaults;kicomEvolutionAtomicJson(kicomEvolutionAutonomyFile(),$r);}
    $r=array_replace($defaults,$r);$r['report_every']=max(2,min(100,(int)$r['report_every']));$r['max_promotions_per_tick']=max(1,min(3,(int)$r['max_promotions_per_tick']));
    return $r;
}
function kicomEvolutionSetEnabled(bool $enabled): bool {
    $c=kicomEvolutionAutonomyConfig();$c['enabled']=$enabled;$c['updated_at']=gmdate('c');$ok=kicomEvolutionAtomicJson(kicomEvolutionAutonomyFile(),$c);
    if($ok)kicomLivingEvent($enabled?'evolution_autonomy_resumed':'evolution_autonomy_paused','info');return $ok;
}
function kicomEvolutionDefaultStats(): array { return ['candidates_evaluated'=>0,'auto_promoted'=>0,'human_gated'=>0,'rejected'=>0,'install_failures'=>0,'rollbacks'=>0,'goals_opened'=>0]; }
function kicomEvolutionState(): array {
    kicomLivingEnsure();$r=kicomEvolutionReadJson(kicomEvolutionStateFile());
    $defaults=['schema'=>1,'generation'=>0,'epoch'=>0,'last_report_generation'=>0,'counted_history_ids'=>[],'last_tick_at'=>'','last_tick_code'=>'never','alert'=>null,'epoch_stats'=>kicomEvolutionDefaultStats(),'started_at'=>gmdate('c')];
    if(!is_array($r)){$r=$defaults;kicomEvolutionAtomicJson(kicomEvolutionStateFile(),$r);}
    $r=array_replace($defaults,$r);if(!is_array($r['counted_history_ids']))$r['counted_history_ids']=[];if(!is_array($r['epoch_stats']))$r['epoch_stats']=kicomEvolutionDefaultStats();
    $r['epoch_stats']=array_replace(kicomEvolutionDefaultStats(),$r['epoch_stats']);return $r;
}
function kicomEvolutionSaveState(array $state): bool { return kicomEvolutionAtomicJson(kicomEvolutionStateFile(),$state); }
function kicomEvolutionGoals(): array {
    kicomLivingEnsure();$r=kicomEvolutionReadJson(kicomEvolutionGoalsFile());if(!is_array($r))$r=['schema'=>1,'goals'=>[]];if(!is_array($r['goals']??null))$r['goals']=[];return $r;
}
function kicomEvolutionRefreshGoals(array &$state): array {
    $store=kicomEvolutionGoals();$goals=$store['goals'];$known=[];foreach($goals as $i=>$g)if(is_array($g)&&isset($g['id']))$known[(string)$g['id']]=$i;
    $opened=0;$ins=kicomLivingInsights();
    foreach(($ins['insights']??[]) as $in){
        if(!is_array($in)||($in['kind']??'')==='baseline'||!in_array((string)($in['priority']??'low'),['medium','high'],true))continue;
        $basis=(string)($in['kind']??'').'|'.(string)($in['evidence']??'').'|'.(string)($in['suggested_action']??'');$id=substr(hash('sha256',$basis),0,20);
        if(isset($known[$id])){$idx=$known[$id];$goals[$idx]['last_seen_at']=gmdate('c');$goals[$idx]['priority']=(string)($in['priority']??'medium');continue;}
        $goals[]=['id'=>$id,'status'=>'open','created_at'=>gmdate('c'),'last_seen_at'=>gmdate('c'),'kind'=>(string)($in['kind']??''),'priority'=>(string)($in['priority']??'medium'),'evidence'=>(string)($in['evidence']??''),'hypothesis'=>(string)($in['hypothesis']??''),'suggested_action'=>(string)($in['suggested_action']??'')];$known[$id]=count($goals)-1;$opened++;
    }
    $store=['schema'=>1,'updated_at'=>gmdate('c'),'policy'=>'archive-never-hard-delete','goals'=>$goals];kicomEvolutionAtomicJson(kicomEvolutionGoalsFile(),$store);
    if($opened>0)$state['epoch_stats']['goals_opened']=(int)($state['epoch_stats']['goals_opened']??0)+$opened;
    if(function_exists('kicomGoalSyncSystemExperience'))kicomGoalSyncSystemExperience();
    return $store;
}
function kicomEvolutionLatestReport(): ?array {
    kicomLivingEnsure();$files=glob(kicomEvolutionReportsDir().'/epoch-*.json')?:[];if(!$files)return null;rsort($files,SORT_STRING);$r=json_decode((string)@file_get_contents($files[0]),true);return is_array($r)?$r:null;
}
function kicomEvolutionWriteEpochReport(array &$state,array $cfg): ?array {
    $generation=(int)($state['generation']??0);$every=(int)($cfg['report_every']??10);if($generation<1||$generation%$every!==0||$generation<=(int)($state['last_report_generation']??0))return null;
    $epoch=(int)ceil($generation/$every);$scan=rkScan();$g=kicomGenomeCurrent();$goals=kicomEvolutionGoals();$openGoals=array_values(array_filter($goals['goals']??[],fn($x)=>is_array($x)&&($x['status']??'open')==='open'));
    $ins=kicomLivingInsights();$report=['schema'=>1,'epoch'=>$epoch,'generation_start'=>$generation-$every+1,'generation_end'=>$generation,'created_at'=>gmdate('c'),'product_version'=>defined('KICOM_VERSION')?KICOM_VERSION:'','genome_id'=>(string)($g['id']??''),'health'=>['healthy'=>(bool)($scan['healthy']??false),'trusted'=>(bool)($scan['trusted']??false),'lkg_ok'=>(bool)($scan['lkg_ok']??false),'drift'=>count($scan['drift']??[]),'unknown'=>count($scan['unknown']??[])],'stats'=>$state['epoch_stats']??kicomEvolutionDefaultStats(),'open_goals'=>count($openGoals),'insights'=>array_slice($ins['insights']??[],0,8),'epoch_lkg'=>['genome_id'=>(string)($scan['genome_id']??($g['id']??'')),'trusted'=>(bool)($scan['trusted']??false),'lkg_ok'=>(bool)($scan['lkg_ok']??false)]];
    $base=sprintf('epoch-%04d',$epoch);if(!kicomEvolutionAtomicJson(kicomEvolutionReportsDir().'/'.$base.'.json',$report))return null;
    $md="# KiCom Evolution Report – Epoch {$epoch}\n\nGenerationen {$report['generation_start']}–{$report['generation_end']} · Produkt {$report['product_version']} · Genome {$report['genome_id']}\n\n";
    $md.="## Zustand\n- healthy: ".($report['health']['healthy']?'true':'false')."\n- trusted: ".($report['health']['trusted']?'true':'false')."\n- LKG: ".($report['health']['lkg_ok']?'OK':'FEHLER')."\n- Drift: {$report['health']['drift']}\n- Unknown: {$report['health']['unknown']}\n\n## Epoch-Statistik\n";
    foreach($report['stats'] as $k=>$v)$md.='- '.$k.': '.(int)$v."\n";$md.="\nOffene Evolutionsziele: {$report['open_goals']}\n";@file_put_contents(kicomEvolutionReportsDir().'/'.$base.'.md',$md,LOCK_EX);@chmod(kicomEvolutionReportsDir().'/'.$base.'.md',0600);
    $state['last_report_generation']=$generation;$state['epoch']=$epoch;$state['epoch_stats']=kicomEvolutionDefaultStats();kicomLivingEvent('evolution_epoch_report','info',['epoch'=>$epoch,'generation'=>$generation,'genome_id'=>$report['genome_id']]);return $report;
}
function kicomEvolutionHistoryIsEvolutionary(array $row): bool {
    if(!empty($row['evolutionary']))return true;$source=(string)($row['source']??'');return str_starts_with($source,'evolution:');
}
function kicomEvolutionObserveInstalledUpdate(array $row,bool $emit=true): bool {
    if(($row['status']??'')!=='installed'||!kicomEvolutionHistoryIsEvolutionary($row))return false;$id=(string)($row['id']??'');if($id==='')return false;
    $cfg=kicomEvolutionAutonomyConfig();$state=kicomEvolutionState();if(in_array($id,$state['counted_history_ids'],true))return false;
    $state['counted_history_ids'][]=$id;if(count($state['counted_history_ids'])>120)$state['counted_history_ids']=array_slice($state['counted_history_ids'],-120);
    $state['generation']=(int)$state['generation']+1;$state['epoch']=(int)ceil($state['generation']/(int)$cfg['report_every']);$state['last_generation_at']=gmdate('c');$state['last_generation_version']=(string)($row['to_version']??'');$state['last_generation_genome']=(string)($row['genome_id']??'');
    if($emit)kicomLivingEvent('evolution_generation_committed','info',['generation'=>$state['generation'],'epoch'=>$state['epoch'],'version'=>$state['last_generation_version'],'genome_id'=>$state['last_generation_genome'],'history_id'=>$id]);
    kicomEvolutionWriteEpochReport($state,$cfg);return kicomEvolutionSaveState($state);
}
function kicomEvolutionSyncInstalledGenerations(): int {
    $rows=function_exists('kicomSelfUpdateHistory')?array_reverse(kicomSelfUpdateHistory()):[];$count=0;foreach($rows as $row)if(is_array($row)&&kicomEvolutionObserveInstalledUpdate($row,false))$count++;return $count;
}
function kicomEvolutionSetAlert(array &$state,?array $alert): void {
    $old=is_array($state['alert']??null)?(string)($state['alert']['code']??''):'';$new=is_array($alert)?(string)($alert['code']??''):'';$state['alert']=$alert;
    if($new!==''&&$new!==$old)kicomLivingEvent('evolution_alert','critical',$alert);elseif($new===''&&$old!=='')kicomLivingEvent('evolution_alert_cleared','info',['previous'=>$old]);
}
function kicomEvolutionCandidateSave(array $c): bool {
    $id=kicomEvolutionSafeId((string)($c['id']??''));if($id===null)return false;return kicomEvolutionAtomicJson(kicomEvolutionCandidateFile($id),$c);
}
function kicomEvolutionAutonomousTick(): array {
    kicomLivingEnsure();$cfg=kicomEvolutionAutonomyConfig();$state=kicomEvolutionState();kicomEvolutionSyncInstalledGenerations();$state=kicomEvolutionState();kicomEvolutionRefreshGoals($state);
    $state['last_tick_at']=gmdate('c');
    if(empty($cfg['enabled'])){$state['last_tick_code']='PAUSED';kicomEvolutionSaveState($state);return ['ok'=>true,'code'=>'PAUSED'];}
    $scan=rkScan();$unsafe=empty($scan['healthy'])||empty($scan['trusted'])||empty($scan['lkg_ok'])||!empty($scan['drift'])||!empty($scan['unknown'])||!empty($scan['kernel_drift']);
    if($unsafe){$alert=['code'=>'SAFETY_STATE_BLOCKS_EVOLUTION','at'=>gmdate('c'),'healthy'=>(bool)($scan['healthy']??false),'trusted'=>(bool)($scan['trusted']??false),'lkg_ok'=>(bool)($scan['lkg_ok']??false),'drift'=>count($scan['drift']??[]),'unknown'=>count($scan['unknown']??[])];kicomEvolutionSetAlert($state,$alert);$state['last_tick_code']=$alert['code'];kicomEvolutionSaveState($state);return ['ok'=>false,'code'=>$alert['code']];}
    kicomEvolutionSetAlert($state,null);
    if(function_exists('kicomSelfUpdatePending')&&kicomSelfUpdatePending()!==null){$state['last_tick_code']='WAITING_SELF_UPDATE';kicomEvolutionSaveState($state);return ['ok'=>true,'code'=>'WAITING_SELF_UPDATE'];}
    $handled=0;foreach(kicomEvolutionCandidates() as $c){
        if($handled>=(int)$cfg['max_promotions_per_tick'])break;$status=(string)($c['status']??'');if(!in_array($status,['fit','awaiting_human'],true)||empty($c['fitness']['passed']))continue;
        $pkg=kicomEvolutionPackageFile((string)$c['id']);if(!is_file($pkg)){$c['status']='rejected';$c['last_code']='CANDIDATE_PACKAGE_MISSING';kicomEvolutionCandidateSave($c);$state['epoch_stats']['rejected']++;continue;}
        $check=kicomSelfUpdateZipInspect($pkg);if(empty($check['ok'])){$c['status']='rejected';$c['last_code']=(string)($check['code']??'PACKAGE_INVALID');kicomEvolutionCandidateSave($c);$state['epoch_stats']['rejected']++;continue;}
        $risk=kicomSelfUpdateRiskClass($check);$c['risk_class']=$risk['class'];$c['risk_reasons']=$risk['reasons'];$c['risk_checked_at']=gmdate('c');
        if($risk['class']!=='green'||empty($cfg['auto_promote_green'])){
            if($status!=='awaiting_human'){$state['epoch_stats']['human_gated']++;kicomLivingEvent('evolution_human_gate_required','info',['candidate'=>$c['id'],'version'=>$c['to_version']??'','risk_class'=>$risk['class']]);}
            $c['status']='awaiting_human';kicomEvolutionCandidateSave($c);$handled++;continue;
        }
        $staged=kicomStageSelfUpdatePackage($pkg,(string)($c['original_name']??'candidate.zip'),'evolution:auto:'.(string)$c['id']);
        if(empty($staged['ok'])||($staged['risk_class']??'')!=='green'){$c['status']='rejected';$c['last_code']=(string)($staged['code']??'STAGE_FAILED');kicomEvolutionCandidateSave($c);$state['epoch_stats']['rejected']++;$handled++;continue;}
        $c['status']='auto_promoted';$c['promoted_at']=gmdate('c');kicomEvolutionCandidateSave($c);$state['epoch_stats']['auto_promoted']++;kicomEvolutionSaveState($state);
        $pending=kicomSelfUpdatePending();$apply=is_array($pending)?kicomApplySelfUpdate($pending):['ok'=>false,'code'=>'PENDING_LOST'];
        if(empty($apply['ok'])){$state=kicomEvolutionState();$state['epoch_stats']['install_failures']++;$state['last_tick_code']='AUTO_INSTALL_FAILED';kicomEvolutionSetAlert($state,['code'=>'EVOLUTION_AUTO_INSTALL_FAILED','at'=>gmdate('c'),'candidate'=>$c['id'],'detail'=>(string)($apply['code']??'UNKNOWN')]);kicomEvolutionSaveState($state);return ['ok'=>false,'code'=>'AUTO_INSTALL_FAILED','candidate'=>$c['id'],'install'=>$apply];}
        $state=kicomEvolutionState();$state['last_tick_code']='AUTO_INSTALLED_GREEN';kicomEvolutionSaveState($state);return ['ok'=>true,'code'=>'AUTO_INSTALLED_GREEN','candidate'=>$c['id'],'to_version'=>$c['to_version']??'','install'=>$apply];
    }
    $state['last_tick_code']=$handled>0?'HUMAN_GATE_PENDING':'IDLE';kicomEvolutionSaveState($state);return ['ok'=>true,'code'=>$state['last_tick_code'],'handled'=>$handled];
}
function kicomEvolutionStatus(): array {
    kicomEvolutionSyncInstalledGenerations();$cfg=kicomEvolutionAutonomyConfig();$state=kicomEvolutionState();$goals=kicomEvolutionGoals();$report=kicomEvolutionLatestReport();$open=array_values(array_filter($goals['goals']??[],fn($x)=>is_array($x)&&($x['status']??'open')==='open'));
    $every=(int)$cfg['report_every'];$generation=(int)$state['generation'];$next=$generation===0?$every:((int)(floor($generation/$every)+1)*$every);
    return ['config'=>$cfg,'state'=>$state,'latest_report'=>$report,'open_goals'=>count($open),'next_report_generation'=>$next,'progress'=>$every?($generation%$every):0];
}
function kicomEvolutionFitness(array $check): array {
    $tests=[];$score=0;$max=0;$add=function(string $name,bool $ok,int $weight,string $detail='')use(&$tests,&$score,&$max){$max+=$weight;if($ok)$score+=$weight;$tests[]=['name'=>$name,'ok'=>$ok,'weight'=>$weight,'detail'=>$detail];};
    $add('package_manifest',!empty($check['ok']),15,empty($check['ok'])?(string)($check['code']??'invalid'):'verified');if(empty($check['ok']))return ['score'=>0,'max'=>$max,'passed'=>false,'tests'=>$tests];
    $g=$check['genome']??null;$gv=is_array($g)?kicomGenomeValidateArray($g,(array)($check['entries']??[])):['ok'=>false,'code'=>'GENOME_MISSING'];$add('genome_schema',!empty($gv['ok']),20,(string)($gv['code']??'valid'));
    $current=kicomGenomeCurrent();$parentOk=is_array($g)&&is_array($current)&&hash_equals((string)($g['parent']??''),(string)($current['id']??''));$add('lineage_parent',$parentOk,12,$parentOk?'matches-current':'parent-mismatch');
    $generationOk=is_array($g)&&is_array($current)&&(int)($g['generation']??0)===(int)($current['generation']??0)+1;$add('lineage_generation',$generationOk,8,$generationOk?'next-generation':'generation-mismatch');
    $reasonOk=is_array($g)&&trim((string)($g['mutation_reason']??''))!=='';$add('mutation_reason',$reasonOk,5,$reasonOk?'declared':'missing');
    $versionOk=version_compare((string)($check['version']??'0.0.0'),KICOM_VERSION,'>');$add('version_progression',$versionOk,10,(string)($check['version']??''));
    $add('php_syntax',true,12,'validated during package inspection');
    $privacy=isset($check['entries']['.htaccess'],$check['entries']['robots.txt'],$check['entries']['genome/.htaccess'],$check['entries']['memory/.htaccess'],$check['entries']['stage/.htaccess'],$check['entries']['var/.htaccess']);$add('privacy_controls',$privacy,8,$privacy?'present':'missing');
    $idx=(string)($check['entries']['index.php']['content']??'');$iface=$idx!==''&&str_contains($idx,"case 'HELLO'")&&str_contains($idx,"case 'BOOTSTRAP'")&&str_contains($idx,"case 'LIVING_STATUS'")&&str_contains($idx,"case 'DESCRIBE'");$add('static_interface_regression',$iface,10,$iface?'core KCL surfaces present':'required KCL surface missing');
    $kernelChanged=(bool)($check['kernel_update']??false);$curKr=(int)($current['kernel_revision']??0);$newKr=(int)($g['kernel_revision']??0);$declared=$kernelChanged?$newKr>$curKr:$newKr===$curKr;$add('kernel_change_declared',$declared,10,$kernelChanged?'kernel-update':'kernel-stable');
    $add('human_gate_preserved',is_array($g)&&in_array('human-gated-new-capabilities',$g['invariants']??[],true),5,'invariant');
    $passed=$score===$max;return ['score'=>$score,'max'=>$max,'percent'=>$max?round($score*100/$max,1):0,'passed'=>$passed,'tests'=>$tests];
}
function kicomEvolutionStageCandidate(string $sourcePath,string $originalName='candidate.zip'): array {
    if(!kicomLivingEnsure())return ['ok'=>false,'code'=>'LIVING_STORAGE_UNAVAILABLE'];$check=kicomSelfUpdateZipInspect($sourcePath);if(!$check['ok'])return $check;
    if(version_compare((string)$check['version'],KICOM_VERSION,'<='))return ['ok'=>false,'code'=>'VERSION_NOT_NEWER'];
    $fitness=kicomEvolutionFitness($check);$id=substr(hash('sha256',(string)$check['zip_sha256'].'|'.microtime(true)),0,24);$pkg=kicomEvolutionPackageFile($id);if(!@copy($sourcePath,$pkg))return ['ok'=>false,'code'=>'CANDIDATE_STORE_FAILED'];@chmod($pkg,0600);
    $g=$check['genome']??[];$row=['id'=>$id,'original_name'=>basename($originalName),'created_at'=>gmdate('c'),'from_version'=>KICOM_VERSION,'to_version'=>$check['version'],'package_sha256'=>$check['zip_sha256'],'manifest_sha256'=>$check['manifest_sha256'],'genome_id'=>is_array($g)?($g['id']??''):'','parent'=>is_array($g)?($g['parent']??''):'','mutation_reason'=>is_array($g)?($g['mutation_reason']??''):'','kernel_update'=>(bool)($check['kernel_update']??false),'fitness'=>$fitness,'status'=>$fitness['passed']?'fit':'rejected'];
    $j=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);if($j===false||@file_put_contents(kicomEvolutionCandidateFile($id),$j,LOCK_EX)===false){@unlink($pkg);return ['ok'=>false,'code'=>'CANDIDATE_META_FAILED'];}@chmod(kicomEvolutionCandidateFile($id),0600);
    kicomLivingEvent('candidate_evaluated',$fitness['passed']?'info':'warn',['candidate'=>$id,'version'=>$check['version'],'fitness'=>$fitness['percent'],'genome_id'=>$row['genome_id']]);$es=kicomEvolutionState();$es['epoch_stats']['candidates_evaluated']=(int)($es['epoch_stats']['candidates_evaluated']??0)+1;if(!$fitness['passed'])$es['epoch_stats']['rejected']=(int)($es['epoch_stats']['rejected']??0)+1;kicomEvolutionSaveState($es);return ['ok'=>true]+$row;
}
function kicomEvolutionCandidates(): array {
    kicomLivingEnsure();$rows=[];foreach(glob(kicomEvolutionCandidatesDir().'/*.json')?:[] as $f){$r=json_decode((string)@file_get_contents($f),true);if(is_array($r))$rows[]=$r;}usort($rows,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));return $rows;
}
function kicomEvolutionCandidate(string $id): ?array { $id=kicomEvolutionSafeId($id);if($id===null)return null;$f=kicomEvolutionCandidateFile($id);if(!is_file($f))return null;$r=json_decode((string)@file_get_contents($f),true);return is_array($r)?$r:null; }
function kicomEvolutionPromote(string $id): array {
    $c=kicomEvolutionCandidate($id);if($c===null)return ['ok'=>false,'code'=>'CANDIDATE_NOT_FOUND'];if(empty($c['fitness']['passed']))return ['ok'=>false,'code'=>'CANDIDATE_NOT_FIT'];$pkg=kicomEvolutionPackageFile((string)$c['id']);if(!is_file($pkg))return ['ok'=>false,'code'=>'CANDIDATE_PACKAGE_MISSING'];$r=kicomStageSelfUpdatePackage($pkg,(string)$c['original_name'],'evolution:human:'.$id);if(!$r['ok'])return $r;$c['status']='promoted';$c['promoted_at']=gmdate('c');$c['risk_class']=$r['risk_class']??'';$c['risk_reasons']=$r['risk_reasons']??[];kicomEvolutionCandidateSave($c);kicomLivingEvent('candidate_promoted','info',['candidate'=>$id,'to_version'=>$c['to_version'],'risk_class'=>$r['risk_class']??'']);return ['ok'=>true]+$r;
}
function kicomLivingCommitGenomeAfterInstall(): array {
    if(!defined('KICOM_RECOVERY_EMBEDDED'))define('KICOM_RECOVERY_EMBEDDED',true);require_once __DIR__.'/recovery.php';$r=rkBootstrapTrust(true);kicomLivingEvent('genome_promoted',$r['ok']?'info':'error',['version'=>$r['version']??'','genome_id'=>$r['genome_id']??'','result'=>$r['ok']]);return $r;
}

/* KiCom 0.9.5 Goal Layer + append-only ChatGPT memory archive. */
function kicomGoalDir(): string { return kicomVarDir().'/goals'; }
function kicomGoalRegistryFile(): string { return kicomGoalDir().'/registry.json'; }
function kicomGoalEventsFile(): string { return kicomGoalDir().'/events.jsonl'; }
function kicomMemoryArchiveDir(): string { return kicomVarDir().'/memory_archive'; }
function kicomMemoryArchiveSnapshotsDir(): string { return kicomMemoryArchiveDir().'/snapshots'; }
function kicomMemoryArchiveUploadsDir(): string { return kicomMemoryArchiveDir().'/uploads'; }
function kicomMemoryArchiveEventsFile(): string { return kicomMemoryArchiveDir().'/events.jsonl'; }

function kicomGoalMemoryEnsure(): bool {
    foreach([kicomGoalDir(),kicomMemoryArchiveDir(),kicomMemoryArchiveSnapshotsDir(),kicomMemoryArchiveUploadsDir()] as $d){
        if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;
        $deny=$d.'/.htaccess';if(!is_file($deny))@file_put_contents($deny,kicomDenyRules(),LOCK_EX);
    }
    return true;
}
function kicomGoalAppendEvent(string $file,array $row): void {
    $row=['at'=>gmdate('c')]+$row;$json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json!==false)@file_put_contents($file,$json."\n",FILE_APPEND|LOCK_EX);
}
function kicomGoalSource(string $source): string {
    $source=strtolower(trim($source));return in_array($source,['human','chatgpt_memory','system_experience'],true)?$source:'system_experience';
}
function kicomGoalPriority(string $priority): string {
    $priority=strtolower(trim($priority));return in_array($priority,['low','medium','high','critical'],true)?$priority:'medium';
}
function kicomGoalText(string $value,int $max): string {
    $value=trim(preg_replace('/\s+/u',' ',$value)??$value);return kicomTextLen($value)>$max?kicomTextSubstr($value,0,$max):$value;
}
function kicomGoalId(string $source,string $sourceRef,string $title,string $description): string {
    $basis=kicomGoalSource($source).'|'.trim($sourceRef).'|'.trim($title).'|'.trim($description);return substr(hash('sha256',$basis),0,24);
}
function kicomGoalRegistryRaw(): array {
    kicomGoalMemoryEnsure();$f=kicomGoalRegistryFile();$r=is_file($f)?json_decode((string)@file_get_contents($f),true):null;
    if(!is_array($r))$r=['schema'=>1,'policy'=>'archive-never-hard-delete','goals'=>[]];if(!is_array($r['goals']??null))$r['goals']=[];return $r;
}
function kicomGoalRegistrySave(array $store): bool {
    $store['schema']=1;$store['policy']='archive-never-hard-delete';$store['updated_at']=gmdate('c');return kicomEvolutionAtomicJson(kicomGoalRegistryFile(),$store);
}
function kicomGoalMirrorToEvolution(array $goal): void {
    $store=kicomEvolutionGoals();$rows=$store['goals']??[];$id=(string)($goal['id']??'');if($id==='')return;$idx=null;
    foreach($rows as $i=>$r)if(is_array($r)&&hash_equals((string)($r['id']??''),$id)){$idx=$i;break;}
    $entry=['id'=>$id,'status'=>(string)($goal['status']??'open'),'created_at'=>(string)($goal['created_at']??gmdate('c')),'last_seen_at'=>(string)($goal['last_seen_at']??gmdate('c')),'kind'=>'goal_layer','source'=>(string)($goal['source']??'system_experience'),'source_ref'=>(string)($goal['source_ref']??''),'priority'=>(string)($goal['priority']??'medium'),'title'=>(string)($goal['title']??''),'evidence'=>(string)($goal['evidence']??''),'hypothesis'=>(string)($goal['description']??''),'suggested_action'=>(string)($goal['success_criteria']??($goal['description']??''))];
    if($idx===null)$rows[]=$entry;else $rows[$idx]=array_replace($rows[$idx],$entry);
    kicomEvolutionAtomicJson(kicomEvolutionGoalsFile(),['schema'=>1,'updated_at'=>gmdate('c'),'goals'=>$rows]);
}
function kicomGoalUpsert(string $source,string $title,string $description,string $priority='medium',string $evidence='',string $successCriteria='',string $sourceRef='',bool $mirror=true): array {
    if(!kicomGoalMemoryEnsure())return ['ok'=>false,'code'=>'STORAGE_UNAVAILABLE'];
    $source=kicomGoalSource($source);$title=kicomGoalText($title,180);$description=kicomGoalText($description,1200);$priority=kicomGoalPriority($priority);$evidence=kicomGoalText($evidence,1200);$successCriteria=kicomGoalText($successCriteria,1200);$sourceRef=kicomGoalText($sourceRef,240);
    if($title===''||$description==='')return ['ok'=>false,'code'=>'GOAL_TEXT_REQUIRED'];
    $id=kicomGoalId($source,$sourceRef,$title,$description);$store=kicomGoalRegistryRaw();$idx=null;
    foreach($store['goals'] as $i=>$g)if(is_array($g)&&hash_equals((string)($g['id']??''),$id)){$idx=$i;break;}
    $now=gmdate('c');$created=$idx===null;$goal=$created?['id'=>$id,'created_at'=>$now,'status'=>'open']:($store['goals'][$idx]??[]);
    $goal=array_replace($goal,['id'=>$id,'last_seen_at'=>$now,'source'=>$source,'source_ref'=>$sourceRef,'priority'=>$priority,'title'=>$title,'description'=>$description,'evidence'=>$evidence,'success_criteria'=>$successCriteria]);
    if(!in_array((string)($goal['status']??'open'),['open','achieved','archived','superseded'],true))$goal['status']='open';
    if($idx===null)$store['goals'][]=$goal;else $store['goals'][$idx]=$goal;
    if(!kicomGoalRegistrySave($store))return ['ok'=>false,'code'=>'GOAL_WRITE_FAILED'];
    kicomGoalAppendEvent(kicomGoalEventsFile(),['type'=>$created?'goal_created':'goal_seen','goal_id'=>$id,'source'=>$source,'priority'=>$priority]);
    if($mirror)kicomGoalMirrorToEvolution($goal);
    return ['ok'=>true,'code'=>$created?'CREATED':'UPDATED','goal'=>$goal];
}
function kicomGoalArchive(string $id,string $reason=''): array {
    $id=strtolower(trim($id));if(!preg_match('/^[a-f0-9]{24}$/',$id))return ['ok'=>false,'code'=>'GOAL_ID_INVALID'];$store=kicomGoalRegistryRaw();$idx=null;
    foreach($store['goals'] as $i=>$g)if(is_array($g)&&hash_equals((string)($g['id']??''),$id)){$idx=$i;break;}if($idx===null)return ['ok'=>false,'code'=>'GOAL_NOT_FOUND'];
    $store['goals'][$idx]['status']='archived';$store['goals'][$idx]['archived_at']=gmdate('c');$store['goals'][$idx]['archive_reason']=kicomGoalText($reason,500);
    if(!kicomGoalRegistrySave($store))return ['ok'=>false,'code'=>'GOAL_WRITE_FAILED'];kicomGoalAppendEvent(kicomGoalEventsFile(),['type'=>'goal_archived','goal_id'=>$id,'reason'=>$reason]);kicomGoalMirrorToEvolution($store['goals'][$idx]);return ['ok'=>true,'goal'=>$store['goals'][$idx]];
}
function kicomGoalSupersede(string $id,string $replacementId,string $reason=''): array {
    $id=strtolower(trim($id));$replacementId=strtolower(trim($replacementId));if(!preg_match('/^[a-f0-9]{24}$/',$id)||!preg_match('/^[a-f0-9]{24}$/',$replacementId))return ['ok'=>false,'code'=>'GOAL_ID_INVALID'];$store=kicomGoalRegistryRaw();$idx=null;
    foreach($store['goals'] as $i=>$g)if(is_array($g)&&hash_equals((string)($g['id']??''),$id)){$idx=$i;break;}if($idx===null)return ['ok'=>false,'code'=>'GOAL_NOT_FOUND'];
    $store['goals'][$idx]['status']='superseded';$store['goals'][$idx]['superseded_at']=gmdate('c');$store['goals'][$idx]['superseded_by']=$replacementId;$store['goals'][$idx]['supersede_reason']=kicomGoalText($reason,500);
    if(!kicomGoalRegistrySave($store))return ['ok'=>false,'code'=>'GOAL_WRITE_FAILED'];kicomGoalAppendEvent(kicomGoalEventsFile(),['type'=>'goal_superseded','goal_id'=>$id,'replacement_id'=>$replacementId,'reason'=>$reason]);kicomGoalMirrorToEvolution($store['goals'][$idx]);return ['ok'=>true,'goal'=>$store['goals'][$idx]];
}
function kicomGoalSyncSystemExperience(): void {
    static $busy=false;if($busy)return;$busy=true;
    try{
        $e=kicomEvolutionGoals();foreach(($e['goals']??[]) as $g){if(!is_array($g))continue;$existingSource=strtolower((string)($g['source']??''));if($existingSource!==''&&$existingSource!=='system_experience')continue;$legacy=(string)($g['id']??'');
            kicomGoalUpsert('system_experience',(string)($g['title']??($g['kind']??'System experience goal')),(string)($g['hypothesis']??($g['suggested_action']??'Observed system improvement goal')),(string)($g['priority']??'medium'),(string)($g['evidence']??''),(string)($g['suggested_action']??''),'evolution:'.$legacy,false);
        }
    } finally {$busy=false;}
}
function kicomGoals(bool $sync=true): array { if($sync)kicomGoalSyncSystemExperience();return kicomGoalRegistryRaw(); }
function kicomOpenGoals(): array { $s=kicomGoals(true);return array_values(array_filter($s['goals']??[],fn($g)=>is_array($g)&&($g['status']??'open')==='open')); }

function kicomMemoryArchiveSnapshotId(string $sha): string { return gmdate('Ymd\THis\Z').'-'.substr($sha,0,16); }
function kicomMemoryArchiveSnapshotStore(string $raw,string $source='chatgpt_memory',string $label=''): array {
    if(!kicomGoalMemoryEnsure())return ['ok'=>false,'code'=>'STORAGE_UNAVAILABLE'];if(strlen($raw)>KICOM_MAX_MEMORY_ARCHIVE_SNAPSHOT_BYTES)return ['ok'=>false,'code'=>'SNAPSHOT_TOO_LARGE'];
    $payload=json_decode($raw,true);if(!is_array($payload))return ['ok'=>false,'code'=>'SNAPSHOT_JSON_INVALID'];$source=kicomGoalSource($source);$label=kicomGoalText($label,160);$sha=hash('sha256',$raw);
    foreach(glob(kicomMemoryArchiveSnapshotsDir().'/*.json')?:[] as $f){$r=json_decode((string)@file_get_contents($f),true);if(is_array($r)&&hash_equals((string)($r['payload_sha256']??''),$sha))return ['ok'=>true,'code'=>'ALREADY_ARCHIVED','snapshot'=>$r];}
    $id=kicomMemoryArchiveSnapshotId($sha);$goalIds=[];$hyp=$payload['goal_hypotheses']??[];if(is_array($hyp))foreach($hyp as $g)if(is_array($g)){
        $title=(string)($g['title']??'');$desc=(string)($g['description']??'');if(trim($title)===''||trim($desc)==='')continue;$goalIds[]=kicomGoalId('chatgpt_memory','snapshot:'.$id.':'.count($goalIds),$title,$desc);
    }
    $row=['schema'=>1,'id'=>$id,'created_at'=>gmdate('c'),'source'=>$source,'label'=>$label,'kind'=>(string)($payload['kind']??'full_snapshot'),'parent_sha256'=>(string)($payload['parent_sha256']??''),'payload_sha256'=>$sha,'payload_bytes'=>strlen($raw),'goal_ids'=>$goalIds,'payload'=>$payload];
    $file=kicomMemoryArchiveSnapshotsDir().'/'.$id.'.json';$json=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false||@file_put_contents($file,$json."\n",LOCK_EX)===false)return ['ok'=>false,'code'=>'SNAPSHOT_WRITE_FAILED'];@chmod($file,0600);
    kicomGoalAppendEvent(kicomMemoryArchiveEventsFile(),['type'=>'snapshot_archived','snapshot_id'=>$id,'source'=>$source,'payload_sha256'=>$sha,'bytes'=>strlen($raw)]);
    $ingested=[];if(is_array($hyp))foreach($hyp as $i=>$g)if(is_array($g)){
        $r=kicomGoalUpsert('chatgpt_memory',(string)($g['title']??''),(string)($g['description']??''),(string)($g['priority']??'medium'),(string)($g['evidence']??''),(string)($g['success_criteria']??''),'snapshot:'.$id.':'.$i,true);if(!empty($r['ok']))$ingested[]=(string)($r['goal']['id']??'');
    }
    return ['ok'=>true,'code'=>'ARCHIVED','snapshot'=>$row,'goals_ingested'=>$ingested];
}
function kicomMemoryArchiveList(int $limit=50): array {
    kicomGoalMemoryEnsure();$files=glob(kicomMemoryArchiveSnapshotsDir().'/*.json')?:[];rsort($files,SORT_STRING);$rows=[];
    foreach(array_slice($files,0,max(1,min(200,$limit))) as $f){$r=json_decode((string)@file_get_contents($f),true);if(!is_array($r))continue;unset($r['payload']);$rows[]=$r;}return $rows;
}
function kicomMemoryArchiveStatus(): array {
    kicomGoalMemoryEnsure();$files=glob(kicomMemoryArchiveSnapshotsDir().'/*.json')?:[];$bytes=0;foreach($files as $f)$bytes+=(int)@filesize($f);$list=kicomMemoryArchiveList(1);return ['policy'=>'archive-never-hard-delete','count'=>count($files),'bytes'=>$bytes,'latest'=>$list[0]??null];
}

function kicomMemoryArchiveUploadCleanup(): void {
    kicomGoalMemoryEnsure();$now=time();foreach(glob(kicomMemoryArchiveUploadsDir().'/*',GLOB_ONLYDIR)?:[] as $d){$m=json_decode((string)@file_get_contents($d.'/meta.json'),true);if(!is_array($m)||(int)($m['expires_at']??0)<$now){foreach(glob($d.'/*')?:[] as $f)if(is_file($f))@unlink($f);@rmdir($d);}}
}
function kicomMemoryArchiveUploadBegin(string $label): array {
    kicomMemoryArchiveUploadCleanup();$dirs=glob(kicomMemoryArchiveUploadsDir().'/*',GLOB_ONLYDIR)?:[];if(count($dirs)>=3)return ['ok'=>false,'code'=>'UPLOAD_SESSION_LIMIT'];
    try{$id=bin2hex(random_bytes(8));$token=bin2hex(random_bytes(24));}catch(Throwable $e){$id=substr(hash('sha256',uniqid('',true)),0,16);$token=hash('sha256',uniqid('',true));}
    $dir=kicomMemoryArchiveUploadsDir().'/'.$id;if(!@mkdir($dir,0700,true)&&!is_dir($dir))return ['ok'=>false,'code'=>'UPLOAD_SESSION_CREATE_FAILED'];$meta=['id'=>$id,'token_hash'=>hash('sha256',$token),'created_at'=>gmdate('c'),'expires_at'=>time()+900,'label'=>kicomGoalText($label,160),'next_seq'=>0,'bytes'=>0];
    if(!kicomEvolutionAtomicJson($dir.'/meta.json',$meta))return ['ok'=>false,'code'=>'UPLOAD_META_WRITE_FAILED'];return ['ok'=>true,'upload_id'=>$id,'token'=>$token,'expires_in'=>900,'max_chunk_bytes'=>3000,'max_bytes'=>KICOM_MAX_MEMORY_ARCHIVE_SNAPSHOT_BYTES];
}
function kicomMemoryArchiveUploadMeta(string $id,string $token): array {
    if(!preg_match('/^[a-f0-9]{16}$/',$id)||!preg_match('/^[a-f0-9]{48,64}$/',$token))return ['ok'=>false,'code'=>'UPLOAD_AUTH_INVALID'];$dir=kicomMemoryArchiveUploadsDir().'/'.$id;$m=json_decode((string)@file_get_contents($dir.'/meta.json'),true);if(!is_array($m)||(int)($m['expires_at']??0)<time())return ['ok'=>false,'code'=>'UPLOAD_SESSION_EXPIRED'];if(!hash_equals((string)($m['token_hash']??''),hash('sha256',$token)))return ['ok'=>false,'code'=>'UPLOAD_AUTH_INVALID'];return ['ok'=>true,'dir'=>$dir,'meta'=>$m];
}
function kicomMemoryArchiveUploadChunk(string $id,string $token,int $seq,string $encoded): array {
    $x=kicomMemoryArchiveUploadMeta($id,$token);if(!$x['ok'])return $x;$m=$x['meta'];if($seq!==(int)($m['next_seq']??0)||$seq<0||$seq>=100)return ['ok'=>false,'code'=>'UPLOAD_SEQUENCE_INVALID'];$raw=kicomDecodeBase64Url($encoded,true);if($raw===null||strlen($raw)>3000)return ['ok'=>false,'code'=>'UPLOAD_CHUNK_INVALID'];$newBytes=(int)($m['bytes']??0)+strlen($raw);if($newBytes>KICOM_MAX_MEMORY_ARCHIVE_SNAPSHOT_BYTES)return ['ok'=>false,'code'=>'SNAPSHOT_TOO_LARGE'];$f=$x['dir'].'/'.sprintf('%03d.part',$seq);if(@file_put_contents($f,$raw,LOCK_EX)===false)return ['ok'=>false,'code'=>'UPLOAD_CHUNK_WRITE_FAILED'];$m['next_seq']=$seq+1;$m['bytes']=$newBytes;$m['expires_at']=time()+900;kicomEvolutionAtomicJson($x['dir'].'/meta.json',$m);return ['ok'=>true,'next_seq'=>$m['next_seq'],'bytes'=>$newBytes];
}
function kicomMemoryArchiveUploadFinish(string $id,string $token): array {
    $x=kicomMemoryArchiveUploadMeta($id,$token);if(!$x['ok'])return $x;$m=$x['meta'];$parts=glob($x['dir'].'/*.part')?:[];sort($parts,SORT_STRING);if(count($parts)!==(int)($m['next_seq']??0)||!$parts)return ['ok'=>false,'code'=>'UPLOAD_INCOMPLETE'];$raw='';foreach($parts as $p){$c=@file_get_contents($p);if($c===false)return ['ok'=>false,'code'=>'UPLOAD_READ_FAILED'];$raw.=$c;}if(strlen($raw)!==(int)($m['bytes']??-1))return ['ok'=>false,'code'=>'UPLOAD_SIZE_MISMATCH'];if(!is_array(json_decode($raw,true)))return ['ok'=>false,'code'=>'SNAPSHOT_JSON_INVALID'];
    $pr=kicomCreateProposal(['kind'=>'memory_archive_snapshot','resource'=>'CHATGPT_MEMORY_ARCHIVE','bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'content_b64'=>base64_encode($raw),'label'=>(string)($m['label']??''),'source'=>'chatgpt_memory','validation'=>['status'=>'ok','message'=>'SNAPSHOT_JSON_VALID'],'transport'=>'KCL_CHUNKED_GET']);if(!$pr['ok'])return ['ok'=>false,'code'=>(string)$pr['code']];foreach(glob($x['dir'].'/*')?:[] as $f)if(is_file($f))@unlink($f);@rmdir($x['dir']);return ['ok'=>true,'code'=>'PROPOSAL_READY','proposal_id'=>$pr['id'],'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw)];
}
