<?php
declare(strict_types=1);

/* KiCom delegated isolated-build jobs v1. Candidate only.
 * No release/finalize/deploy/memory/update/critical authority. */
function kicomDelegatedBuildJobsDirV1(): string { return kicomVarDir().'/delegated_build_jobs_v1'; }
function kicomDelegatedBuildJobFileV1(string $id): string { return kicomDelegatedBuildJobsDirV1().'/'.$id.'.json'; }
function kicomDelegatedBuildJobLockV1(string $id): string { return kicomDelegatedBuildJobsDirV1().'/'.$id.'.lock'; }
function kicomDelegatedBuildJobsEnsureV1(): bool {
    $d=kicomDelegatedBuildJobsDirV1();if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;
    $deny=$d.'/.htaccess';if(!is_file($deny))@file_put_contents($deny,"Require all denied\n",LOCK_EX);return true;
}
function kicomDelegatedBuildJobIdV1(string $id): ?string {$id=strtolower(trim($id));return preg_match('/^[a-f0-9]{24}$/',$id)?$id:null;}
function kicomDelegatedBuildJobReadV1(string $id): ?array {$id=kicomDelegatedBuildJobIdV1($id)??'';return $id===''?null:kicomAuthJsonRead(kicomDelegatedBuildJobFileV1($id));}
function kicomDelegatedBuildPlanCanonV1(array $plan): string {
    $copy=$plan;foreach($copy['steps']??[] as &$s)if(is_array($s))ksort($s,SORT_STRING);unset($s);ksort($copy,SORT_STRING);
    return json_encode($copy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'';
}
function kicomDelegatedBuildPlanValidateV1(array $plan): array {
    $buildId=strtolower(trim((string)($plan['build_id']??'')));$steps=$plan['steps']??null;
    if(!preg_match('/^[a-f0-9]{20}$/',$buildId)||!is_array($steps)||count($steps)<1||count($steps)>16)return ['ok'=>false,'code'=>'JOB_PLAN_INVALID'];
    $clean=[];$bytes=0;
    foreach($steps as $i=>$s){
        if(!is_array($s)||strtolower((string)($s['kind']??'build_patch'))!=='build_patch')return ['ok'=>false,'code'=>'JOB_STEP_KIND_REJECTED','step'=>$i];
        $path=kicomSafeUpdatePath((string)($s['path']??''));$base=strtolower((string)($s['base_sha256']??''));$expected=strtolower((string)($s['expected_sha256']??''));$find=(string)($s['find']??'');$replace=(string)($s['replace']??'');
        if($path===null||!preg_match('/^[a-f0-9]{64}$/',$base)||!preg_match('/^[a-f0-9]{64}$/',$expected)||$find===''||strlen($find)>4096||strlen($replace)>16384)return ['ok'=>false,'code'=>'JOB_STEP_INVALID','step'=>$i];
        $bytes+=strlen($find)+strlen($replace);if($bytes>65536)return ['ok'=>false,'code'=>'JOB_PLAN_TOO_LARGE'];
        $clean[]=['kind'=>'build_patch','path'=>$path,'base_sha256'=>$base,'find'=>$find,'replace'=>$replace,'expected_sha256'=>$expected];
    }
    $out=['build_id'=>$buildId,'steps'=>$clean];return ['ok'=>true,'plan'=>$out,'plan_sha256'=>hash('sha256',kicomDelegatedBuildPlanCanonV1($out))];
}
function kicomDelegatedBuildJobCreateV1(array $session,array $plan): array {
    if(!kicomDelegatedBuildJobsEnsureV1())return ['ok'=>false,'code'=>'JOB_STORAGE_UNAVAILABLE'];
    $sid=strtolower((string)($session['session_id']??''));$abs=(int)($session['expires_at']??0);$now=time();if(!preg_match('/^[a-f0-9]{24}$/',$sid)||$abs<=$now)return ['ok'=>false,'code'=>'JOB_SESSION_INVALID'];
    $v=kicomDelegatedBuildPlanValidateV1($plan);if(empty($v['ok']))return $v;$id=substr(kicomAuthRandomHex(12),0,24);$expires=min($abs,$now+3600);
    $row=['schema'=>1,'id'=>$id,'state'=>'READY','session_id'=>$sid,'plan_sha256'=>(string)$v['plan_sha256'],'plan'=>$v['plan'],'next_step'=>0,'completed_steps'=>0,'created_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'expires_at'=>$expires,'last_code'=>'READY','last_sha256'=>''];
    if(!kicomAuthJsonWrite(kicomDelegatedBuildJobFileV1($id),$row))return ['ok'=>false,'code'=>'JOB_WRITE_FAILED'];
    if(function_exists('kicomLivingEvent'))kicomLivingEvent('delegated_build_job_created','info',['job_id'=>$id,'build_id'=>$row['plan']['build_id'],'steps'=>count($row['plan']['steps']),'expires_in'=>$expires-$now]);
    return ['ok'=>true,'code'=>'JOB_CREATED','job_id'=>$id,'plan_sha256'=>$row['plan_sha256'],'steps'=>count($row['plan']['steps']),'expires_in'=>$expires-$now];
}
function kicomDelegatedBuildJobStatusV1(string $id): array {
    $r=kicomDelegatedBuildJobReadV1($id);if(!is_array($r))return ['ok'=>false,'code'=>'JOB_NOT_FOUND'];
    return ['ok'=>true,'code'=>'OK','job_id'=>(string)$r['id'],'state'=>(string)$r['state'],'plan_sha256'=>(string)$r['plan_sha256'],'build_id'=>(string)($r['plan']['build_id']??''),'next_step'=>(int)$r['next_step'],'completed_steps'=>(int)$r['completed_steps'],'steps'=>count($r['plan']['steps']??[]),'expires_at'=>(int)$r['expires_at'],'last_code'=>(string)$r['last_code'],'last_sha256'=>(string)$r['last_sha256']];
}
function kicomDelegatedBuildJobTickV1(string $id): array {
    $id=kicomDelegatedBuildJobIdV1($id)??'';if($id==='')return ['ok'=>false,'code'=>'JOB_ID_INVALID'];$lf=kicomDelegatedBuildJobLockV1($id);$lock=@fopen($lf,'c+');if(is_resource($lock))@chmod($lf,0600);if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);return ['ok'=>false,'code'=>'JOB_LOCK_FAILED'];}
    try{
        $r=kicomDelegatedBuildJobReadV1($id);if(!is_array($r))return ['ok'=>false,'code'=>'JOB_NOT_FOUND'];$state=(string)($r['state']??'');if(in_array($state,['COMPLETED','FAILED','EXPIRED'],true))return ['ok'=>true,'code'=>'JOB_TERMINAL','state'=>$state];
        if((int)($r['expires_at']??0)<time()){$r['state']='EXPIRED';$r['last_code']='JOB_EXPIRED';$r['updated_at']=gmdate('c');kicomAuthJsonWrite(kicomDelegatedBuildJobFileV1($id),$r);return ['ok'=>false,'code'=>'JOB_EXPIRED'];}
        $steps=$r['plan']['steps']??[];$n=(int)($r['next_step']??0);if(!isset($steps[$n])||!is_array($steps[$n])){$r['state']='COMPLETED';$r['last_code']='COMPLETED';$r['updated_at']=gmdate('c');kicomAuthJsonWrite(kicomDelegatedBuildJobFileV1($id),$r);return ['ok'=>true,'code'=>'JOB_COMPLETED','completed_steps'=>(int)$r['completed_steps']];}
        $s=$steps[$n];$r['state']='RUNNING';$r['updated_at']=gmdate('c');kicomAuthJsonWrite(kicomDelegatedBuildJobFileV1($id),$r);
        $x=kicomFastBuildPatch((string)$r['session_id'],(string)$r['plan']['build_id'],(string)$s['path'],(string)$s['base_sha256'],(string)$s['find'],(string)$s['replace']);
        if(empty($x['ok'])){$r['state']='FAILED';$r['last_code']=(string)($x['code']??'PATCH_FAILED');$r['updated_at']=gmdate('c');kicomAuthJsonWrite(kicomDelegatedBuildJobFileV1($id),$r);return ['ok'=>false,'code'=>$r['last_code'],'step'=>$n];}
        $sha=strtolower((string)($x['sha256']??''));if(!hash_equals((string)$s['expected_sha256'],$sha)){$r['state']='FAILED';$r['last_code']='JOB_RESULT_SHA_MISMATCH';$r['last_sha256']=$sha;$r['updated_at']=gmdate('c');kicomAuthJsonWrite(kicomDelegatedBuildJobFileV1($id),$r);return ['ok'=>false,'code'=>'JOB_RESULT_SHA_MISMATCH','step'=>$n,'sha256'=>$sha];}
        $r['completed_steps']=(int)$r['completed_steps']+1;$r['next_step']=$n+1;$r['last_code']='STEP_OK';$r['last_sha256']=$sha;$r['state']=$r['next_step']>=count($steps)?'COMPLETED':'READY';$r['updated_at']=gmdate('c');if(!kicomAuthJsonWrite(kicomDelegatedBuildJobFileV1($id),$r))return ['ok'=>false,'code'=>'JOB_WRITE_FAILED'];
        if(function_exists('kicomLivingEvent'))kicomLivingEvent('delegated_build_job_step','info',['job_id'=>$id,'step'=>$n,'state'=>$r['state'],'sha256'=>$sha]);
        return ['ok'=>true,'code'=>$r['state']==='COMPLETED'?'JOB_COMPLETED':'STEP_OK','job_id'=>$id,'step'=>$n,'state'=>$r['state'],'next_step'=>(int)$r['next_step'],'sha256'=>$sha];
    }finally{@flock($lock,LOCK_UN);@fclose($lock);}
}
function kicomDelegatedBuildJobTickAllV1(int $limit=4): array {
    if(!kicomDelegatedBuildJobsEnsureV1())return ['ok'=>false,'code'=>'JOB_STORAGE_UNAVAILABLE'];$limit=max(1,min(16,$limit));$rows=[];
    foreach(glob(kicomDelegatedBuildJobsDirV1().'/*.json')?:[] as $f){if(count($rows)>=$limit)break;$id=basename($f,'.json');$r=kicomDelegatedBuildJobReadV1($id);if(!is_array($r)||!in_array((string)($r['state']??''),['READY','RUNNING'],true))continue;$rows[]=['job_id'=>$id,'result'=>kicomDelegatedBuildJobTickV1($id)];}
    return ['ok'=>true,'code'=>'TICK_COMPLETE','jobs'=>$rows];
}
