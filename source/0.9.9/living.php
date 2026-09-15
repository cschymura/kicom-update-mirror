<?php
declare(strict_types=1);

/* KiCom Living Architecture 0.9.9 */
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
    $requiredInvariants=['no-arbitrary-shell','no-arbitrary-sql','no-arbitrary-remote-fetch','human-gated-new-capabilities','separate-genome-memory-phenotype','autonomous-heal-trusted-state-only','production-writes-human-approved','recovery-kernel-not-auto-healed','unknown-code-quarantine-before-use','goal-layer-no-permission-grants','memory-archive-no-hard-delete','autonomy-envelope-bounded','critical-actions-totp-bound','release-archive-append-only'];
    $inv=array_values(array_filter($g['invariants']??[],'is_string'));foreach($requiredInvariants as $i)if(!in_array($i,$inv,true))return ['ok'=>false,'code'=>'GENOME_INVARIANT_MISSING','invariant'=>$i];
    $health=array_values(array_filter($g['healthchecks']??[],'is_string'));foreach(['HELLO','BOOTSTRAP','LIVING_STATUS'] as $h)if(!in_array($h,$health,true))return ['ok'=>false,'code'=>'GENOME_HEALTHCHECK_MISSING','healthcheck'=>$h];
    $seen=[];foreach($g['components'] as $c){if(!is_array($c))return ['ok'=>false,'code'=>'GENOME_COMPONENT_INVALID'];$p=kicomSafeUpdatePath((string)($c['path']??''));$sha=strtolower((string)($c['sha256']??''));if($p===null||isset($seen[$p])||!preg_match('/^[a-f0-9]{64}$/',$sha))return ['ok'=>false,'code'=>'GENOME_COMPONENT_INVALID'];$seen[$p]=true;if($packageEntries&&(!isset($packageEntries[$p])||!hash_equals($sha,(string)($packageEntries[$p]['sha256']??''))))return ['ok'=>false,'code'=>'GENOME_COMPONENT_HASH_MISMATCH','path'=>$p];}
    foreach(['lib.php','index.php','api.php','admin.php','living.php','recovery.php','guardian.php','.htaccess','robots.txt','genome/.htaccess','memory/.htaccess','stage/.htaccess','var/.htaccess'] as $p)if(!isset($seen[$p]))return ['ok'=>false,'code'=>'GENOME_REQUIRED_COMPONENT_MISSING','path'=>$p];
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

/* KiCom 0.9.6 Autonomy Envelope + Human Authorization Bridge.
   Runtime authority remains code-defined. TOTP never grants arbitrary shell/SQL/filesystem access. */

function kicomAuthDir(): string { return kicomVarDir().'/auth'; }
function kicomAuthSessionsDir(): string { return kicomAuthDir().'/sessions'; }
function kicomAuthApprovalsDir(): string { return kicomAuthDir().'/approvals'; }
function kicomAuthUploadsDir(): string { return kicomAuthDir().'/uploads'; }
function kicomAuthReadLeasesDir(): string { return kicomAuthDir().'/read_leases'; }
function kicomTotpFile(): string { return kicomAuthDir().'/totp.json'; }
function kicomTotpPendingFile(): string { return kicomAuthDir().'/totp_pending.json'; }
function kicomAuthRateFile(): string { return kicomAuthDir().'/rate.json'; }
function kicomAutonomyPolicyFile(): string { return kicomAuthDir().'/autonomy.json'; }

function kicomAuthEnsure(): bool {
    foreach([kicomAuthDir(),kicomAuthSessionsDir(),kicomAuthApprovalsDir(),kicomAuthUploadsDir(),kicomAuthReadLeasesDir()] as $d){
        if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;
        $deny=$d.'/.htaccess';if(!is_file($deny))@file_put_contents($deny,kicomDenyRules(),LOCK_EX);
    }
    return true;
}
function kicomAuthJsonRead(string $file): ?array {
    if(!is_file($file))return null;$r=json_decode((string)@file_get_contents($file),true);return is_array($r)?$r:null;
}
function kicomAuthJsonWrite(string $file,array $row): bool {
    if(!kicomAuthEnsure())return false;$j=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($j===false)return false;
    $tmp=$file.'.tmp-'.strtolower(kicomRequestId());if(@file_put_contents($tmp,$j."\n",LOCK_EX)===false)return false;@chmod($tmp,0600);
    if(!@rename($tmp,$file)){@unlink($tmp);return false;}@chmod($file,0600);return true;
}
function kicomAuthRandomHex(int $bytes): string { try{return bin2hex(random_bytes($bytes));}catch(Throwable $e){return hash('sha256',uniqid('',true).microtime(true));} }

function kicomBase32Encode(string $raw): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';$out='';
    foreach(str_split($raw) as $c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);
    foreach(str_split($bits,5) as $chunk){if(strlen($chunk)<5)$chunk=str_pad($chunk,5,'0',STR_PAD_RIGHT);$out.=$alphabet[bindec($chunk)];}
    return $out;
}
function kicomBase32Decode(string $s): ?string {
    $s=strtoupper(preg_replace('/[^A-Z2-7]/','',$s)??'');if($s==='')return null;$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';
    foreach(str_split($s) as $c){$p=strpos($alphabet,$c);if($p===false)return null;$bits.=str_pad(decbin($p),5,'0',STR_PAD_LEFT);}
    $out='';foreach(str_split($bits,8) as $chunk){if(strlen($chunk)<8)break;$out.=chr(bindec($chunk));}return $out;
}
function kicomTotpCodeForCounter(string $secret,int $counter,int $digits=6): ?string {
    $key=kicomBase32Decode($secret);if($key===null)return null;
    $hi=intdiv($counter,4294967296);$lo=$counter%4294967296;$bin=pack('N2',$hi,$lo);$h=hash_hmac('sha1',$bin,$key,true);$off=ord($h[19])&15;
    $n=((ord($h[$off])&127)<<24)|(ord($h[$off+1])<<16)|(ord($h[$off+2])<<8)|ord($h[$off+3]);$mod=10**$digits;
    return str_pad((string)($n%$mod),$digits,'0',STR_PAD_LEFT);
}
function kicomTotpConfig(): ?array {
    $r=kicomAuthJsonRead(kicomTotpFile());if(!is_array($r)||empty($r['enabled'])||!is_string($r['secret']??null))return null;return $r;
}
function kicomTotpPending(): ?array {
    $r=kicomAuthJsonRead(kicomTotpPendingFile());if(!is_array($r))return null;if((int)($r['expires_at']??0)<time()){@unlink(kicomTotpPendingFile());return null;}return $r;
}
function kicomTotpSetupBegin(string $account='Christoph'): array {
    if(!kicomAuthEnsure())return ['ok'=>false,'code'=>'AUTH_STORAGE_UNAVAILABLE'];
    $secret=kicomBase32Encode(hex2bin(kicomAuthRandomHex(20))?:random_bytes(20));$issuer='KiCom';$label=$issuer.':'.$account;
    $uri='otpauth://totp/'.rawurlencode($label).'?secret='.rawurlencode($secret).'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    $row=['schema'=>1,'secret'=>$secret,'issuer'=>$issuer,'account'=>$account,'digits'=>6,'period'=>30,'algorithm'=>'SHA1','created_at'=>gmdate('c'),'expires_at'=>time()+900,'uri'=>$uri];
    if(!kicomAuthJsonWrite(kicomTotpPendingFile(),$row))return ['ok'=>false,'code'=>'TOTP_PENDING_WRITE_FAILED'];return ['ok'=>true]+$row;
}
function kicomTotpSetupConfirm(string $code): array {
    $p=kicomTotpPending();if($p===null)return ['ok'=>false,'code'=>'TOTP_SETUP_NOT_PENDING'];$code=preg_replace('/\D/','',$code)??'';if(strlen($code)!==6)return ['ok'=>false,'code'=>'TOTP_CODE_INVALID'];
    $counter=intdiv(time(),30);$expected=kicomTotpCodeForCounter((string)$p['secret'],$counter,6);if($expected===null||!hash_equals($expected,$code))return ['ok'=>false,'code'=>'TOTP_CODE_REJECTED'];
    $row=['schema'=>1,'enabled'=>true,'secret'=>$p['secret'],'issuer'=>$p['issuer'],'account'=>$p['account'],'digits'=>6,'period'=>30,'algorithm'=>'SHA1','confirmed_at'=>gmdate('c'),'last_counter'=>$counter];
    if(!kicomAuthJsonWrite(kicomTotpFile(),$row))return ['ok'=>false,'code'=>'TOTP_CONFIG_WRITE_FAILED'];@unlink(kicomTotpPendingFile());kicomLivingEvent('totp_enrolled','info');return ['ok'=>true,'code'=>'TOTP_ENABLED'];
}
function kicomTotpRateAllowed(bool $success=false): bool {
    $now=time();$r=kicomAuthJsonRead(kicomAuthRateFile())??['attempts'=>[]];$a=array_values(array_filter($r['attempts']??[],fn($t)=>(int)$t>$now-300));
    if($success)$a=[];else $a[]=$now;kicomAuthJsonWrite(kicomAuthRateFile(),['attempts'=>$a,'updated_at'=>gmdate('c')]);return count($a)<=5;
}
function kicomTotpVerifyConsume(string $code,string $purpose): array {
    $cfg=kicomTotpConfig();if($cfg===null)return ['ok'=>false,'code'=>'TOTP_NOT_CONFIGURED'];if(!kicomTotpRateAllowed(false))return ['ok'=>false,'code'=>'TOTP_RATE_LIMIT'];
    $code=preg_replace('/\D/','',$code)??'';if(strlen($code)!==6)return ['ok'=>false,'code'=>'TOTP_CODE_INVALID'];$period=(int)($cfg['period']??30);$counter=intdiv(time(),max(1,$period));
    if($counter<=(int)($cfg['last_counter']??-1))return ['ok'=>false,'code'=>'TOTP_REPLAY'];$expected=kicomTotpCodeForCounter((string)$cfg['secret'],$counter,(int)($cfg['digits']??6));
    if($expected===null||!hash_equals($expected,$code))return ['ok'=>false,'code'=>'TOTP_CODE_REJECTED'];$cfg['last_counter']=$counter;$cfg['last_used_at']=gmdate('c');$cfg['last_purpose']=substr($purpose,0,120);
    if(!kicomAuthJsonWrite(kicomTotpFile(),$cfg))return ['ok'=>false,'code'=>'TOTP_STATE_WRITE_FAILED'];kicomTotpRateAllowed(true);return ['ok'=>true,'counter'=>$counter];
}
function kicomTotpPublicStatus(): array {
    $cfg=kicomTotpConfig();$p=kicomTotpPending();
    /* A confirmed configuration is authoritative; stale pending enrollment state is housekeeping only. */
    if($cfg!==null&&$p!==null){@unlink(kicomTotpPendingFile());$p=null;}
    return ['configured'=>$cfg!==null,'pending'=>$p!==null,'digits'=>6,'period'=>30,'algorithm'=>'SHA1'];
}

