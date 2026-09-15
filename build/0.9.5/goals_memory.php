<?php
declare(strict_types=1);

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
