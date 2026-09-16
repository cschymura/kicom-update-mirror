<?php
declare(strict_types=1);

/* KiCom Interrupt/Auth Resilience candidate v1.
 * Candidate-only. No routing, no permission expansion, no TOTP bypass.
 * Persists only non-secret resumable job/checkpoint state and idempotent request receipts.
 */

function kicomResilienceRoot(): string { return kicomVarDir().'/interrupt_resilience'; }
function kicomResilienceJobsDir(): string { return kicomResilienceRoot().'/jobs'; }
function kicomResilienceReceiptsDir(): string { return kicomResilienceRoot().'/receipts'; }

function kicomResilienceEnsure(): bool {
    foreach ([kicomResilienceRoot(),kicomResilienceJobsDir(),kicomResilienceReceiptsDir()] as $d) {
        if (!is_dir($d) && !@mkdir($d,0700,true) && !is_dir($d)) return false;
    }
    $deny=kicomResilienceRoot().'/.htaccess';
    if (!is_file($deny)) @file_put_contents($deny,"Require all denied\n",LOCK_EX);
    return true;
}

function kicomResilienceId(string $id): ?string {
    $id=strtolower(trim($id));
    return preg_match('/^[a-f0-9]{16,64}$/',$id)?$id:null;
}

function kicomResilienceJsonWrite(string $file,array $row): bool {
    $json=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if ($json===false) return false;
    $tmp=$file.'.tmp-'.bin2hex(random_bytes(4));
    if (@file_put_contents($tmp,$json."\n",LOCK_EX)===false) return false;
    @chmod($tmp,0600);
    if (!@rename($tmp,$file)) { @unlink($tmp); return false; }
    @chmod($file,0600); return true;
}

function kicomResilienceJsonRead(string $file): ?array {
    if (!is_file($file)) return null;
    $raw=@file_get_contents($file); if ($raw===false) return null;
    $row=json_decode($raw,true); return is_array($row)?$row:null;
}

function kicomResilienceSecretKey(string $k): bool {
    $k=strtolower($k);
    foreach (['token','totp','freeotp','password','secret','authorization','cookie','session_key','api_key'] as $needle)
        if (str_contains($k,$needle)) return true;
    return false;
}

function kicomResilienceSanitize(array $in,int $depth=0): array {
    if ($depth>4) return [];
    $out=[];
    foreach ($in as $k=>$v) {
        $key=preg_replace('/[^A-Za-z0-9_.:-]/','_',substr((string)$k,0,80));
        if ($key==='' || kicomResilienceSecretKey($key)) continue;
        if (is_array($v)) $out[$key]=kicomResilienceSanitize($v,$depth+1);
        elseif (is_bool($v)||is_int($v)||is_float($v)||$v===null) $out[$key]=$v;
        elseif (is_string($v)) $out[$key]=substr($v,0,1000);
    }
    return $out;
}

function kicomResilienceJobFile(string $id): string { return kicomResilienceJobsDir().'/'.$id.'.json'; }

function kicomResilienceJobCreate(string $goal,string $phase='INIT',array $meta=[]): array {
    if (!kicomResilienceEnsure()) return ['ok'=>false,'code'=>'RESILIENCE_STORAGE_UNAVAILABLE'];
    $goal=trim($goal); $phase=trim($phase);
    if ($goal===''||strlen($goal)>1000||!preg_match('/^[A-Za-z0-9_.:-]{1,80}$/',$phase))
        return ['ok'=>false,'code'=>'JOB_INPUT_INVALID'];
    $id=bin2hex(random_bytes(12));
    $row=['schema'=>1,'id'=>$id,'state'=>'RUNNING','phase'=>$phase,'goal'=>$goal,'revision'=>1,
          'last_completed'=>'','next'=>'','meta'=>kicomResilienceSanitize($meta),'created_at'=>gmdate('c'),'updated_at'=>gmdate('c')];
    if (!kicomResilienceJsonWrite(kicomResilienceJobFile($id),$row)) return ['ok'=>false,'code'=>'JOB_WRITE_FAILED'];
    return ['ok'=>true,'code'=>'JOB_CREATED','job'=>$row];
}

function kicomResilienceJobGet(string $id): array {
    $id=kicomResilienceId($id)??''; if ($id==='') return ['ok'=>false,'code'=>'JOB_ID_INVALID'];
    $row=kicomResilienceJsonRead(kicomResilienceJobFile($id));
    return is_array($row)?['ok'=>true,'code'=>'OK','job'=>$row]:['ok'=>false,'code'=>'JOB_NOT_FOUND'];
}

