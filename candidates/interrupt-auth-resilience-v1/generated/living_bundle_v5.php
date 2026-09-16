<?php
declare(strict_types=1);

/* GENERATED KiCom interrupt/auth resilience living bundle.
 * Candidate only. Deterministically built from PROMOTION_MANIFEST runtime_include.
 */

/* BEGIN interrupt_resilience_v1.php sha256=6c688291410de65fd0720a1dc5162bdbdadb700338383f400072f4bf7f4901fe */
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
/* END interrupt_resilience_v1.php */

/* BEGIN interrupt_resilience_v2.php sha256=2b6d7e2781b41fea755367652ece86f18612e688984fd5cf2c6ba262161b87d4 */
/* Receipt semantics v2: a request_id is never automatically re-executed once claimed.
 * Expired IN_PROGRESS becomes UNCERTAIN and requires status inspection. */
function kicomResilienceReceiptBeginV2(string $sessionId,string $requestId,string $operation,string $requestSha,int $ttl=1800): array {
    if(!kicomResilienceEnsure())return ['ok'=>false,'code'=>'RESILIENCE_STORAGE_UNAVAILABLE'];
    $file=kicomResilienceReceiptFile($sessionId,$requestId);$requestSha=strtolower(trim($requestSha));
    if($file===null||!preg_match('/^[a-f0-9]{64}$/',$requestSha))return ['ok'=>false,'code'=>'RECEIPT_INPUT_INVALID'];
    $old=kicomResilienceJsonRead($file);$now=time();
    if(is_array($old)){
        if(!hash_equals((string)($old['request_sha256']??''),$requestSha)||!hash_equals((string)($old['operation']??''),$operation))return ['ok'=>false,'code'=>'REQUEST_ID_CONFLICT'];
        $status=(string)($old['status']??'');
        if($status==='IN_PROGRESS'&&(int)($old['expires_at']??0)<$now){$old['status']='UNCERTAIN';$old['uncertain_at']=gmdate('c');kicomResilienceJsonWrite($file,$old);$status='UNCERTAIN';}
        if(in_array($status,['IN_PROGRESS','UNCERTAIN','DONE'],true))return ['ok'=>true,'code'=>'REQUEST_REPLAY','receipt'=>$old];
        return ['ok'=>false,'code'=>'REQUEST_RECEIPT_STATE_INVALID'];
    }
    $claim=bin2hex(random_bytes(16));$row=['schema'=>2,'session_hash'=>hash('sha256',$sessionId),'request_id'=>$requestId,'operation'=>$operation,'request_sha256'=>$requestSha,'status'=>'IN_PROGRESS','claim_hash'=>hash('sha256',$claim),'result'=>null,'created_at'=>gmdate('c'),'expires_at'=>$now+max(60,min(3600,$ttl))];
    if(!kicomResilienceJsonWrite($file,$row))return ['ok'=>false,'code'=>'RECEIPT_WRITE_FAILED'];return ['ok'=>true,'code'=>'REQUEST_CLAIMED','claim'=>$claim];
}
function kicomResilienceReceiptCommitV2(string $sessionId,string $requestId,string $claim,array $result): array {
    $file=kicomResilienceReceiptFile($sessionId,$requestId);if($file===null)return ['ok'=>false,'code'=>'RECEIPT_INPUT_INVALID'];$row=kicomResilienceJsonRead($file);if(!is_array($row))return ['ok'=>false,'code'=>'RECEIPT_NOT_FOUND'];
    if(!hash_equals((string)($row['claim_hash']??''),hash('sha256',$claim)))return ['ok'=>false,'code'=>'RECEIPT_CLAIM_INVALID'];
    if(($row['status']??'')==='DONE')return ['ok'=>true,'code'=>'REQUEST_ALREADY_COMMITTED','receipt'=>$row];
    if(!in_array((string)($row['status']??''),['IN_PROGRESS','UNCERTAIN'],true))return ['ok'=>false,'code'=>'REQUEST_RECEIPT_STATE_INVALID'];
    $row['status']='DONE';$row['result']=kicomResilienceSanitize($result);$row['completed_at']=gmdate('c');if(!kicomResilienceJsonWrite($file,$row))return ['ok'=>false,'code'=>'RECEIPT_WRITE_FAILED'];return ['ok'=>true,'code'=>'REQUEST_COMMITTED','receipt'=>$row];
}
/* END interrupt_resilience_v2.php */