function kicomAutonomyPolicy(): array {
    $defaults=['schema'=>1,'enabled'=>true,'session_ttl'=>28800,'idle_ttl'=>1800,'auto_workspace'=>true,'auto_test'=>true,'auto_staging'=>true,'auto_memory'=>true,'auto_goal'=>true,'auto_memory_archive'=>true,'auto_yellow_from_session'=>true,'red_requires_totp'=>true,'production_requires_totp'=>true,'kernel_requires_totp'=>true,'archive_append_only'=>true];
    $r=kicomAuthJsonRead(kicomAutonomyPolicyFile());if(!is_array($r)){$r=$defaults;kicomAuthJsonWrite(kicomAutonomyPolicyFile(),$r);}return array_replace($defaults,$r);
}
function kicomAutonomySetEnabled(bool $enabled): bool {$p=kicomAutonomyPolicy();$p['enabled']=$enabled;$p['updated_at']=gmdate('c');$ok=kicomAuthJsonWrite(kicomAutonomyPolicyFile(),$p);if($ok)kicomLivingEvent($enabled?'autonomy_enabled':'autonomy_disabled','warn');return $ok;}
function kicomAutonomyRevokeSessions(): void {foreach(glob(kicomAuthSessionsDir().'/*.json')?:[] as $f)@unlink($f);foreach(glob(kicomAuthReadLeasesDir().'/*.json')?:[] as $f)@unlink($f);kicomLivingEvent('autonomy_sessions_revoked','warn');}
function kicomAutonomySessionFile(string $id): string {return kicomAuthSessionsDir().'/'.$id.'.json';}
function kicomAutonomySessionOpen(string $code): array {
    $policy=kicomAutonomyPolicy();if(empty($policy['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];$v=kicomTotpVerifyConsume($code,'autonomy_session_open');if(!$v['ok'])return $v;
    $id=substr(kicomAuthRandomHex(12),0,24);$token=kicomAuthRandomHex(32);$now=time();$row=['schema'=>1,'id'=>$id,'token_hash'=>hash('sha256',$token),'created_at'=>gmdate('c'),'created_epoch'=>$now,'last_used_at'=>gmdate('c'),'absolute_expires_at'=>$now+(int)$policy['session_ttl'],'idle_expires_at'=>$now+(int)$policy['idle_ttl'],'scope'=>'autonomy'];
    if(!kicomAuthJsonWrite(kicomAutonomySessionFile($id),$row))return ['ok'=>false,'code'=>'SESSION_WRITE_FAILED'];kicomLivingEvent('autonomy_session_opened','info',['session_id'=>$id]);return ['ok'=>true,'session_id'=>$id,'token'=>$token,'expires_in'=>(int)$policy['session_ttl'],'idle_expires_in'=>(int)$policy['idle_ttl']];
}
function kicomAutonomySessionConsume(string $id,string $token): array {
    $policy=kicomAutonomyPolicy();if(empty($policy['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];$id=strtolower(trim($id));$token=strtolower(trim($token));if(!preg_match('/^[a-f0-9]{24}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$token))return ['ok'=>false,'code'=>'SESSION_AUTH_INVALID'];
    $file=kicomAutonomySessionFile($id);$r=kicomAuthJsonRead($file);if(!is_array($r))return ['ok'=>false,'code'=>'SESSION_NOT_FOUND'];$now=time();
    if((int)($r['absolute_expires_at']??0)<$now||(int)($r['idle_expires_at']??0)<$now){@unlink($file);return ['ok'=>false,'code'=>'SESSION_EXPIRED'];}
    $present=hash('sha256',$token);$current=(string)($r['token_hash']??'');$previous=(string)($r['previous_token_hash']??'');$previousUntil=(int)($r['previous_token_until']??0);
    $normal=$current!==''&&hash_equals($current,$present);$recover=!$normal&&$previous!==''&&$previousUntil>=$now&&hash_equals($previous,$present);
    if(!$normal&&!$recover)return ['ok'=>false,'code'=>'SESSION_TOKEN_REJECTED'];
    $next=kicomAuthRandomHex(32);
    if($normal){$r['previous_token_hash']=$current;$r['previous_token_until']=$now+60;}else{$r['previous_token_hash']='';$r['previous_token_until']=0;$r['recovered_at']=gmdate('c');}
    $r['token_hash']=hash('sha256',$next);$r['last_used_at']=gmdate('c');$r['idle_expires_at']=min((int)$r['absolute_expires_at'],$now+(int)$policy['idle_ttl']);
    if(!kicomAuthJsonWrite($file,$r))return ['ok'=>false,'code'=>'SESSION_ROTATE_FAILED'];
    return ['ok'=>true,'session_id'=>$id,'next_token'=>$next,'expires_at'=>(int)$r['absolute_expires_at'],'idle_expires_at'=>(int)$r['idle_expires_at'],'recovered'=>$recover];
}
function kicomAutonomySessionCount(): int {$n=0;$now=time();foreach(glob(kicomAuthSessionsDir().'/*.json')?:[] as $f){$r=kicomAuthJsonRead($f);if(is_array($r)&&(int)($r['absolute_expires_at']??0)>$now&&(int)($r['idle_expires_at']??0)>$now)$n++;}return $n;}
function kicomAuthReadLeaseFile(string $id): string {return kicomAuthReadLeasesDir().'/'.$id.'.json';}
function kicomAuthReadLeaseCleanup(): void {
    if(!kicomAuthEnsure())return;$now=time();foreach(glob(kicomAuthReadLeasesDir().'/*.json')?:[] as $f){$r=kicomAuthJsonRead($f);if(!is_array($r)||(int)($r['expires_at']??0)<$now)@unlink($f);}
}
function kicomAutonomyReadLeaseOpen(string $sessionId): array {
    if(!kicomAuthEnsure())return ['ok'=>false,'code'=>'AUTH_STORAGE_UNAVAILABLE'];$sessionId=strtolower(trim($sessionId));$sf=kicomAutonomySessionFile($sessionId);$s=kicomAuthJsonRead($sf);$now=time();if(!is_array($s))return ['ok'=>false,'code'=>'SESSION_NOT_FOUND'];$abs=(int)($s['absolute_expires_at']??0);$idle=(int)($s['idle_expires_at']??0);if($abs<$now||$idle<$now)return ['ok'=>false,'code'=>'SESSION_EXPIRED'];kicomAuthReadLeaseCleanup();$id=substr(kicomAuthRandomHex(10),0,20);$token=kicomAuthRandomHex(32);$expires=min($abs,$idle,$now+600);$row=['schema'=>1,'id'=>$id,'session_id'=>$sessionId,'token_hash'=>hash('sha256',$token),'created_at'=>gmdate('c'),'expires_at'=>$expires,'uses'=>0,'max_uses'=>64,'scope'=>'trusted_source_read'];if(!kicomAuthJsonWrite(kicomAuthReadLeaseFile($id),$row))return ['ok'=>false,'code'=>'READ_LEASE_WRITE_FAILED'];return ['ok'=>true,'lease_id'=>$id,'lease_token'=>$token,'expires_in'=>max(0,$expires-$now),'max_uses'=>64,'scope'=>'trusted_source_read'];
}
function kicomAutonomyReadLeaseCheck(string $id,string $token): array {
    $id=strtolower(trim($id));$token=strtolower(trim($token));if(!preg_match('/^[a-f0-9]{20}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$token))return ['ok'=>false,'code'=>'READ_LEASE_INVALID'];$f=kicomAuthReadLeaseFile($id);$r=kicomAuthJsonRead($f);$now=time();if(!is_array($r))return ['ok'=>false,'code'=>'READ_LEASE_NOT_FOUND'];if((int)($r['expires_at']??0)<$now){@unlink($f);return ['ok'=>false,'code'=>'READ_LEASE_EXPIRED'];}$s=kicomAuthJsonRead(kicomAutonomySessionFile((string)($r['session_id']??'')));if(!is_array($s)||(int)($s['absolute_expires_at']??0)<$now||(int)($s['idle_expires_at']??0)<$now){@unlink($f);return ['ok'=>false,'code'=>'READ_LEASE_SESSION_EXPIRED'];}if(!hash_equals((string)($r['token_hash']??''),hash('sha256',$token)))return ['ok'=>false,'code'=>'READ_LEASE_REJECTED'];$uses=(int)($r['uses']??0);$max=(int)($r['max_uses']??64);if($uses>=$max){@unlink($f);return ['ok'=>false,'code'=>'READ_LEASE_EXHAUSTED'];}$r['uses']=$uses+1;$r['last_used_at']=gmdate('c');if(!kicomAuthJsonWrite($f,$r))return ['ok'=>false,'code'=>'READ_LEASE_WRITE_FAILED'];return ['ok'=>true,'lease_id'=>$id,'expires_at'=>(int)$r['expires_at'],'uses_left'=>max(0,$max-(int)$r['uses']),'scope'=>'trusted_source_read'];
}


function kicomAuthApprovalFile(string $id): string {return kicomAuthApprovalsDir().'/'.$id.'.json';}
function kicomAuthApprovalCreate(string $sessionId,string $action,array $binding,array $payload,string $risk): array {
    $id=substr(kicomAuthRandomHex(12),0,24);$digest=hash('sha256',json_encode([$action,$binding],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'');$row=['schema'=>1,'id'=>$id,'session_id'=>$sessionId,'action'=>$action,'risk'=>$risk,'binding'=>$binding,'binding_sha256'=>$digest,'payload'=>$payload,'created_at'=>gmdate('c'),'expires_at'=>time()+120,'status'=>'pending'];
    if(!kicomAuthJsonWrite(kicomAuthApprovalFile($id),$row))return ['ok'=>false,'code'=>'APPROVAL_WRITE_FAILED'];kicomLivingEvent('totp_approval_prepared','info',['approval_id'=>$id,'action'=>$action,'risk'=>$risk,'binding_sha256'=>$digest]);return ['ok'=>true,'approval_id'=>$id,'binding_sha256'=>$digest,'risk'=>$risk,'expires_in'=>120,'binding'=>$binding];
}
function kicomAuthApprovalGet(string $id): ?array {$id=strtolower(trim($id));if(!preg_match('/^[a-f0-9]{24}$/',$id))return null;return kicomAuthJsonRead(kicomAuthApprovalFile($id));}
function kicomAuthApprovalExecute(string $id,string $code): array {
    $row=kicomAuthApprovalGet($id);if(!is_array($row))return ['ok'=>false,'code'=>'APPROVAL_NOT_FOUND'];if(($row['status']??'')!=='pending')return ['ok'=>false,'code'=>'APPROVAL_NOT_PENDING'];if((int)($row['expires_at']??0)<time()){$row['status']='expired';kicomAuthJsonWrite(kicomAuthApprovalFile($id),$row);return ['ok'=>false,'code'=>'APPROVAL_EXPIRED'];}
    $v=kicomTotpVerifyConsume($code,'approval:'.$id.':'.(string)$row['action']);if(!$v['ok'])return $v;$action=(string)$row['action'];$payload=is_array($row['payload']??null)?$row['payload']:[];$result=['ok'=>false,'code'=>'APPROVAL_ACTION_UNKNOWN'];
    if($action==='self_update_install'){
        $pending=kicomSelfUpdatePending();$b=$row['binding']??[];
        if(!is_array($pending)||!hash_equals((string)($b['to_version']??''),(string)($pending['to_version']??''))||!hash_equals((string)($b['package_sha256']??''),(string)($pending['zip_sha256']??'')))$result=['ok'=>false,'code'=>'APPROVAL_BINDING_CHANGED'];else $result=kicomApplySelfUpdate($pending);
    }elseif($action==='deploy_write')$result=kicomApplyDeployProposal($payload['proposal']??[]);
    elseif($action==='deploy_package')$result=kicomApplyDeployProposal($payload['proposal']??[]);
    elseif($action==='deploy_rollback')$result=kicomApplyDeployProposal($payload['proposal']??[]);
    elseif($action==='self_update_rollback')$result=kicomRollbackSelfUpdate((string)($payload['history_id']??''));
    $row['status']=!empty($result['ok'])?'used':'failed';$row['used_at']=gmdate('c');$row['result_code']=(string)($result['code']??(!empty($result['ok'])?'OK':'FAILED'));kicomAuthJsonWrite(kicomAuthApprovalFile($id),$row);kicomLivingEvent('totp_approval_executed',!empty($result['ok'])?'info':'warn',['approval_id'=>$id,'action'=>$action,'result'=>$row['result_code']]);return $result+['approval_id'=>$id,'binding_sha256'=>(string)$row['binding_sha256']];
}
function kicomAuthPrepareSelfUpdate(string $sessionId): array {
    $p=kicomSelfUpdatePending();if(!is_array($p))return ['ok'=>false,'code'=>'NO_PENDING_UPDATE'];$binding=['action'=>'self_update_install','from_version'=>(string)($p['from_version']??KICOM_VERSION),'to_version'=>(string)($p['to_version']??''),'package_sha256'=>(string)($p['zip_sha256']??''),'risk_class'=>(string)($p['risk_class']??'red'),'kernel_update'=>!empty($p['kernel_update'])];return kicomAuthApprovalCreate($sessionId,'self_update_install',$binding,[],'red');
}

function kicomAutonomyUploadDir(string $id): string {return kicomAuthUploadsDir().'/'.$id;}
function kicomAutonomyUploadCleanup(): void {$now=time();foreach(glob(kicomAuthUploadsDir().'/*',GLOB_ONLYDIR)?:[] as $d){$m=kicomAuthJsonRead($d.'/meta.json');if(!is_array($m)||(int)($m['expires_at']??0)<$now){foreach(glob($d.'/*')?:[] as $f)if(is_file($f))@unlink($f);@rmdir($d);}}}
function kicomAutonomyUploadBegin(string $sessionId,string $kind,array $meta): array {
    kicomAutonomyUploadCleanup();$allowed=['workspace','memory_snapshot','memory_resource','self_update'];if(!in_array($kind,$allowed,true))return ['ok'=>false,'code'=>'UPLOAD_KIND_INVALID'];$id=substr(kicomAuthRandomHex(10),0,20);$dir=kicomAutonomyUploadDir($id);if(!@mkdir($dir,0700,true)&&!is_dir($dir))return ['ok'=>false,'code'=>'UPLOAD_CREATE_FAILED'];
    $max=$kind==='self_update'?KICOM_MAX_SELF_UPDATE_ZIP_BYTES:($kind==='memory_snapshot'?KICOM_MAX_MEMORY_ARCHIVE_SNAPSHOT_BYTES:1048576);$row=['schema'=>1,'id'=>$id,'session_id'=>$sessionId,'kind'=>$kind,'meta'=>$meta,'created_at'=>gmdate('c'),'expires_at'=>time()+900,'next_seq'=>0,'bytes'=>0,'max_bytes'=>$max];if(!kicomAuthJsonWrite($dir.'/meta.json',$row))return ['ok'=>false,'code'=>'UPLOAD_META_FAILED'];return ['ok'=>true,'upload_id'=>$id,'max_chunk_bytes'=>3000,'max_bytes'=>$max,'expires_in'=>900];
}
function kicomAutonomyUploadMeta(string $sessionId,string $id): array {$id=strtolower(trim($id));if(!preg_match('/^[a-f0-9]{20}$/',$id))return ['ok'=>false,'code'=>'UPLOAD_ID_INVALID'];$dir=kicomAutonomyUploadDir($id);$m=kicomAuthJsonRead($dir.'/meta.json');if(!is_array($m)||!hash_equals((string)($m['session_id']??''),$sessionId))return ['ok'=>false,'code'=>'UPLOAD_NOT_FOUND'];if((int)($m['expires_at']??0)<time())return ['ok'=>false,'code'=>'UPLOAD_EXPIRED'];return ['ok'=>true,'dir'=>$dir,'meta'=>$m];}
function kicomAutonomyUploadChunk(string $sessionId,string $id,int $seq,string $encoded): array {
    $x=kicomAutonomyUploadMeta($sessionId,$id);if(!$x['ok'])return $x;$m=$x['meta'];if($seq!==(int)($m['next_seq']??0)||$seq<0||$seq>4096)return ['ok'=>false,'code'=>'UPLOAD_SEQUENCE_INVALID'];$raw=kicomDecodeBase64Url($encoded,true);if($raw===null||strlen($raw)>3000)return ['ok'=>false,'code'=>'UPLOAD_CHUNK_INVALID'];$bytes=(int)$m['bytes']+strlen($raw);if($bytes>(int)$m['max_bytes'])return ['ok'=>false,'code'=>'UPLOAD_TOO_LARGE'];$file=$x['dir'].'/'.sprintf('%05d.part',$seq);if(@file_put_contents($file,$raw,LOCK_EX)===false)return ['ok'=>false,'code'=>'UPLOAD_CHUNK_WRITE_FAILED'];$m['next_seq']=$seq+1;$m['bytes']=$bytes;$m['expires_at']=time()+900;kicomAuthJsonWrite($x['dir'].'/meta.json',$m);return ['ok'=>true,'next_seq'=>$m['next_seq'],'bytes'=>$bytes];
}
function kicomAutonomyUploadAssemble(array $x): array {$m=$x['meta'];$parts=glob($x['dir'].'/*.part')?:[];sort($parts,SORT_STRING);if(!$parts||count($parts)!==(int)($m['next_seq']??0))return ['ok'=>false,'code'=>'UPLOAD_INCOMPLETE'];$raw='';foreach($parts as $p){$c=@file_get_contents($p);if($c===false)return ['ok'=>false,'code'=>'UPLOAD_READ_FAILED'];$raw.=$c;}if(strlen($raw)!==(int)($m['bytes']??-1))return ['ok'=>false,'code'=>'UPLOAD_SIZE_MISMATCH'];return ['ok'=>true,'raw'=>$raw];}
function kicomAutonomyUploadDestroy(array $x): void {foreach(glob($x['dir'].'/*')?:[] as $f)if(is_file($f))@unlink($f);@rmdir($x['dir']);}
function kicomAutonomyUploadFinish(string $sessionId,string $id): array {
    $x=kicomAutonomyUploadMeta($sessionId,$id);if(!$x['ok'])return $x;$a=kicomAutonomyUploadAssemble($x);if(!$a['ok'])return $a;$m=$x['meta'];$raw=(string)$a['raw'];$kind=(string)$m['kind'];$meta=is_array($m['meta']??null)?$m['meta']:[];$result=['ok'=>false,'code'=>'UPLOAD_KIND_INVALID'];
    if($kind==='workspace'){
        $path=kicomSafeRelativePath((string)($meta['path']??''));$base=trim((string)($meta['base_sha256']??''));
        if($path===null)$result=['ok'=>false,'code'=>'INVALID_PATH'];
        else{$v=kicomValidateContent($path,$raw);if($v['status']==='error')$result=['ok'=>false,'code'=>'VALIDATION_FAILED','validation'=>$v['message']];else{$cur=kicomCurrentHash($path);if($cur==='NEW'){$base=strtoupper($base)==='NEW'||$base===''?'NEW':$base;$result=kicomAtomicStageWrite($path,$raw,'autonomy_write',$base);}elseif(!preg_match('/^[a-f0-9]{64}$/',strtolower($base)))$result=['ok'=>false,'code'=>'BASE_REQUIRED','current_sha256'=>$cur];else $result=kicomAtomicStageWrite($path,$raw,'autonomy_write',strtolower($base));}}
    }elseif($kind==='memory_snapshot'){$result=kicomMemoryArchiveSnapshotStore($raw,'chatgpt_memory',(string)($meta['label']??'ChatGPT memory snapshot'));}
    elseif($kind==='memory_resource'){$name=strtoupper((string)($meta['resource']??''));$base=strtolower((string)($meta['base_sha256']??''));$result=kicomAtomicMemoryWrite($name,$raw,'autonomy_write',$base);}
    elseif($kind==='self_update'){
        $tmp=kicomTempDir().'/autonomy-'.substr(hash('sha256',$raw),0,20).'.zip';if(@file_put_contents($tmp,$raw,LOCK_EX)===false)$result=['ok'=>false,'code'=>'PACKAGE_TEMP_WRITE_FAILED'];else{try{$result=kicomReceiveSelfUpdatePackage($tmp,(string)($meta['filename']??'KiCom-upload.zip'),'autonomy:session',true);if(!empty($result['ok'])&&($result['code']??'')==='STAGED_DECISION_REQUIRED'&&($result['risk_class']??($result['staged']['risk_class']??''))==='yellow'&&!empty(kicomAutonomyPolicy()['auto_yellow_from_session'])){$pending=kicomSelfUpdatePending();if(is_array($pending))$result=kicomApplySelfUpdate($pending)+['code'=>'AUTO_INSTALLED_YELLOW_SESSION'];}}finally{@unlink($tmp);}}
    }
    if(!empty($result['ok']))kicomAutonomyUploadDestroy($x);return $result+['upload_id'=>$id,'kind'=>$kind,'sha256'=>hash('sha256',$raw),'bytes'=>strlen($raw)];
}

function kicomAutonomySourcePaths(): array {
    $manifest=kicomBaseDir().'/MANIFEST.sha256';if(!is_file($manifest))return [];$rows=[];foreach(preg_split('/\r?\n/',(string)@file_get_contents($manifest))?:[] as $line){if(!preg_match('/^[a-f0-9]{64}\s+(.+)$/i',trim($line),$m))continue;$p=kicomSafeUpdatePath(trim($m[1]));if($p===null||!kicomSelfUpdateInstallPathAllowed($p))continue;if(str_starts_with($p,'var/')&&!hash_equals($p,'var/.htaccess'))continue;if(str_starts_with($p,'stage/')&&!hash_equals($p,'stage/.htaccess'))continue;$rows[$p]=strtolower($m[0]??'');}
    $paths=array_keys($rows);sort($paths,SORT_STRING);return $paths;
}
function kicomAutonomySourceList(): array {$out=[];foreach(kicomAutonomySourcePaths() as $p){$f=kicomBaseDir().'/'.$p;if(!is_file($f))continue;$out[]=['path'=>$p,'bytes'=>(int)filesize($f),'sha256'=>hash_file('sha256',$f)?:''];}return $out;}
function kicomAutonomySourceRead(string $path,int $offset=0,int $length=49152): array {$path=kicomSafeUpdatePath($path);if($path===null||!in_array($path,kicomAutonomySourcePaths(),true))return ['ok'=>false,'code'=>'SOURCE_PATH_FORBIDDEN'];$f=kicomBaseDir().'/'.$path;if(!is_file($f))return ['ok'=>false,'code'=>'SOURCE_FILE_MISSING'];$size=(int)filesize($f);$offset=max(0,$offset);$length=max(1,min(49152,$length));if($offset>$size)return ['ok'=>false,'code'=>'SOURCE_OFFSET_INVALID'];$h=@fopen($f,'rb');if(!$h)return ['ok'=>false,'code'=>'SOURCE_READ_FAILED'];fseek($h,$offset);$raw=(string)fread($h,$length);fclose($h);$next=$offset+strlen($raw);return ['ok'=>true,'path'=>$path,'bytes'=>$size,'sha256'=>hash_file('sha256',$f)?:'','offset'=>$offset,'next_offset'=>$next,'eof'=>$next>=$size,'data'=>kicomEncodeBase64Url($raw)];}


/* 0.9.8 high-throughput transport + server-side candidate builder.
   Candidate build trees live under protected var and are never executable. */
function kicomFastBuildRoot(): string { return kicomVarDir().'/fast_builds'; }
function kicomFastBuildDir(string $id): string { return kicomFastBuildRoot().'/'.$id; }
function kicomFastBuildSafeId(string $id): ?string {$id=strtolower(trim($id));return preg_match('/^[a-f0-9]{20}$/',$id)?$id:null;}
function kicomFastBuildEnsure(): bool {$d=kicomFastBuildRoot();if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;$deny=$d.'/.htaccess';if(!is_file($deny))@file_put_contents($deny,kicomDenyRules(),LOCK_EX);return true;}
function kicomFastBuildRm(string $dir): void {if(!is_dir($dir))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($dir);}
function kicomFastBuildCleanup(): void {if(!kicomFastBuildEnsure())return;$now=time();foreach(glob(kicomFastBuildRoot().'/*')?:[] as $d){if(is_dir($d)&&($now-(int)@filemtime($d))>7200)kicomFastBuildRm($d);}}
function kicomFastBuildMeta(string $sessionId,string $id): array {$id=kicomFastBuildSafeId($id)??'';$dir=kicomFastBuildDir($id);$m=kicomAuthJsonRead($dir.'/meta.json');if($id===''||!is_array($m)||!hash_equals((string)($m['session_id']??''),$sessionId))return ['ok'=>false,'code'=>'BUILD_NOT_FOUND'];if((int)($m['expires_at']??0)<time()){kicomFastBuildRm($dir);return ['ok'=>false,'code'=>'BUILD_EXPIRED'];}return ['ok'=>true,'dir'=>$dir,'meta'=>$m];}
function kicomFastBuildBegin(string $sessionId): array {
    kicomFastBuildCleanup();if(!kicomFastBuildEnsure())return ['ok'=>false,'code'=>'BUILD_STORAGE_UNAVAILABLE'];$id=substr(kicomAuthRandomHex(10),0,20);$dir=kicomFastBuildDir($id);$src=$dir.'/src';if(!@mkdir($src,0700,true)&&!is_dir($src))return ['ok'=>false,'code'=>'BUILD_CREATE_FAILED'];$files=[];
    foreach(kicomAutonomySourcePaths() as $p){$from=kicomBaseDir().'/'.$p;if(!is_file($from))return ['ok'=>false,'code'=>'BUILD_SOURCE_MISSING','path'=>$p];$to=$src.'/'.str_replace('/',DIRECTORY_SEPARATOR,$p);$td=dirname($to);if(!is_dir($td)&&!@mkdir($td,0700,true)&&!is_dir($td))return ['ok'=>false,'code'=>'BUILD_DIR_FAILED','path'=>$p];if(!@copy($from,$to))return ['ok'=>false,'code'=>'BUILD_COPY_FAILED','path'=>$p];@chmod($to,0600);$files[$p]=hash_file('sha256',$to)?:'';}
    $g=kicomGenomeCurrent();$m=['schema'=>1,'id'=>$id,'session_id'=>$sessionId,'status'=>'open','created_at'=>gmdate('c'),'expires_at'=>time()+7200,'base_version'=>KICOM_VERSION,'base_genome'=>(string)($g['id']??''),'base_files'=>$files,'patches'=>[]];if(!kicomAuthJsonWrite($dir.'/meta.json',$m)){kicomFastBuildRm($dir);return ['ok'=>false,'code'=>'BUILD_META_FAILED'];}@touch($dir);return ['ok'=>true,'build_id'=>$id,'base_version'=>KICOM_VERSION,'files'=>count($files),'expires_in'=>7200];
}
function kicomFastBuildPatch(string $sessionId,string $id,string $path,string $baseSha,string $find,string $replace): array {
    $x=kicomFastBuildMeta($sessionId,$id);if(!$x['ok'])return $x;$m=$x['meta'];if(($m['status']??'')!=='open')return ['ok'=>false,'code'=>'BUILD_NOT_OPEN'];$path=kicomSafeUpdatePath($path);if($path===null||!array_key_exists($path,$m['base_files']??[]))return ['ok'=>false,'code'=>'BUILD_PATH_FORBIDDEN'];if($find===''||strlen($find)>65536||strlen($replace)>262144)return ['ok'=>false,'code'=>'BUILD_PATCH_SIZE_INVALID'];$f=$x['dir'].'/src/'.$path;if(!is_file($f))return ['ok'=>false,'code'=>'BUILD_FILE_MISSING'];$raw=@file_get_contents($f);if($raw===false)return ['ok'=>false,'code'=>'BUILD_READ_FAILED'];$cur=hash('sha256',$raw);$baseSha=strtolower(trim($baseSha));if(!preg_match('/^[a-f0-9]{64}$/',$baseSha)||!hash_equals($cur,$baseSha))return ['ok'=>false,'code'=>'BASE_CONFLICT','current_sha256'=>$cur];$count=substr_count($raw,$find);if($count!==1)return ['ok'=>false,'code'=>'PATCH_MATCH_COUNT','matches'=>$count];$next=str_replace($find,$replace,$raw,$done);if($done!==1||strlen($next)>2097152)return ['ok'=>false,'code'=>'BUILD_PATCH_FAILED'];$v=kicomValidateContent($path,$next);if(($v['status']??'')==='error')return ['ok'=>false,'code'=>'VALIDATION_FAILED','validation'=>$v['message']];$tmp=$f.'.tmp-'.strtolower(kicomRequestId());if(@file_put_contents($tmp,$next,LOCK_EX)===false)return ['ok'=>false,'code'=>'BUILD_WRITE_FAILED'];@chmod($tmp,0600);if(!@rename($tmp,$f)){@unlink($tmp);return ['ok'=>false,'code'=>'BUILD_RENAME_FAILED'];}$after=hash('sha256',$next);$m['patches'][]=['path'=>$path,'at'=>gmdate('c'),'before'=>$cur,'after'=>$after];$m['expires_at']=time()+7200;kicomAuthJsonWrite($x['dir'].'/meta.json',$m);@touch($x['dir']);return ['ok'=>true,'build_id'=>$id,'path'=>$path,'sha256'=>$after,'patches'=>count($m['patches'])];
}
function kicomFastBuildStatus(string $sessionId,string $id): array {$x=kicomFastBuildMeta($sessionId,$id);if(!$x['ok'])return $x;$m=$x['meta'];$lib=@file_get_contents($x['dir'].'/src/lib.php');$v='';if(is_string($lib)&&preg_match("/const\\s+KICOM_VERSION\\s*=\\s*'([^']+)'/",$lib,$mm))$v=$mm[1];$g=json_decode((string)@file_get_contents($x['dir'].'/src/genome/genome.json'),true);return ['ok'=>true,'build_id'=>$id,'status'=>(string)($m['status']??''),'base_version'=>(string)($m['base_version']??''),'candidate_version'=>$v,'genome_id'=>is_array($g)?(string)($g['id']??''):'','patches'=>count($m['patches']??[]),'files'=>count($m['base_files']??[])];}
function kicomFastBuildPrepareRelease(string $sessionId,string $id,string $version,string $mutationReason,string $summary=''): array {
    $x=kicomFastBuildMeta($sessionId,$id);if(!$x['ok'])return $x;$m=$x['meta'];if(($m['status']??'')!=='open')return ['ok'=>false,'code'=>'BUILD_NOT_OPEN'];$version=trim($version);$mutationReason=trim($mutationReason);if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',$version)||version_compare($version,KICOM_VERSION,'<='))return ['ok'=>false,'code'=>'BUILD_VERSION_INVALID'];if($mutationReason===''||strlen($mutationReason)>1200)return ['ok'=>false,'code'=>'BUILD_REASON_INVALID'];$src=$x['dir'].'/src';$atomic=function(string $file,string $raw): bool {$tmp=$file.'.tmp-'.strtolower(kicomRequestId());if(@file_put_contents($tmp,$raw,LOCK_EX)===false)return false;@chmod($tmp,0600);if(!@rename($tmp,$file)){@unlink($tmp);return false;}return true;};
    $libf=$src.'/lib.php';$lib=(string)@file_get_contents($libf);$beforeLib=hash('sha256',$lib);$next=preg_replace("/const\\s+KICOM_VERSION\\s*=\\s*'[^']+';/","const KICOM_VERSION = '".$version."';",$lib,1,$n);if(!is_string($next)||$n!==1)return ['ok'=>false,'code'=>'BUILD_VERSION_ANCHOR'];$v=kicomValidateContent('lib.php',$next);if(($v['status']??'')==='error')return ['ok'=>false,'code'=>'VALIDATION_FAILED','validation'=>$v['message']];if(!$atomic($libf,$next))return ['ok'=>false,'code'=>'BUILD_WRITE_FAILED'];
    $current=kicomGenomeCurrent();if(!is_array($current))return ['ok'=>false,'code'=>'BUILD_CURRENT_GENOME_UNAVAILABLE'];$gp=$src.'/genome/genome.json';$g=json_decode((string)@file_get_contents($gp),true);if(!is_array($g))return ['ok'=>false,'code'=>'BUILD_GENOME_INVALID'];$beforeGenome=hash_file('sha256',$gp)?:'';$generation=(int)($current['generation']??0)+1;$gid='kicom-'.$version.'-g'.$generation;$g['id']=$gid;$g['version']=$version;$g['parent']=(string)($current['id']??'');$g['generation']=$generation;$g['created_at']=gmdate('c');$g['mutation_reason']=$mutationReason;$gj=json_encode($g,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($gj===false||!$atomic($gp,$gj."\n"))return ['ok'=>false,'code'=>'BUILD_GENOME_WRITE_FAILED'];
    $ps=$src.'/memory/project_state.kcl';if(is_file($ps)){$raw=(string)@file_get_contents($ps);$raw=preg_replace('/^VERSION\s+"[0-9]+\.[0-9]+\.[0-9]+"/m','VERSION "'.$version.'"',$raw,1,$pn);if($pn===1&&!$atomic($ps,$raw))return ['ok'=>false,'code'=>'BUILD_SEED_WRITE_FAILED'];}
    $summary=trim($summary);if($summary!==''&&strlen($summary)<=800){$cf=$src.'/memory/changelog.kcl';if(is_file($cf)){$cr=(string)@file_get_contents($cf);if(!str_contains($cr,'RELEASE "'.$version.'"')&&str_contains($cr,'END_CHANGELOG kicom')){$safe=str_replace(["\r","\n",'"'],[' ',' ',"'"],$summary);$cr=str_replace('END_CHANGELOG kicom','RELEASE "'.$version.'" date="'.gmdate('Y-m-d').'" change="'.$safe.'"'."\n".'END_CHANGELOG kicom',$cr);if(!$atomic($cf,$cr))return ['ok'=>false,'code'=>'BUILD_CHANGELOG_WRITE_FAILED'];}}}
    $m['patches'][]=['path'=>'lib.php','at'=>gmdate('c'),'before'=>$beforeLib,'after'=>hash_file('sha256',$libf)?:'','kind'=>'release_prepare'];$m['patches'][]=['path'=>'genome/genome.json','at'=>gmdate('c'),'before'=>$beforeGenome,'after'=>hash_file('sha256',$gp)?:'','kind'=>'release_prepare'];$m['expires_at']=time()+7200;if(!kicomAuthJsonWrite($x['dir'].'/meta.json',$m))return ['ok'=>false,'code'=>'BUILD_META_FAILED'];@touch($x['dir']);return ['ok'=>true,'build_id'=>$id,'candidate_version'=>$version,'genome_id'=>$gid,'generation'=>$generation,'lib_sha256'=>hash_file('sha256',$libf)?:'','genome_sha256'=>hash_file('sha256',$gp)?:'','patches'=>count($m['patches'])];
}

function kicomFastBuildFinalize(string $sessionId,string $id): array {
    $x=kicomFastBuildMeta($sessionId,$id);if(!$x['ok'])return $x;$m=$x['meta'];if(($m['status']??'')!=='open')return ['ok'=>false,'code'=>'BUILD_NOT_OPEN'];$src=$x['dir'].'/src';$lib=(string)@file_get_contents($src.'/lib.php');if(!preg_match("/const\\s+KICOM_VERSION\\s*=\\s*'([0-9]+\\.[0-9]+\\.[0-9]+)'/",$lib,$vm))return ['ok'=>false,'code'=>'BUILD_VERSION_MISSING'];$version=$vm[1];if(version_compare($version,KICOM_VERSION,'<='))return ['ok'=>false,'code'=>'BUILD_VERSION_NOT_NEWER','version'=>$version];
    $gp=$src.'/genome/genome.json';$g=json_decode((string)@file_get_contents($gp),true);if(!is_array($g))return ['ok'=>false,'code'=>'BUILD_GENOME_INVALID'];$current=kicomGenomeCurrent();if(!is_array($current))return ['ok'=>false,'code'=>'BUILD_CURRENT_GENOME_UNAVAILABLE'];if((string)($g['version']??'')!==$version||!hash_equals((string)($g['parent']??''),(string)($current['id']??''))||(int)($g['generation']??0)!==((int)($current['generation']??0)+1))return ['ok'=>false,'code'=>'BUILD_LINEAGE_INVALID'];
    /* Component hashes are deterministic packaging metadata; refresh them after source patches. */
    foreach($g['components'] as &$c){if(!is_array($c))return ['ok'=>false,'code'=>'BUILD_GENOME_COMPONENT_INVALID'];$p=kicomSafeUpdatePath((string)($c['path']??''));if($p===null||!is_file($src.'/'.$p))return ['ok'=>false,'code'=>'BUILD_GENOME_COMPONENT_MISSING','path'=>(string)($c['path']??'')];$c['sha256']=hash_file('sha256',$src.'/'.$p)?:'';}unset($c);$g['created_at']=gmdate('c');@file_put_contents($gp,json_encode($g,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",LOCK_EX);
    /* Fresh installs need a seed PROJECT_STATE matching the candidate version. */
    $ps=$src.'/memory/project_state.kcl';if(is_file($ps)){$s=(string)@file_get_contents($ps);$s=preg_replace('/^VERSION\\s+"[0-9]+\\.[0-9]+\\.[0-9]+"/m','VERSION "'.$version.'"',$s,1,$n);if($n===1)@file_put_contents($ps,$s,LOCK_EX);}
    $paths=array_keys($m['base_files']??[]);sort($paths,SORT_STRING);foreach($paths as $p){if(!is_file($src.'/'.$p))return ['ok'=>false,'code'=>'BUILD_FILE_MISSING','path'=>$p];$v=kicomValidateContent($p,(string)@file_get_contents($src.'/'.$p));if(($v['status']??'')==='error')return ['ok'=>false,'code'=>'BUILD_VALIDATION_FAILED','path'=>$p,'validation'=>$v['message']];}
    $manifest='';foreach($paths as $p)$manifest.=(hash_file('sha256',$src.'/'.$p)?:'').'  '.$p."\n";if(@file_put_contents($src.'/MANIFEST.sha256',$manifest,LOCK_EX)===false)return ['ok'=>false,'code'=>'BUILD_MANIFEST_FAILED'];$zip=$x['dir'].'/KiCom-'.$version.'-server-build.zip';$z=new ZipArchive();if($z->open($zip,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)return ['ok'=>false,'code'=>'BUILD_ZIP_OPEN_FAILED'];foreach(array_merge($paths,['MANIFEST.sha256']) as $p)if(!$z->addFile($src.'/'.$p,$p)){$z->close();return ['ok'=>false,'code'=>'BUILD_ZIP_ADD_FAILED','path'=>$p];}$z->close();$inspect=kicomSelfUpdateZipInspect($zip);if(empty($inspect['ok']))return ['ok'=>false,'code'=>'BUILD_VERIFIER_REJECTED','detail'=>(string)($inspect['code']??'UNKNOWN')];$risk=kicomSelfUpdateRiskClass($inspect);$r=kicomReceiveSelfUpdatePackage($zip,basename($zip),'autonomy:server-build',true);$m['status']=!empty($r['ok'])?'finalized':'failed';$m['finalized_at']=gmdate('c');$m['candidate_version']=$version;$m['package_sha256']=hash_file('sha256',$zip)?:'';$m['risk_class']=(string)($risk['class']??'');$m['result_code']=(string)($r['code']??'UNKNOWN');kicomAuthJsonWrite($x['dir'].'/meta.json',$m);return $r+['build_id'=>$id,'candidate_version'=>$version,'package_sha256'=>$m['package_sha256'],'risk_class'=>(string)($risk['class']??'')];
}
function kicomAutonomyMemoryPatchDirect(string $name,string $baseSha,string $find,string $replace): array {$c=kicomMemoryPatchCandidate($name,$baseSha,$find,$replace);if(empty($c['ok']))return $c;return kicomAtomicMemoryWrite((string)$c['resource'],(string)$c['content'],'autonomy_patch',(string)$c['base_sha256']);}
function kicomAutonomyWorkspacePatchDirect(string $path,string $baseSha,string $find,string $replace): array {$path=kicomSafeRelativePath($path);if($path===null)return ['ok'=>false,'code'=>'INVALID_PATH'];$raw=kicomReadStage($path);if($raw===null)return ['ok'=>false,'code'=>'FILE_NOT_FOUND'];$cur=hash('sha256',$raw);$baseSha=strtolower(trim($baseSha));if(!preg_match('/^[a-f0-9]{64}$/',$baseSha)||!hash_equals($cur,$baseSha))return ['ok'=>false,'code'=>'BASE_CONFLICT','current_sha256'=>$cur];if($find===''||strlen($find)>65536||strlen($replace)>262144)return ['ok'=>false,'code'=>'PATCH_SIZE_INVALID'];$count=substr_count($raw,$find);if($count!==1)return ['ok'=>false,'code'=>'PATCH_MATCH_COUNT','matches'=>$count];$next=str_replace($find,$replace,$raw,$done);if($done!==1)return ['ok'=>false,'code'=>'PATCH_APPLY_FAILED'];$v=kicomValidateContent($path,$next);if(($v['status']??'')==='error')return ['ok'=>false,'code'=>'VALIDATION_FAILED','validation'=>$v['message']];return kicomAtomicStageWrite($path,$next,'autonomy_patch',$baseSha);}

function kicomAutonomyDeployDirect(string $sessionId,string $alias,string $workspacePath,string $dest,string $workspaceSha,string $targetBase): array {
    $d=kicomDeployDryRun($alias,$workspacePath,$dest);if(!$d['ok'])return $d;if(!$d['target_writable'])return ['ok'=>false,'code'=>'TARGET_NOT_WRITABLE'];if($alias==='kicomarchive')return ['ok'=>false,'code'=>'ARCHIVE_APPEND_ONLY_SPECIAL'];$policy=kicomAutonomyPolicy();if((string)$d['target_class']==='test'&&empty($policy['auto_test']))return ['ok'=>false,'code'=>'AUTONOMY_TEST_DISABLED'];if((string)$d['target_class']==='staging'&&empty($policy['auto_staging']))return ['ok'=>false,'code'=>'AUTONOMY_STAGING_DISABLED'];$workspaceSha=strtolower($workspaceSha);$targetBase=strtoupper($targetBase)==='NEW'?'NEW':strtolower($targetBase);if(!hash_equals((string)$d['workspace_sha256'],$workspaceSha)||!hash_equals((string)$d['target_sha256'],$targetBase))return ['ok'=>false,'code'=>'DEPLOY_HASH_CONFLICT'];
    $proposal=['kind'=>'deploy_write','target'=>$d['target'],'target_class'=>$d['target_class'],'workspace_path'=>$d['workspace_path'],'dest_path'=>$d['dest_path'],'workspace_sha256'=>$workspaceSha,'target_base_sha256'=>$targetBase,'bytes'=>$d['bytes'],'sha256'=>$workspaceSha,'validation'=>['status'=>'ok','message'=>$d['validation']],'healthcheck_preflight_ok'=>$d['healthcheck_preflight_ok'],'healthcheck_http_code'=>$d['healthcheck_http_code'],'transport'=>'AUTONOMY_SESSION'];
    if((string)$d['target_class']==='production')return kicomAuthApprovalCreate($sessionId,'deploy_write',['target'=>$alias,'dest'=>$dest,'workspace_sha256'=>$workspaceSha,'target_base_sha256'=>$targetBase],['proposal'=>$proposal],'production');return kicomApplyDeployProposal($proposal);
}
function kicomAutonomyDeployRollback(string $sessionId,string $deploymentId): array {
    $h=kicomDeployHistoryGet($deploymentId);if(!is_array($h))return ['ok'=>false,'code'=>'DEPLOYMENT_NOT_FOUND'];$target=kicomDeployTarget((string)$h['target'],true);if(!is_array($target))return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];$current=kicomTargetHash((string)$h['target'],(string)$h['dest_path']);$proposal=['kind'=>'deploy_rollback','target'=>$h['target'],'target_class'=>$target['class'],'dest_path'=>$h['dest_path'],'source_deployment'=>$deploymentId,'target_base_sha256'=>$current,'bytes'=>0,'sha256'=>(string)($h['before_sha256']??''),'transport'=>'AUTONOMY_SESSION'];if((string)$target['class']==='production')return kicomAuthApprovalCreate($sessionId,'deploy_rollback',['deployment_id'=>$deploymentId,'target'=>$h['target'],'dest'=>$h['dest_path'],'current_sha256'=>$current],['proposal'=>$proposal],'production');return kicomApplyDeployProposal($proposal);
}

function kicomArchiveSafeRel(string $p): ?string {$p=ltrim(str_replace('\\','/',trim($p)),'/');if($p===''||strlen($p)>240||str_contains($p,'..')||str_contains($p,"\0")||!preg_match('~^[A-Za-z0-9_./-]+$~',$p))return null;return $p;}
function kicomArchiveTarget(): ?array {$t=kicomDeployTarget('kicomarchive',true);if(!is_array($t)||($t['class']??'')==='production')return null;return $t;}
function kicomArchiveWrite(string $rel,string $raw): array {
    $t=kicomArchiveTarget();$rel=kicomArchiveSafeRel($rel);if($t===null||$rel===null)return ['ok'=>false,'code'=>'ARCHIVE_TARGET_UNAVAILABLE'];$root=(string)$t['root'];$rootReal=realpath($root);if($rootReal===false)return ['ok'=>false,'code'=>'ARCHIVE_ROOT_INVALID'];$full=rtrim($rootReal,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);$dir=dirname($full);if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))return ['ok'=>false,'code'=>'ARCHIVE_DIR_FAILED'];$rootNorm=rtrim(str_replace('\\','/',$rootReal),'/').'/';$dirReal=realpath($dir);if($dirReal===false||!str_starts_with(rtrim(str_replace('\\','/',$dirReal),'/').'/', $rootNorm))return ['ok'=>false,'code'=>'ARCHIVE_PATH_ESCAPE'];$sha=hash('sha256',$raw);
    if(is_file($full)){
        $cur=hash_file('sha256',$full)?:'';if(hash_equals($cur,$sha))return ['ok'=>true,'code'=>'ALREADY_ARCHIVED','sha256'=>$sha];
        /* release.json carries timestamps and provenance; immutable package identity is what makes a repeated archive operation idempotent. */
        if(basename($rel)==='release.json'){$old=json_decode((string)@file_get_contents($full),true);$new=json_decode($raw,true);if(is_array($old)&&is_array($new)){foreach(['product','version','genome_id','parent','generation','package_sha256','manifest_sha256'] as $k){if((string)($old[$k]??'')!==(string)($new[$k]??''))return ['ok'=>false,'code'=>'ARCHIVE_CONFLICT','current_sha256'=>$cur];}return ['ok'=>true,'code'=>'ALREADY_ARCHIVED_METADATA','sha256'=>$cur];}}
        return ['ok'=>false,'code'=>'ARCHIVE_CONFLICT','current_sha256'=>$cur];
    }
    $tmp=$full.'.tmp-'.strtolower(kicomRequestId());if(@file_put_contents($tmp,$raw,LOCK_EX)===false)return ['ok'=>false,'code'=>'ARCHIVE_WRITE_FAILED'];@chmod($tmp,0640);if(!@rename($tmp,$full)){@unlink($tmp);return ['ok'=>false,'code'=>'ARCHIVE_RENAME_FAILED'];}@chmod($full,0640);return ['ok'=>true,'code'=>'ARCHIVED','sha256'=>$sha];
}
function kicomArchiveEnsureDeny(): array {
    $t=kicomArchiveTarget();if($t===null)return ['ok'=>false,'code'=>'ARCHIVE_TARGET_UNAVAILABLE'];
    $root=realpath((string)$t['root']);if($root===false)return ['ok'=>false,'code'=>'ARCHIVE_ROOT_INVALID'];$full=rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.htaccess';
    if(is_file($full)){
        $raw=@file_get_contents($full);if($raw===false)return ['ok'=>false,'code'=>'ARCHIVE_DENY_READ_FAILED'];
        $noIndex=(bool)preg_match('/^\s*Options\s+-Indexes\s*$/mi',$raw);$deny=(bool)preg_match('/Require\s+all\s+denied/i',$raw)||(bool)preg_match('/^\s*Deny\s+from\s+all\s*$/mi',$raw);
        if($noIndex&&$deny)return ['ok'=>true,'code'=>'ARCHIVE_DENY_PRESENT','sha256'=>hash('sha256',$raw)];
        return ['ok'=>false,'code'=>'ARCHIVE_DENY_POLICY_CONFLICT'];
    }
    return kicomArchiveWrite('.htaccess',kicomDenyRules());
}
function kicomArchiveReleasePackage(string $pkg,array $check,array $meta=[]): array {
    if(!is_file($pkg))return ['ok'=>false,'code'=>'PACKAGE_NOT_FOUND'];$deny=kicomArchiveEnsureDeny();if(!$deny['ok'])return $deny;$version=(string)($check['version']??'');if(!preg_match('/^\d+\.\d+\.\d+$/',$version))return ['ok'=>false,'code'=>'ARCHIVE_VERSION_INVALID'];$raw=@file_get_contents($pkg);if($raw===false)return ['ok'=>false,'code'=>'PACKAGE_READ_FAILED'];$base='releases/'.$version;$rows=[];$rows['install/KiCom-'.$version.'-install.zip']=kicomArchiveWrite($base.'/install/KiCom-'.$version.'-install.zip',$raw);$rows['update/KiCom-'.$version.'-update.zip']=kicomArchiveWrite($base.'/update/KiCom-'.$version.'-update.zip',$raw);foreach($rows as $r)if(empty($r['ok']))return $r;
    $sourceHashes=[];foreach(($check['install_files']??[]) as $path=>$e){if(!is_array($e)||!isset($e['content']))continue;$r=kicomArchiveWrite($base.'/source/'.$path,(string)$e['content']);if(empty($r['ok']))return $r;$sourceHashes[$path]=(string)$e['sha256'];}
    $release=['schema'=>1,'product'=>'kicom','version'=>$version,'genome_id'=>(string)($check['genome']['id']??''),'parent'=>(string)($check['genome']['parent']??''),'generation'=>(int)($check['genome']['generation']??0),'package_sha256'=>(string)($check['zip_sha256']??hash('sha256',$raw)),'manifest_sha256'=>(string)($check['manifest_sha256']??''),'archived_at'=>gmdate('c'),'source'=>(string)($meta['source']??''),'risk_class'=>(string)($meta['risk_class']??''),'policy'=>'append-only-never-overwrite'];$r=kicomArchiveWrite($base.'/release.json',(json_encode($release,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)?:'{}')."\n");if(!$r['ok'])return $r;
    $sum=[];$sum[]=hash('sha256',$raw).'  install/KiCom-'.$version.'-install.zip';$sum[]=hash('sha256',$raw).'  update/KiCom-'.$version.'-update.zip';foreach($sourceHashes as $p=>$s)$sum[]=$s.'  source/'.$p;$r=kicomArchiveWrite($base.'/SHA256SUMS',implode("\n",$sum)."\n");if(!$r['ok'])return $r;kicomLivingEvent('release_archived','info',['version'=>$version,'package_sha256'=>$release['package_sha256']]);return ['ok'=>true,'code'=>'RELEASE_ARCHIVED','version'=>$version,'package_sha256'=>$release['package_sha256']];
}
function kicomArchiveBackfill(): array {$done=[];$errors=[];foreach(kicomSelfUpdateHistory() as $h){if(($h['action']??'')!=='self_update'||($h['status']??'')!=='installed')continue;$name=(string)($h['package_file']??'');if(!preg_match('/^[a-f0-9]{64}\.zip$/',$name))continue;$pkg=kicomSelfUpdatePackagesDir().'/'.$name;if(!is_file($pkg))continue;$check=kicomSelfUpdateZipInspect($pkg);if(!$check['ok']){$errors[]=['version'=>$h['to_version']??'','code'=>$check['code']??'INSPECT_FAILED'];continue;}$r=kicomArchiveReleasePackage($pkg,$check,['source'=>$h['source']??'history','risk_class'=>$h['risk_class']??'']);if($r['ok'])$done[]=$r['version'];else $errors[]=['version'=>$h['to_version']??'','code'=>$r['code']??'ARCHIVE_FAILED'];}return ['ok'=>empty($errors),'code'=>empty($errors)?'ARCHIVE_BACKFILL_OK':'ARCHIVE_BACKFILL_PARTIAL','versions'=>$done,'errors'=>$errors];}
function kicomArchiveStatus(): array {$t=kicomArchiveTarget();return ['configured'=>$t!==null,'target_class'=>$t['class']??'','policy'=>'append-only-never-overwrite'];}

function kicomAuxTargetFile(string $alias,string $rel,array $exts): ?array {
    $t=kicomDeployTarget($alias,true);$rel=kicomArchiveSafeRel($rel);if($t===null||$rel===null)return null;$ext=strtolower(pathinfo($rel,PATHINFO_EXTENSION));if(!in_array($ext,$exts,true))return null;$rootReal=realpath((string)$t['root']);if($rootReal===false)return null;$full=rtrim($rootReal,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);$dir=dirname($full);if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))return null;$dirReal=realpath($dir);$rootNorm=rtrim(str_replace('\\','/',$rootReal),'/').'/';if($dirReal===false||!str_starts_with(rtrim(str_replace('\\','/',$dirReal),'/').'/', $rootNorm))return null;return ['target'=>$t,'full'=>$full,'rel'=>$rel];
}
function kicomAuxTargetWrite(string $alias,string $rel,string $raw,array $exts,bool $appendOnly=false): array {
    $x=kicomAuxTargetFile($alias,$rel,$exts);if($x===null)return ['ok'=>false,'code'=>'AUX_TARGET_PATH_INVALID'];$full=(string)$x['full'];$sha=hash('sha256',$raw);if(is_file($full)){$cur=hash_file('sha256',$full)?:'';if(hash_equals($cur,$sha))return ['ok'=>true,'code'=>'UNCHANGED','sha256'=>$sha];if($appendOnly)return ['ok'=>false,'code'=>'AUX_TARGET_CONFLICT','current_sha256'=>$cur];}$tmp=$full.'.tmp-'.strtolower(kicomRequestId());if(@file_put_contents($tmp,$raw,LOCK_EX)===false)return ['ok'=>false,'code'=>'AUX_TARGET_WRITE_FAILED'];@chmod($tmp,0640);if(!@rename($tmp,$full)){@unlink($tmp);return ['ok'=>false,'code'=>'AUX_TARGET_RENAME_FAILED'];}@chmod($full,0640);return ['ok'=>true,'code'=>'WRITTEN','sha256'=>$sha];
}

function kicomPrimaryFeedPublishPackage(string $pkg,array $check,array $meta=[]): array {
    $t=kicomDeployTarget('updatefeed',true);if(!is_array($t))return ['ok'=>false,'code'=>'UPDATEFEED_TARGET_UNAVAILABLE'];$version=(string)($check['version']??'');if(!preg_match('/^\d+\.\d+\.\d+$/',$version))return ['ok'=>false,'code'=>'UPDATEFEED_VERSION_INVALID'];$raw=@file_get_contents($pkg);if($raw===false)return ['ok'=>false,'code'=>'PACKAGE_READ_FAILED'];$dest='releases/'.$version.'/KiCom-'.$version.'-update.zip';$sha=hash('sha256',$raw);$w=kicomAuxTargetWrite('updatefeed',$dest,$raw,['zip'],true);if(!$w['ok'])return $w;
    $cx=kicomAuxTargetFile('updatefeed','channel.json',['json']);if($cx===null)return ['ok'=>false,'code'=>'UPDATEFEED_CHANNEL_INVALID'];$current=is_file((string)$cx['full'])?json_decode((string)@file_get_contents((string)$cx['full']),true):null;if(!is_array($current))$current=['schema'=>1,'product'=>'kicom','releases'=>[]];$host=(string)parse_url((string)($t['health_url']??''),PHP_URL_HOST);if($host==='')$host='feed.rurtalbahn.info';$url='https://'.$host.'/'.$dest;$rels=array_values(array_filter($current['releases']??[],fn($r)=>!is_array($r)||(string)($r['version']??'')!==$version));array_unshift($rels,['version'=>$version,'url'=>$url,'sha256'=>$sha,'published_at'=>gmdate('c')]);$current=['schema'=>1,'product'=>'kicom','releases'=>$rels];$j=json_encode($current,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);if($j===false)return ['ok'=>false,'code'=>'UPDATEFEED_JSON_FAILED'];$w=kicomAuxTargetWrite('updatefeed','channel.json',$j."\n",['json'],false);if(!$w['ok'])return $w;$health=kicomHealthCheck('updatefeed');kicomLivingEvent('release_published_primary',$health['ok']?'info':'warn',['version'=>$version,'package_sha256'=>$sha,'health'=>$health]);return ['ok'=>$health['ok'],'code'=>$health['ok']?'PRIMARY_PUBLISHED':'PRIMARY_HEALTH_FAILED','version'=>$version,'url'=>$url,'sha256'=>$sha,'health'=>$health];
}
function kicomPublishCurrentPackageToPrimary(): array {$p=kicomSelfUpdatePending();if(is_array($p))return ['ok'=>false,'code'=>'PENDING_UPDATE_EXISTS'];foreach(kicomSelfUpdateHistory() as $h){if(($h['action']??'')==='self_update'&&($h['status']??'')==='installed'&&($h['to_version']??'')===KICOM_VERSION){$name=(string)($h['package_file']??'');$pkg=kicomSelfUpdatePackagesDir().'/'.$name;if(!is_file($pkg))return ['ok'=>false,'code'=>'PACKAGE_NOT_FOUND'];$check=kicomSelfUpdateZipInspect($pkg);if(!$check['ok'])return $check;return kicomPrimaryFeedPublishPackage($pkg,$check,['source'=>$h['source']??'history','risk_class'=>$h['risk_class']??'']);}}return ['ok'=>false,'code'=>'CURRENT_RELEASE_HISTORY_NOT_FOUND'];}

function kicomAutonomyStatus(): array {$p=kicomAutonomyPolicy();$t=kicomTotpPublicStatus();$a=kicomArchiveStatus();return ['policy'=>$p,'totp'=>$t,'sessions'=>kicomAutonomySessionCount(),'archive'=>$a,'transport'=>'rolling-one-time-session-token','critical_approval'=>'transaction-bound-totp'];}