function kicomResilienceJobCheckpoint(string $id,int $expectedRevision,string $phase,string $lastCompleted,string $next,string $state='RUNNING',array $meta=[]): array {
    $id=kicomResilienceId($id)??''; if ($id==='') return ['ok'=>false,'code'=>'JOB_ID_INVALID'];
    if (!in_array($state,['RUNNING','WAITING_HUMAN_APPROVAL','PAUSED','COMPLETED','FAILED'],true)) return ['ok'=>false,'code'=>'JOB_STATE_INVALID'];
    if (!preg_match('/^[A-Za-z0-9_.:-]{1,80}$/',$phase)) return ['ok'=>false,'code'=>'JOB_PHASE_INVALID'];
    $file=kicomResilienceJobFile($id); $lock=@fopen($file.'.lock','c+');
    if ($lock===false||!@flock($lock,LOCK_EX)) { if(is_resource($lock))@fclose($lock); return ['ok'=>false,'code'=>'JOB_LOCK_FAILED']; }
    try {
        $row=kicomResilienceJsonRead($file); if(!is_array($row)) return ['ok'=>false,'code'=>'JOB_NOT_FOUND'];
        if ((int)($row['revision']??0)!==$expectedRevision) return ['ok'=>false,'code'=>'JOB_REVISION_CONFLICT','current_revision'=>(int)($row['revision']??0)];
        $row['state']=$state; $row['phase']=$phase; $row['last_completed']=substr($lastCompleted,0,200); $row['next']=substr($next,0,500);
        $row['meta']=array_replace(is_array($row['meta']??null)?$row['meta']:[],kicomResilienceSanitize($meta));
        $row['revision']=$expectedRevision+1; $row['updated_at']=gmdate('c');
        if (!kicomResilienceJsonWrite($file,$row)) return ['ok'=>false,'code'=>'JOB_WRITE_FAILED'];
        return ['ok'=>true,'code'=>'JOB_CHECKPOINTED','job'=>$row];
    } finally { @flock($lock,LOCK_UN); @fclose($lock); }
}

function kicomResilienceReceiptFile(string $sessionId,string $requestId): ?string {
    $sid=preg_replace('/[^a-f0-9]/','',strtolower($sessionId)); $rid=preg_replace('/[^A-Za-z0-9_.:-]/','',$requestId);
    if (strlen($sid)<16||strlen($rid)<8||strlen($rid)>80) return null;
    return kicomResilienceReceiptsDir().'/'.hash('sha256',$sid.'|'.$rid).'.json';
}

function kicomResilienceReceiptBegin(string $sessionId,string $requestId,string $operation,string $requestSha,int $ttl=600): array {
    if (!kicomResilienceEnsure()) return ['ok'=>false,'code'=>'RESILIENCE_STORAGE_UNAVAILABLE'];
    $file=kicomResilienceReceiptFile($sessionId,$requestId); $requestSha=strtolower(trim($requestSha));
    if ($file===null||!preg_match('/^[a-f0-9]{64}$/',$requestSha)) return ['ok'=>false,'code'=>'RECEIPT_INPUT_INVALID'];
    $old=kicomResilienceJsonRead($file);
    if (is_array($old) && (int)($old['expires_at']??0)>=time()) {
        if (!hash_equals((string)($old['request_sha256']??''),$requestSha) || !hash_equals((string)($old['operation']??''),$operation))
            return ['ok'=>false,'code'=>'REQUEST_ID_CONFLICT'];
        return ['ok'=>true,'code'=>'REQUEST_REPLAY','receipt'=>$old];
    }
    $claim=bin2hex(random_bytes(16));
    $row=['schema'=>1,'session_hash'=>hash('sha256',$sessionId),'request_id'=>$requestId,'operation'=>$operation,
          'request_sha256'=>$requestSha,'status'=>'IN_PROGRESS','claim_hash'=>hash('sha256',$claim),'result'=>null,
          'created_at'=>gmdate('c'),'expires_at'=>time()+max(60,min(3600,$ttl))];
    if (!kicomResilienceJsonWrite($file,$row)) return ['ok'=>false,'code'=>'RECEIPT_WRITE_FAILED'];
    return ['ok'=>true,'code'=>'REQUEST_CLAIMED','claim'=>$claim];
}

function kicomResilienceReceiptCommit(string $sessionId,string $requestId,string $claim,array $result): array {
    $file=kicomResilienceReceiptFile($sessionId,$requestId); if($file===null) return ['ok'=>false,'code'=>'RECEIPT_INPUT_INVALID'];
    $row=kicomResilienceJsonRead($file); if(!is_array($row)) return ['ok'=>false,'code'=>'RECEIPT_NOT_FOUND'];
    if (!hash_equals((string)($row['claim_hash']??''),hash('sha256',$claim))) return ['ok'=>false,'code'=>'RECEIPT_CLAIM_INVALID'];
    if (($row['status']??'')==='DONE') return ['ok'=>true,'code'=>'REQUEST_ALREADY_COMMITTED','receipt'=>$row];
    $safe=kicomResilienceSanitize($result);
    $row['status']='DONE'; $row['result']=$safe; $row['completed_at']=gmdate('c');
    if (!kicomResilienceJsonWrite($file,$row)) return ['ok'=>false,'code'=>'RECEIPT_WRITE_FAILED'];
    return ['ok'=>true,'code'=>'REQUEST_COMMITTED','receipt'=>$row];
}