/* BEGIN request_replay_v3.php sha256=723ad755124f5d4b7c97a44e8833ccd1ee8d575add51689be1606df1f3481bf0 */
/* Exact-response replay v3: recursive canonical fingerprint + encrypted response. */
function kicomReplayDirV3(): string { return kicomAuthDir().'/request_replay_v3'; }
function kicomReplayEnsureV3(): bool {$d=kicomReplayDirV3();if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;$deny=$d.'/.htaccess';if(!is_file($deny))@file_put_contents($deny,"Require all denied\n",LOCK_EX);return true;}
function kicomReplayRequestIdV3(string $id): ?string {$id=trim($id);return preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$id)?$id:null;}
function kicomReplayFileV3(string $sessionId,string $requestId): string {return kicomReplayDirV3().'/'.hash('sha256',$sessionId.'|'.$requestId).'.json';}
function kicomReplaySensitiveKeyV3(string $key): bool {$key=strtolower($key);foreach(['token','session_id','code','recovery_handle','password','secret','totp','authorization','cookie','api_key'] as $n)if(str_contains($key,$n))return true;return false;}
function kicomReplayCanonicalV3(mixed $v): mixed {if(!is_array($v))return $v;$isList=array_is_list($v);$out=[];if($isList){foreach($v as $x)$out[]=kicomReplayCanonicalV3($x);return $out;}foreach($v as $k=>$x){$k=(string)$k;if(kicomReplaySensitiveKeyV3($k))continue;$out[$k]=kicomReplayCanonicalV3($x);}ksort($out,SORT_STRING);return $out;}
function kicomReplayFingerprintV3(string $operation,array $params=[]): string {$canon=kicomReplayCanonicalV3($params);return hash('sha256',json_encode([$operation,$canon],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'');}
function kicomReplayKeyV3(string $presentedToken,string $requestId): string {return hash('sha256','kicom-request-replay-v3|'.$requestId.'|'.$presentedToken,true);}
function kicomReplaySealV3(array $response,string $presentedToken,string $requestId): ?array {if(!function_exists('openssl_encrypt'))return null;$plain=json_encode($response,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($plain===false)return null;$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',kicomReplayKeyV3($presentedToken,$requestId),OPENSSL_RAW_DATA,$iv,$tag,'kicom-request-replay-v3');return $cipher===false?null:['iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'cipher'=>base64_encode($cipher)];}
function kicomReplayOpenV3(array $sealed,string $presentedToken,string $requestId): ?array {if(!function_exists('openssl_decrypt'))return null;$iv=base64_decode((string)($sealed['iv']??''),true);$tag=base64_decode((string)($sealed['tag']??''),true);$cipher=base64_decode((string)($sealed['cipher']??''),true);if($iv===false||$tag===false||$cipher===false)return null;$plain=openssl_decrypt($cipher,'aes-256-gcm',kicomReplayKeyV3($presentedToken,$requestId),OPENSSL_RAW_DATA,$iv,$tag,'kicom-request-replay-v3');if($plain===false)return null;$r=json_decode($plain,true);return is_array($r)?$r:null;}
function kicomReplayLookupV3(string $sessionId,string $requestId,string $operation,array $params,string $presentedToken): array {$requestId=kicomReplayRequestIdV3($requestId)??'';if($requestId==='')return ['ok'=>false,'code'=>'REPLAY_REQUEST_ID_INVALID'];$row=kicomAuthJsonRead(kicomReplayFileV3($sessionId,$requestId));if(!is_array($row))return ['ok'=>false,'code'=>'REPLAY_MISS'];if((int)($row['expires_at']??0)<time())return ['ok'=>false,'code'=>'REPLAY_EXPIRED'];if(!hash_equals((string)($row['session_hash']??''),hash('sha256',$sessionId)))return ['ok'=>false,'code'=>'REPLAY_SESSION_MISMATCH'];if(!hash_equals((string)($row['fingerprint']??''),kicomReplayFingerprintV3($operation,$params)))return ['ok'=>false,'code'=>'REPLAY_FINGERPRINT_MISMATCH'];if(!hash_equals((string)($row['presented_token_hash']??''),hash('sha256',$presentedToken)))return ['ok'=>false,'code'=>'REPLAY_TOKEN_MISMATCH'];$response=kicomReplayOpenV3(is_array($row['sealed']??null)?$row['sealed']:[],$presentedToken,$requestId);return is_array($response)?['ok'=>true,'code'=>'REPLAY_HIT','response'=>$response,'replayed'=>true]:['ok'=>false,'code'=>'REPLAY_DECRYPT_FAILED'];}
function kicomReplayStoreV3(string $sessionId,string $requestId,string $operation,array $params,string $presentedToken,array $response,int $ttl=3600): array {$requestId=kicomReplayRequestIdV3($requestId)??'';if($requestId==='')return ['ok'=>false,'code'=>'REPLAY_REQUEST_ID_INVALID'];if(!kicomReplayEnsureV3())return ['ok'=>false,'code'=>'REPLAY_STORAGE_UNAVAILABLE'];$sealed=kicomReplaySealV3($response,$presentedToken,$requestId);if($sealed===null)return ['ok'=>false,'code'=>'REPLAY_SEAL_FAILED'];$row=['schema'=>3,'session_hash'=>hash('sha256',$sessionId),'request_id_hash'=>hash('sha256',$requestId),'fingerprint'=>kicomReplayFingerprintV3($operation,$params),'presented_token_hash'=>hash('sha256',$presentedToken),'sealed'=>$sealed,'created_at'=>gmdate('c'),'expires_at'=>time()+max(60,min(3600,$ttl))];$file=kicomReplayFileV3($sessionId,$requestId);if(is_file($file)){$old=kicomAuthJsonRead($file);if(is_array($old)){if(!hash_equals((string)($old['fingerprint']??''),$row['fingerprint'])||!hash_equals((string)($old['presented_token_hash']??''),$row['presented_token_hash']))return ['ok'=>false,'code'=>'REPLAY_REQUEST_ID_CONFLICT'];return ['ok'=>true,'code'=>'REPLAY_ALREADY_STORED'];}}if(!kicomAuthJsonWrite($file,$row))return ['ok'=>false,'code'=>'REPLAY_WRITE_FAILED'];return ['ok'=>true,'code'=>'REPLAY_STORED'];}
/* END request_replay_v3.php */

/* BEGIN request_guard_v5.php sha256=e582fb1db7cb5cf110b78655ef8d01875e64da0c8fb7b2c38f78d4ca907f04fe */
function kicomRequestGuardRequestShaV5(string $operation,array $params,string $presentedToken): string {return hash('sha256',$operation.'|'.kicomReplayFingerprintV3($operation,$params).'|'.hash('sha256',$presentedToken));}
function kicomRequestGuardLockFileV5(string $sessionId,string $requestId): ?string {$f=kicomResilienceReceiptFile($sessionId,$requestId);return $f===null?null:$f.'.guard.lock';}
function kicomRequestGuardBeginV5(string $sessionId,string $requestId,string $operation,array $params,string $presentedToken): array {
    $rid=kicomReplayRequestIdV3($requestId)??'';if($rid==='')return ['ok'=>false,'code'=>'REQUEST_ID_INVALID'];$lf=kicomRequestGuardLockFileV5($sessionId,$rid);if($lf===null)return ['ok'=>false,'code'=>'REQUEST_ID_INVALID'];$lock=@fopen($lf,'c+');if(is_resource($lock))@chmod($lf,0600);if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);return ['ok'=>false,'code'=>'REQUEST_GUARD_LOCK_FAILED'];}
    try{$sha=kicomRequestGuardRequestShaV5($operation,$params,$presentedToken);$claim=kicomResilienceReceiptBeginV2($sessionId,$rid,$operation,$sha,1800);if(empty($claim['ok']))return $claim;if(($claim['code']??'')==='REQUEST_REPLAY'){$receipt=is_array($claim['receipt']??null)?$claim['receipt']:[];$status=(string)($receipt['status']??'');if($status==='IN_PROGRESS')return ['ok'=>true,'code'=>'REQUEST_IN_FLIGHT','mode'=>'IN_FLIGHT','retry_same_request'=>true];if($status==='UNCERTAIN')return ['ok'=>true,'code'=>'REQUEST_UNCERTAIN','mode'=>'UNCERTAIN','inspect_required'=>true];if($status==='DONE'){$replay=kicomReplayLookupV3($sessionId,$rid,$operation,$params,$presentedToken);if(!empty($replay['ok']))return ['ok'=>true,'code'=>'REQUEST_REPLAY','mode'=>'REPLAY','response'=>$replay['response'],'replayed'=>true];return ['ok'=>false,'code'=>'REQUEST_REPLAY_UNAVAILABLE','detail'=>(string)($replay['code']??'UNKNOWN'),'inspect_required'=>true];}return ['ok'=>false,'code'=>'REQUEST_RECEIPT_STATE_INVALID'];}return ['ok'=>true,'code'=>'REQUEST_EXECUTE','mode'=>'EXECUTE','claim'=>(string)($claim['claim']??''),'replayed'=>false];}finally{@flock($lock,LOCK_UN);@fclose($lock);}
}
function kicomRequestGuardCompleteV5(string $sessionId,string $requestId,string $operation,array $params,string $presentedToken,string $claim,array $response): array {
    $lf=kicomRequestGuardLockFileV5($sessionId,$requestId);if($lf===null)return ['ok'=>false,'code'=>'REQUEST_ID_INVALID'];$lock=@fopen($lf,'c+');if(is_resource($lock))@chmod($lf,0600);if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);return ['ok'=>false,'code'=>'REQUEST_GUARD_LOCK_FAILED'];}
    try{$replay=kicomReplayStoreV3($sessionId,$requestId,$operation,$params,$presentedToken,$response,3600);if(empty($replay['ok']))return ['ok'=>false,'code'=>'REQUEST_REPLAY_STORE_FAILED','detail'=>(string)($replay['code']??'UNKNOWN')];$summary=['code'=>(string)($response['code']??(!empty($response['ok'])?'OK':'FAILED')),'ok'=>!empty($response['ok']),'response_sha256'=>hash('sha256',json_encode($response,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'')];$commit=kicomResilienceReceiptCommitV2($sessionId,$requestId,$claim,$summary);if(empty($commit['ok']))return ['ok'=>false,'code'=>'REQUEST_RECEIPT_COMMIT_FAILED','detail'=>(string)($commit['code']??'UNKNOWN')];return ['ok'=>true,'code'=>'REQUEST_COMMITTED'];}finally{@flock($lock,LOCK_UN);@fclose($lock);}
}
/* END request_guard_v5.php */

/* BEGIN session_lock_v2.php sha256=034cb873a5255a83288f832640b9c40ec5b5c396455b227e813cc3f216cb750d */
function kicomNormalSessionLockFile(string $sessionId): string {
    return kicomAuthSessionsDir().'/'.$sessionId.'.normal.lock';
}
/* END session_lock_v2.php */

/* BEGIN session_consume_resilient_v3.php sha256=f25c5fe53f463f0036f3279c52ee9dc412058da46e33b6058ae3fe6e9e6010d7 */
/* Request-bound rolling-token consumer. For resilient requests, the 60-second
 * previous-token fallback is valid only for the same request_id that rotated it. */
function kicomAutonomySessionConsumeResilientV3(string $id,string $token,string $requestId=''): array {
    $policy=kicomAutonomyPolicy();if(empty($policy['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];
    $id=strtolower(trim($id));$token=strtolower(trim($token));$requestId=trim($requestId);
    if(!preg_match('/^[a-f0-9]{24}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$token))return ['ok'=>false,'code'=>'SESSION_AUTH_INVALID'];
    if($requestId!==''&&!preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$requestId))return ['ok'=>false,'code'=>'REQUEST_ID_INVALID'];
    $requestHash=$requestId===''?'':hash('sha256',$requestId);
    $file=kicomAutonomySessionFile($id);$lock=@fopen(kicomNormalSessionLockFile($id),'c+');if(is_resource($lock))@chmod(kicomNormalSessionLockFile($id),0600);
    if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);return ['ok'=>false,'code'=>'SESSION_LOCK_FAILED'];}
    try{
        $r=kicomAuthJsonRead($file);if(!is_array($r))return ['ok'=>false,'code'=>'SESSION_NOT_FOUND'];$now=time();
        if((int)($r['absolute_expires_at']??0)<$now||(int)($r['idle_expires_at']??0)<$now){@unlink($file);return ['ok'=>false,'code'=>'SESSION_EXPIRED'];}
        $present=hash('sha256',$token);$current=(string)($r['token_hash']??'');$previous=(string)($r['previous_token_hash']??'');$previousUntil=(int)($r['previous_token_until']??0);
        $normal=$current!==''&&hash_equals($current,$present);$recover=!$normal&&$previous!==''&&$previousUntil>=$now&&hash_equals($previous,$present);
        if($recover&&$requestHash!==''){$bound=(string)($r['previous_token_request_hash']??'');if($bound===''||!hash_equals($bound,$requestHash))return ['ok'=>false,'code'=>'SESSION_PREVIOUS_TOKEN_REQUEST_MISMATCH'];}
        if(!$normal&&!$recover)return ['ok'=>false,'code'=>'SESSION_TOKEN_REJECTED'];
        $next=kicomAuthRandomHex(32);
        if($normal){$r['previous_token_hash']=$current;$r['previous_token_until']=$now+60;$r['previous_token_request_hash']=$requestHash;}
        else{$r['previous_token_hash']='';$r['previous_token_until']=0;$r['previous_token_request_hash']='';$r['recovered_at']=gmdate('c');}
        $r['token_hash']=hash('sha256',$next);$r['last_used_at']=gmdate('c');$r['idle_expires_at']=min((int)$r['absolute_expires_at'],$now+(int)$policy['idle_ttl']);
        if(!kicomAuthJsonWrite($file,$r))return ['ok'=>false,'code'=>'SESSION_ROTATE_FAILED'];
        return ['ok'=>true,'session_id'=>$id,'next_token'=>$next,'expires_at'=>(int)$r['absolute_expires_at'],'idle_expires_at'=>(int)$r['idle_expires_at'],'recovered'=>$recover,'request_bound'=>$requestHash!==''];
    }finally{@flock($lock,LOCK_UN);@fclose($lock);}
}
/* END session_consume_resilient_v3.php */

/* BEGIN session_consume_bridge_v1.php sha256=9403a72d7dfc28856569b7cb46b6a5ff223b896ea5f58bb9da3eebb955163b20 */
/* Compatibility bridge used after the original live consumer is renamed to
 * kicomAutonomySessionConsumeLegacyV5(). Legacy callers remain on the existing
 * path unless a deferred resilient client_request_id is active. */
function kicomAutonomySessionConsumeBridgeV1(string $id,string $token): array {
    $requestId=function_exists('kicomDeferredGuardClientRequestIdV1')?kicomDeferredGuardClientRequestIdV1():'';
    if($requestId!=='')return kicomAutonomySessionConsumeResilientV3($id,$token,$requestId);
    return kicomAutonomySessionConsumeLegacyV5($id,$token);
}
/* END session_consume_bridge_v1.php */

/* BEGIN session_open_resilient_v2.php sha256=b1e99757d0ce096fd8f0a4ddb5d9e99dea2878295c0efd7398247ebe2b87f159 */
/* Idempotent normal-session open. A successfully consumed FreeOTP open can be
 * replayed briefly for the same request_id/code only while its initial action
 * token is still current. No secondary recovery bearer is created. */
function kicomSessionOpenResilienceDirV2(): string { return kicomAuthDir().'/session_open_resilience_v2'; }
function kicomSessionOpenResilienceKeyFileV2(): string { return kicomSessionOpenResilienceDirV2().'/master.key'; }
function kicomSessionOpenResilienceReceiptsDirV2(): string { return kicomSessionOpenResilienceDirV2().'/receipts'; }
function kicomSessionOpenResilienceEnsureV2(): bool {
    foreach([kicomSessionOpenResilienceDirV2(),kicomSessionOpenResilienceReceiptsDirV2()] as $d)if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))return false;
    $deny=kicomSessionOpenResilienceDirV2().'/.htaccess';if(!is_file($deny))@file_put_contents($deny,"Require all denied\n",LOCK_EX);
    $kf=kicomSessionOpenResilienceKeyFileV2();if(!is_file($kf)){$key=random_bytes(32);if(@file_put_contents($kf,$key,LOCK_EX)===false)return false;@chmod($kf,0600);} $raw=@file_get_contents($kf);return is_string($raw)&&strlen($raw)===32;
}
function kicomSessionOpenResilienceKeyV2(): ?string { if(!kicomSessionOpenResilienceEnsureV2())return null;$raw=@file_get_contents(kicomSessionOpenResilienceKeyFileV2());return is_string($raw)&&strlen($raw)===32?$raw:null; }
function kicomSessionOpenRequestIdV2(string $requestId): ?string {$requestId=trim($requestId);return preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$requestId)?$requestId:null;}
function kicomSessionOpenReceiptFileV2(string $requestId): ?string {$requestId=kicomSessionOpenRequestIdV2($requestId)??'';return $requestId===''?null:kicomSessionOpenResilienceReceiptsDirV2().'/'.hash('sha256',$requestId).'.json';}
function kicomSessionOpenCleanupV2(): void {$now=time();foreach(glob(kicomSessionOpenResilienceReceiptsDirV2().'/*.json')?:[] as $f){$r=kicomAuthJsonRead($f);if(!is_array($r)||(int)($r['expires_at']??0)<$now){@unlink($f);@unlink($f.'.lock');}}}
function kicomSessionOpenDeriveTokenV2(string $master,string $sessionId,string $requestId,string $nonce): string {return hash_hmac('sha256','kicom-open-v2|token|'.$sessionId.'|'.$requestId.'|'.$nonce,$master);}
function kicomSessionOpenIdempotentV2(string $code,string $requestId): array {
    $requestId=kicomSessionOpenRequestIdV2($requestId)??'';if($requestId==='')return ['ok'=>false,'code'=>'SESSION_OPEN_REQUEST_ID_INVALID'];
    $master=kicomSessionOpenResilienceKeyV2();if($master===null)return ['ok'=>false,'code'=>'SESSION_OPEN_RESILIENCE_UNAVAILABLE'];kicomSessionOpenCleanupV2();
    $rf=kicomSessionOpenReceiptFileV2($requestId);if($rf===null)return ['ok'=>false,'code'=>'SESSION_OPEN_REQUEST_ID_INVALID'];$lock=@fopen($rf.'.lock','c+');if(is_resource($lock))@chmod($rf.'.lock',0600);
    if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);return ['ok'=>false,'code'=>'SESSION_OPEN_LOCK_FAILED'];}
    try{
        $now=time();$codeTag=hash_hmac('sha256',$code,$master);$old=kicomAuthJsonRead($rf);
        if(is_array($old)&&(int)($old['expires_at']??0)>=$now){
            if(!hash_equals((string)($old['code_tag']??''),$codeTag))return ['ok'=>false,'code'=>'SESSION_OPEN_REQUEST_CONFLICT'];
            $sid=(string)($old['session_id']??'');$nonce=(string)($old['nonce']??'');if(!preg_match('/^[a-f0-9]{24}$/',$sid))return ['ok'=>false,'code'=>'SESSION_OPEN_REPLAY_SESSION_MISSING'];
            $sl=@fopen(kicomNormalSessionLockFile($sid),'c+');if(is_resource($sl))@chmod(kicomNormalSessionLockFile($sid),0600);if($sl===false||!@flock($sl,LOCK_EX)){if(is_resource($sl))@fclose($sl);return ['ok'=>false,'code'=>'SESSION_LOCK_FAILED'];}
            try{$row=kicomAuthJsonRead(kicomAutonomySessionFile($sid));if(!is_array($row))return ['ok'=>false,'code'=>'SESSION_OPEN_REPLAY_SESSION_MISSING'];if((int)($row['absolute_expires_at']??0)<$now||(int)($row['idle_expires_at']??0)<$now)return ['ok'=>false,'code'=>'SESSION_EXPIRED'];$token=kicomSessionOpenDeriveTokenV2($master,$sid,$requestId,$nonce);if(!hash_equals((string)($row['token_hash']??''),hash('sha256',$token)))return ['ok'=>false,'code'=>'SESSION_OPEN_REPLAY_SUPERSEDED'];return ['ok'=>true,'code'=>'SESSION_OPEN_REPLAY','session_id'=>$sid,'token'=>$token,'expires_in'=>max(0,(int)$row['absolute_expires_at']-$now),'idle_expires_in'=>max(0,(int)$row['idle_expires_at']-$now),'replayed'=>true];}finally{@flock($sl,LOCK_UN);@fclose($sl);}
        }
        $policy=kicomAutonomyPolicy();if(empty($policy['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];$grace=max(0,min(4,(int)($policy['session_totp_past_steps']??2)));$v=kicomTotpVerifyConsumeWindow($code,'autonomy_session_open',$grace);if(empty($v['ok']))return $v;
        $sid=substr(kicomAuthRandomHex(12),0,24);$nonce=kicomAuthRandomHex(16);$token=kicomSessionOpenDeriveTokenV2($master,$sid,$requestId,$nonce);
        $row=['schema'=>1,'id'=>$sid,'token_hash'=>hash('sha256',$token),'created_at'=>gmdate('c'),'created_epoch'=>$now,'last_used_at'=>gmdate('c'),'absolute_expires_at'=>$now+(int)$policy['session_ttl'],'idle_expires_at'=>$now+(int)$policy['idle_ttl'],'scope'=>'autonomy'];
        if(!kicomAuthJsonWrite(kicomAutonomySessionFile($sid),$row))return ['ok'=>false,'code'=>'SESSION_WRITE_FAILED'];
        $receipt=['schema'=>2,'request_hash'=>hash('sha256',$requestId),'code_tag'=>$codeTag,'session_id'=>$sid,'nonce'=>$nonce,'created_at'=>gmdate('c'),'expires_at'=>$now+180];
        if(!kicomAuthJsonWrite($rf,$receipt)){@unlink(kicomAutonomySessionFile($sid));return ['ok'=>false,'code'=>'SESSION_OPEN_RECEIPT_WRITE_FAILED'];}
        kicomLivingEvent('autonomy_session_opened','info',['session_id'=>$sid,'totp_age_steps'=>(int)($v['age_steps']??0),'idempotent_open_v2'=>true]);
        return ['ok'=>true,'code'=>'SESSION_OPENED','session_id'=>$sid,'token'=>$token,'expires_in'=>(int)$policy['session_ttl'],'idle_expires_in'=>(int)$policy['idle_ttl'],'totp_age_steps'=>(int)($v['age_steps']??0),'totp_period'=>(int)($v['period']??30),'replayed'=>false];
    }finally{@flock($lock,LOCK_UN);@fclose($lock);}
}
/* END session_open_resilient_v2.php */

/* BEGIN guarded_action_v5.php sha256=84f09d23eeae1fba2cf55ecbd9249775dd162dd462a2095ed2a3d8659b05033a */
function kicomGuardedNormalActionV5(string $sessionId,string $presentedToken,string $requestId,string $operation,array $params,callable $action): array {
    $g=kicomRequestGuardBeginV5($sessionId,$requestId,$operation,$params,$presentedToken);if(empty($g['ok']))return $g;$mode=(string)($g['mode']??'');if($mode==='REPLAY')return is_array($g['response']??null)?$g['response']:['ok'=>false,'code'=>'REQUEST_REPLAY_INVALID'];if($mode==='IN_FLIGHT')return ['ok'=>false,'code'=>'REQUEST_IN_FLIGHT','retry_same_request'=>true,'request_id'=>$requestId];if($mode==='UNCERTAIN')return ['ok'=>false,'code'=>'REQUEST_UNCERTAIN','inspect_required'=>true,'request_id'=>$requestId];if($mode!=='EXECUTE')return ['ok'=>false,'code'=>'REQUEST_GUARD_MODE_INVALID'];$claim=(string)($g['claim']??'');$ss=kicomAutonomySessionConsumeResilientV3($sessionId,$presentedToken,$requestId);
    if(empty($ss['ok'])){$response=$ss;$done=kicomRequestGuardCompleteV5($sessionId,$requestId,$operation,$params,$presentedToken,$claim,$response);return empty($done['ok'])?($done+['original_response'=>$response]):$response;}
    try{$response=$action($ss);if(!is_array($response))$response=['ok'=>false,'code'=>'ACTION_RESPONSE_INVALID'];}catch(Throwable $e){$response=['ok'=>false,'code'=>'ACTION_EXCEPTION'];}$response['session_id']=(string)$ss['session_id'];$response['next_token']=(string)$ss['next_token'];$response['expires_at']=(int)$ss['expires_at'];$response['idle_expires_at']=(int)$ss['idle_expires_at'];$done=kicomRequestGuardCompleteV5($sessionId,$requestId,$operation,$params,$presentedToken,$claim,$response);if(empty($done['ok']))return ['ok'=>false,'code'=>'REQUEST_REPLAY_PERSIST_FAILED','detail'=>(string)($done['code']??'UNKNOWN'),'action_response'=>$response,'inspect_required'=>true];return $response;
}
/* END guarded_action_v5.php */

/* BEGIN router_deferred_guard_v1.php sha256=05065534191e7a5e17928be4a095f1339a1d073554babc12aa99121824cc50be */
/* Candidate integration helper for KiCom 0.9.14.
 * External protocol uses client_request_id; FACT request_id remains the server request id.
 * Requires request_guard_v5.php and request_replay_v3.php.
 */
function kicomClientRequestIdV2(array $get,array $post=[]): string {
    $v=(string)($post['client_request_id']??$get['client_request_id']??'');
    return preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$v)?$v:'';
}
function kicomDeferredGuardContextV1(): ?array {
    $x=$GLOBALS['kicom_deferred_guard_v1']??null;
    return is_array($x)?$x:null;
}
function kicomDeferredGuardClientRequestIdV1(): string {
    $x=kicomDeferredGuardContextV1();
    return is_array($x)?(string)($x['client_request_id']??''):'';
}
function kicomDeferredGuardClearV1(): void {
    unset($GLOBALS['kicom_deferred_guard_v1']);
}
function kicomDeferredGuardPreflightV1(string $sessionId,string $presentedToken,string $clientRequestId,string $operation,array $params): array {
    $clientRequestId=trim($clientRequestId);
    if($clientRequestId==='')return ['ok'=>true,'mode'=>'LEGACY'];
    $g=kicomRequestGuardBeginV5($sessionId,$clientRequestId,$operation,$params,$presentedToken);
    if(empty($g['ok']))return $g;
    $mode=(string)($g['mode']??'');
    if($mode==='REPLAY'){
        $r=is_array($g['response']??null)?$g['response']:[];
        $lines=$r['lines']??null;$status=(int)($r['status']??0);
        if(!is_array($lines)||$status<100||$status>599)return ['ok'=>false,'code'=>'REQUEST_REPLAY_INVALID'];
        return ['ok'=>true,'mode'=>'REPLAY','lines'=>array_values(array_map('strval',$lines)),'status'=>$status];
    }
    if($mode==='IN_FLIGHT')return ['ok'=>true,'mode'=>'IN_FLIGHT','code'=>'REQUEST_IN_FLIGHT'];
    if($mode==='UNCERTAIN')return ['ok'=>true,'mode'=>'UNCERTAIN','code'=>'REQUEST_UNCERTAIN'];
    if($mode!=='EXECUTE')return ['ok'=>false,'code'=>'REQUEST_GUARD_MODE_INVALID'];
    $GLOBALS['kicom_deferred_guard_v1']=[
        'session_id'=>$sessionId,
        'presented_token'=>$presentedToken,
        'client_request_id'=>$clientRequestId,
        'operation'=>$operation,
        'params'=>$params,
        'claim'=>(string)($g['claim']??''),
    ];
    return ['ok'=>true,'mode'=>'EXECUTE'];
}
function kicomDeferredGuardCompleteV1(array $lines,int $status): array {
    $ctx=kicomDeferredGuardContextV1();
    if(!is_array($ctx))return ['ok'=>true,'code'=>'NO_DEFERRED_GUARD'];
    kicomDeferredGuardClearV1();
    $response=['status'=>$status,'lines'=>array_values(array_map('strval',$lines))];
    return kicomRequestGuardCompleteV5(
        (string)$ctx['session_id'],(string)$ctx['client_request_id'],(string)$ctx['operation'],
        is_array($ctx['params']??null)?$ctx['params']:[],(string)$ctx['presented_token'],(string)$ctx['claim'],$response
    );
}
function kicomDeferredGuardErrorLinesV1(string $serverRequestId,string $clientRequestId,string $code): array {
    return [KCL_PROTOCOL,'ERROR request_recovery','FACT request_id='.kclString($serverRequestId),'FACT client_request_id='.kclString($clientRequestId),'FACT code='.kclString($code),'RULE do-not-reexecute-blindly','END'];
}
/* END router_deferred_guard_v1.php */

/* BEGIN approval_status_v1.php sha256=198a1c494b2e7fcc4a74553f4b5bde8236e32f0ce83b0293c885bea73891e139 */
/* Read-only recovery for lost critical-execute responses.
 * Requires approval_id + exact binding_sha256. Grants no authority. */
function kicomAuthApprovalStatusReadV1(string $approvalId,string $bindingSha256): array {
    $approvalId=strtolower(trim($approvalId));$bindingSha256=strtolower(trim($bindingSha256));
    if(!preg_match('/^[a-f0-9]{24}$/',$approvalId)||!preg_match('/^[a-f0-9]{64}$/',$bindingSha256))return ['ok'=>false,'code'=>'APPROVAL_STATUS_INPUT_INVALID'];
    $row=kicomAuthApprovalGet($approvalId);if(!is_array($row))return ['ok'=>false,'code'=>'APPROVAL_NOT_FOUND'];
    if(!hash_equals((string)($row['binding_sha256']??''),$bindingSha256))return ['ok'=>false,'code'=>'APPROVAL_BINDING_MISMATCH'];
    $stored=(string)($row['status']??'unknown');$effective=$stored;
    if($stored==='pending'&&(int)($row['expires_at']??0)<time())$effective='expired';
    return ['ok'=>true,'code'=>'OK','approval_id'=>$approvalId,'action'=>(string)($row['action']??''),'risk'=>(string)($row['risk']??''),'status'=>$effective,'stored_status'=>$stored,'terminal'=>in_array($effective,['used','failed','expired'],true),'binding_sha256'=>$bindingSha256,'created_at'=>(string)($row['created_at']??''),'expires_at'=>(int)($row['expires_at']??0),'used_at'=>(string)($row['used_at']??''),'result_code'=>(string)($row['result_code']??'')];
}
/* END approval_status_v1.php */

/* BEGIN update_pending_inspect_v1.php sha256=de952b0b8a45a9378c2a1a670558f54a5c01a052775253c83c6f92ecffcabc0a */
/* Read-only self-update pending inspector. No package mutation or authorization. */
function kicomUpdatePendingInspectV1(): array {
    $p=kicomSelfUpdatePending();
    if(!is_array($p))return ['ok'=>true,'code'=>'NO_PENDING','pending'=>false];
    $changed=[];foreach(($p['changed_paths']??[]) as $path)if(is_string($path)){$changed[]=substr($path,0,240);if(count($changed)>=128)break;}
    $reasons=[];foreach(($p['risk_reasons']??[]) as $reason)if(is_string($reason)){$reasons[]=substr($reason,0,300);if(count($reasons)>=64)break;}
    return ['ok'=>true,'code'=>'OK','pending'=>true,
        'from_version'=>(string)($p['from_version']??''),'to_version'=>(string)($p['to_version']??''),
        'zip_sha256'=>(string)($p['zip_sha256']??''),'manifest_sha256'=>(string)($p['manifest_sha256']??''),
        'genome_id'=>(string)($p['genome_id']??''),'genome_sha256'=>(string)($p['genome_sha256']??''),
        'kernel_update'=>!empty($p['kernel_update']),'zip_bytes'=>(int)($p['zip_bytes']??0),'files_count'=>(int)($p['files_count']??0),
        'install_files_count'=>(int)($p['install_files_count']??0),'preserved_state_files'=>(int)($p['preserved_state_files']??0),
        'source'=>(string)($p['source']??''),'risk_class'=>(string)($p['risk_class']??''),'risk_reasons'=>$reasons,'changed_paths'=>$changed,'created_at'=>(string)($p['created_at']??'')];
}
/* END update_pending_inspect_v1.php */

/* BEGIN source_manifest_status_v1.php sha256=f35dedaf1dfa7134cfc695de6131546f7efe5fd577c9b9d60251fc3d15997750 */
/* Public read-only trusted-source manifest status: hashes only, never file content. */
function kicomSourceManifestStatusV1(): array {
    $rows=kicomAutonomySourceList();$out=[];
    foreach($rows as $r){if(!is_array($r))continue;$path=(string)($r['path']??'');$sha=strtolower((string)($r['sha256']??''));if($path===''||!preg_match('/^[a-f0-9]{64}$/',$sha))continue;$out[]=['path'=>$path,'bytes'=>(int)($r['bytes']??0),'sha256'=>$sha];if(count($out)>=128)break;}
    usort($out,fn($a,$b)=>strcmp($a['path'],$b['path']));$canon=json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'';
    return ['ok'=>true,'code'=>'OK','version'=>defined('KICOM_VERSION')?KICOM_VERSION:'','manifest_sha256'=>hash('sha256',$canon),'files'=>$out];
}
/* END source_manifest_status_v1.php */

/* BEGIN release_consistency_status_v1.php sha256=63f963b30b3d28b8822df0b37b6eed6898684e8ae6f315ca5ceffb8965ce5bff */
/* Read-only release/canonical-memory consistency diagnostic.
 * It reports drift only; it never mutates memory, genome, runtime or authorization state. */
function kicomReleaseConsistencyExtractV1(string $raw,string $pattern): string {
    return preg_match($pattern,$raw,$m)?trim((string)($m[1]??'')):'';
}
function kicomReleaseConsistencyStatusV1(): array {
    $runtime=defined('KICOM_VERSION')?(string)constant('KICOM_VERSION'):'';
    $g=function_exists('kicomGenomeCurrent')?kicomGenomeCurrent():null;
    $genomeId=is_array($g)?(string)($g['id']??''):'';
    $generation=is_array($g)?(int)($g['generation']??0):0;
    $names=['PROJECT_STATE','CHANGELOG','NEXT','PROTOCOL'];$res=[];
    foreach($names as $name){$x=kicomReadMemoryResource($name);if(!is_array($x))return ['ok'=>false,'code'=>'CONSISTENCY_MEMORY_UNAVAILABLE','resource'=>$name];$res[$name]=$x;}
    $project=(string)($res['PROJECT_STATE']['raw']??$res['PROJECT_STATE']['content']??'');
    $changelog=(string)($res['CHANGELOG']['raw']??$res['CHANGELOG']['content']??'');
    $next=(string)($res['NEXT']['raw']??$res['NEXT']['content']??'');
    $protocol=(string)($res['PROTOCOL']['raw']??$res['PROTOCOL']['content']??'');
    $projectVersion=kicomReleaseConsistencyExtractV1($project,'/^VERSION\s+"([^"]+)"/m');
    $projectGenome=kicomReleaseConsistencyExtractV1($project,'/^FACT\s+genome_id="([^"]+)"/m');
    $projectGeneration=(int)kicomReleaseConsistencyExtractV1($project,'/^FACT\s+genome_generation=(\d+)/m');
    $projectOk=$runtime!==''&&hash_equals($runtime,$projectVersion)&&$genomeId!==''&&hash_equals($genomeId,$projectGenome)&&$generation>0&&$generation===$projectGeneration;
    $changeOk=$runtime!==''&&str_contains($changelog,'RELEASE "'.$runtime.'"');
    $nextOk=$runtime!==''&&preg_match('/^PRIORITY\s+1\s+goal="[^"]*'.preg_quote($runtime,'/').'[^"]*"/m',$next)===1;
    $batchLive=function_exists('kicomAuthPrepareWorkspaceProposalBatch');
    $protocolBatchCandidate=str_contains($protocol,'workspace_batch="candidate only')||str_contains($protocol,'workspace batch candidate');
    $protocolBatchLive=str_contains($protocol,'workspace_proposal_batch')||str_contains($protocol,'AUTH workspace_batch=');
    $protocolOk=!$batchLive||($protocolBatchLive&&!$protocolBatchCandidate);
    $stale=[];if(!$projectOk)$stale[]='PROJECT_STATE';if(!$changeOk)$stale[]='CHANGELOG';if(!$nextOk)$stale[]='NEXT';if(!$protocolOk)$stale[]='PROTOCOL';
    $hashes=[];foreach($names as $name)$hashes[$name]=(string)($res[$name]['sha256']??'');
    return ['ok'=>true,'code'=>empty($stale)?'CONSISTENT':'CANONICAL_MEMORY_STALE','consistent'=>empty($stale),'runtime_version'=>$runtime,'genome_id'=>$genomeId,'genome_generation'=>$generation,'project_state_version'=>$projectVersion,'project_state_genome_id'=>$projectGenome,'project_state_generation'=>$projectGeneration,'project_state_consistent'=>$projectOk,'changelog_has_runtime_release'=>$changeOk,'next_priority1_has_runtime'=>$nextOk,'protocol_workspace_batch_consistent'=>$protocolOk,'stale_resources'=>$stale,'memory_sha256'=>$hashes];
}
/* END release_consistency_status_v1.php */

/* BEGIN route_helpers_v1.php sha256=e134c45c186573b03341e07409cd11c5e9d3b05d821a48d2cc7c3e1228f2f042 */
function kicomClientRequestIdV1(array $get,array $post=[]): string {
    $v=(string)($post['client_request_id']??$get['client_request_id']??'');
    return preg_match('/^[A-Za-z0-9_.:-]{16,80}$/',$v)?$v:'';
}
function kicomRouteAuthSessionOpenV1(string $code,string $clientRequestId): array {
    if($clientRequestId!=='')return kicomSessionOpenIdempotentV2($code,$clientRequestId);
    return kicomAutonomySessionOpen($code);
}
function kicomRouteApprovalStatusV1(string $approvalId,string $bindingSha256): array {return kicomAuthApprovalStatusReadV1($approvalId,$bindingSha256);}
function kicomApprovalStatusKclLinesV1(array $r,string $serverRequestId): array {
    if(empty($r['ok']))return [KCL_PROTOCOL,'ERROR auth_approval_status','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'FAILED')),'END'];
    return [KCL_PROTOCOL,'OK auth_approval_status','FACT request_id='.kclString($serverRequestId),'FACT code="OK"','FACT approval_id='.kclString((string)$r['approval_id']),'FACT action='.kclString((string)$r['action']),'FACT risk='.kclString((string)$r['risk']),'FACT status='.kclString((string)$r['status']),'FACT terminal='.(!empty($r['terminal'])?'true':'false'),'FACT binding_sha256='.kclString((string)$r['binding_sha256']),'FACT expires_at='.(int)$r['expires_at'],'FACT used_at='.kclString((string)$r['used_at']),'FACT result_code='.kclString((string)$r['result_code']),'RULE read-only-no-authorization','END'];
}
function kicomRoutePendingInspectAuthorizedV1(string $sessionId,string $token): array {
    $s=kicomAutonomySessionPeek(strtolower(trim($sessionId)),strtolower(trim($token)));
    if(empty($s['ok']))return ['ok'=>false,'code'=>(string)($s['code']??'SESSION_REJECTED')];
    $r=kicomUpdatePendingInspectV1();
    if(empty($r['ok']))return $r;
    $r['authorized_session_id']=(string)($s['session_id']??'');
    return $r;
}
function kicomPendingInspectKclLinesV1(array $r,string $serverRequestId): array {
    if(empty($r['ok']))return [KCL_PROTOCOL,'ERROR update_pending_inspect','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'FAILED')),'RULE authorized-read-only','END'];
    $lines=[KCL_PROTOCOL,'OK update_pending_inspect','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'OK')),'FACT pending='.(!empty($r['pending'])?'true':'false')];
    if(!empty($r['pending'])){foreach(['from_version','to_version','zip_sha256','manifest_sha256','genome_id','genome_sha256','source','risk_class','created_at'] as $k)$lines[]='FACT '.$k.'='.kclString((string)($r[$k]??''));$lines[]='FACT kernel_update='.(!empty($r['kernel_update'])?'true':'false');$lines[]='FACT files_count='.(int)($r['files_count']??0);$lines[]='FACT install_files_count='.(int)($r['install_files_count']??0);foreach(($r['risk_reasons']??[]) as $i=>$x)$lines[]='RISK #'.($i+1).'. reason='.kclString((string)$x);foreach(($r['changed_paths']??[]) as $i=>$x)$lines[]='CHANGED #'.($i+1).'. path='.kclString((string)$x);}
    $lines[]='RULE authorized-read-only-no-token-rotation';$lines[]='RULE read-only-no-package-mutation';$lines[]='END';return $lines;
}
function kicomSourceManifestKclLinesV1(array $r,string $serverRequestId): array {
    $lines=[KCL_PROTOCOL,'OK source_manifest_status','FACT request_id='.kclString($serverRequestId),'FACT version='.kclString((string)($r['version']??'')),'FACT manifest_sha256='.kclString((string)($r['manifest_sha256']??'')),'FACT files='.count($r['files']??[])];
    foreach(($r['files']??[]) as $i=>$f)$lines[]='FILE #'.($i+1).'. path='.kclString((string)($f['path']??'')).' bytes='.(int)($f['bytes']??0).' sha256='.kclString((string)($f['sha256']??''));$lines[]='RULE hashes-only-no-source-content';$lines[]='END';return $lines;
}
function kicomRouteReleaseConsistencyV1(): array {return kicomReleaseConsistencyStatusV1();}
function kicomReleaseConsistencyKclLinesV1(array $r,string $serverRequestId): array {
    if(empty($r['ok']))return [KCL_PROTOCOL,'ERROR release_consistency_status','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'FAILED')),'END'];
    $lines=[KCL_PROTOCOL,(!empty($r['consistent'])?'OK':'WARN').' release_consistency_status','FACT request_id='.kclString($serverRequestId),'FACT code='.kclString((string)($r['code']??'OK')),'FACT consistent='.(!empty($r['consistent'])?'true':'false'),'FACT runtime_version='.kclString((string)($r['runtime_version']??'')),'FACT genome_id='.kclString((string)($r['genome_id']??'')),'FACT genome_generation='.(int)($r['genome_generation']??0),'FACT project_state_version='.kclString((string)($r['project_state_version']??'')),'FACT project_state_genome_id='.kclString((string)($r['project_state_genome_id']??'')),'FACT project_state_generation='.(int)($r['project_state_generation']??0),'FACT project_state_consistent='.(!empty($r['project_state_consistent'])?'true':'false'),'FACT changelog_has_runtime_release='.(!empty($r['changelog_has_runtime_release'])?'true':'false'),'FACT next_priority1_has_runtime='.(!empty($r['next_priority1_has_runtime'])?'true':'false'),'FACT protocol_workspace_batch_consistent='.(!empty($r['protocol_workspace_batch_consistent'])?'true':'false')];
    foreach(($r['stale_resources']??[]) as $i=>$name)$lines[]='STALE #'.($i+1).' resource='.kclString((string)$name);
    foreach(($r['memory_sha256']??[]) as $name=>$sha)$lines[]='MEMORY resource='.kclString((string)$name).' sha256='.kclString((string)$sha);
    $lines[]='RULE read-only-no-memory-mutation';$lines[]='RULE diagnostic-does-not-grant-authority';$lines[]='END';return $lines;
}
/* END route_helpers_v1.php */
