<?php
declare(strict_types=1);
if(!defined('KICOM_GUARDIAN_EMBEDDED')) define('KICOM_GUARDIAN_EMBEDDED',true);
require_once __DIR__ . '/guardian.php';
kicomImmuneGuardianMaybe(false,true);
require_once __DIR__ . '/lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

const KCL_PROTOCOL = 'KCL/1';
/** @return never */
function apiOut(array $lines,int $status=200): never { http_response_code($status); echo implode("\n",$lines),"\n"; exit; }
function apiKclString(string $value,int $maxLen=500): string {
    $value=trim($value); if(kicomTextLen($value)>$maxLen)$value=kicomTextSubstr($value,0,$maxLen);
    $value=str_replace(["\\","\r","\n","\t",'"'],["\\\\","\\r","\\n","\\t",'\\"'],$value);
    return '"'.$value.'"';
}
function apiContent(array $data,bool $allowEmpty=true): ?string {
    if(!array_key_exists('content',$data)) return null;
    return kicomDecodeBase64Url((string)$data['content'],$allowEmpty);
}

if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');
    apiOut([KCL_PROTOCOL,'ERROR method','FACT code="POST_REQUIRED"','FACT endpoint="api.php"','END'],405);
}
if(!kicomEnsureStorage()) apiOut([KCL_PROTOCOL,'ERROR storage','FACT code="STORAGE_UNAVAILABLE"','END'],500);

/* 0.9.2 machine push channel. Transport authentication is separate from package
   verification; every accepted package still enters the same risk/verifier pipeline. */
$queryOp=strtoupper(trim((string)($_GET['q']??'')));
if($queryOp==='UPDATE_PUSH'){
    $auth=(string)($_SERVER['HTTP_X_KICOM_UPDATE_KEY']??'');
    if($auth===''){
        $hdr=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
        if(preg_match('/^Bearer\s+(.+)$/i',$hdr,$m))$auth=trim($m[1]);
    }
    $fingerprint=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    if(!kicomUpdatePushRateAllowed($fingerprint))apiOut([KCL_PROTOCOL,'ERROR update_push','FACT code="RATE_LIMIT"','END'],429);
    if(!kicomUpdatePushAuth($auth))apiOut([KCL_PROTOCOL,'ERROR update_push','FACT code="NOT_FOUND"','END'],404);

    $tmp='';$name='KiCom-push.zip';
    if(isset($_FILES['package'])&&is_array($_FILES['package'])&&($_FILES['package']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){
        $tmp=(string)($_FILES['package']['tmp_name']??'');$name=basename((string)($_FILES['package']['name']??$name));
        if(!is_uploaded_file($tmp))apiOut([KCL_PROTOCOL,'ERROR update_push','FACT code="UPLOAD_INVALID"','END'],400);
    } else {
        $len=(int)($_SERVER['CONTENT_LENGTH']??0);
        if($len<=0||$len>KICOM_MAX_SELF_UPDATE_ZIP_BYTES)apiOut([KCL_PROTOCOL,'ERROR update_push','FACT code="PACKAGE_SIZE_INVALID"','END'],413);
        $raw=file_get_contents('php://input');
        if($raw===false||strlen($raw)!==$len||strlen($raw)>KICOM_MAX_SELF_UPDATE_ZIP_BYTES)apiOut([KCL_PROTOCOL,'ERROR update_push','FACT code="BODY_READ_FAILED"','END'],400);
        $name=basename((string)($_SERVER['HTTP_X_KICOM_FILENAME']??$name));if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='zip')$name.='.zip';
        $tmp=kicomTempDir().'/push-'.substr(hash('sha256',$raw),0,24).'.zip';
        if(@file_put_contents($tmp,$raw,LOCK_EX)===false)apiOut([KCL_PROTOCOL,'ERROR update_push','FACT code="TEMP_WRITE_FAILED"','END'],500);@chmod($tmp,0600);
    }
    try{$r=kicomReceiveSelfUpdatePackage($tmp,$name,'push',true);}
    finally{if($tmp!==''&&str_starts_with($tmp,kicomTempDir().'/'))@unlink($tmp);}
    $risk=(string)($r['risk_class']??($r['staged']['risk_class']??''));
    $to=(string)($r['staged']['to_version']??($r['to_version']??''));
    $code=(string)($r['code']??($r['ok']?'OK':'FAILED'));
    apiOut([KCL_PROTOCOL,($r['ok']?'OK':'ERROR').' update_push','FACT code='.apiKclString($code),'FACT to_version='.apiKclString($to),'FACT risk_class='.apiKclString($risk),'FACT auto_installed='.(($code==='AUTO_INSTALLED_GREEN')?'true':'false'),'END'],$r['ok']?200:422);
}

$len=(int)($_SERVER['CONTENT_LENGTH']??0);
if($len>KICOM_MAX_POST_BYTES) apiOut([KCL_PROTOCOL,'ERROR request','FACT code="BODY_TOO_LARGE"','FACT max_bytes='.KICOM_MAX_POST_BYTES,'END'],413);
$raw=file_get_contents('php://input');
if($raw===false || strlen($raw)>KICOM_MAX_POST_BYTES) apiOut([KCL_PROTOCOL,'ERROR request','FACT code="BODY_READ_FAILED"','END'],400);
$ct=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
$data=[];
if(str_contains($ct,'application/json')){
    $decoded=json_decode($raw,true);
    if(!is_array($decoded)) apiOut([KCL_PROTOCOL,'ERROR request','FACT code="JSON_INVALID"','END'],400);
    $data=$decoded;
} else {
    parse_str($raw,$data);
    if(!is_array($data)) $data=[];
}
$op=strtoupper(trim((string)($data['operation']??$data['q']??'')));
$rid=kicomRequestId();
kicomCleanupPending();

switch($op){
case 'WORKSPACE_PROPOSE':
    $path=kicomSafeRelativePath((string)($data['path']??'')); $content=apiContent($data,true);
    if($path===null||$content===null) apiOut([KCL_PROTOCOL,'ERROR workspace_propose','FACT request_id='.apiKclString($rid),'FACT code="INVALID_REQUEST"','END'],400);
    if(strlen($content)>KICOM_MAX_PROPOSAL_BYTES) apiOut([KCL_PROTOCOL,'ERROR workspace_propose','FACT request_id='.apiKclString($rid),'FACT code="CONTENT_TOO_LARGE"','FACT max_bytes='.KICOM_MAX_PROPOSAL_BYTES,'END'],413);
    $current=kicomCurrentHash($path); $baseRaw=trim((string)($data['base_sha256']??'')); $base=strtoupper($baseRaw)==='NEW'?'NEW':strtolower($baseRaw); if($current==='NEW'&&$base==='')$base='NEW';
    if($base==='') apiOut([KCL_PROTOCOL,'ERROR workspace_propose','FACT request_id='.apiKclString($rid),'FACT code="BASE_REQUIRED"','FACT current_sha256='.apiKclString($current),'END'],409);
    if($base!=='NEW'&&!preg_match('/^[a-f0-9]{64}$/',$base)) apiOut([KCL_PROTOCOL,'ERROR workspace_propose','FACT request_id='.apiKclString($rid),'FACT code="INVALID_BASE_SHA256"','END'],400);
    if(!hash_equals($current,$base)) apiOut([KCL_PROTOCOL,'ERROR workspace_propose','FACT request_id='.apiKclString($rid),'FACT code="BASE_CONFLICT"','FACT current_sha256='.apiKclString($current),'FACT supplied_base='.apiKclString($base),'END'],409);
    $v=kicomValidateContent($path,$content); if($v['status']==='error') apiOut([KCL_PROTOCOL,'ERROR workspace_propose','FACT request_id='.apiKclString($rid),'FACT code="VALIDATION_FAILED"','FACT validation='.apiKclString($v['message']),'END'],422);
    $pr=kicomCreateProposal(['kind'=>'workspace_write','path'=>$path,'bytes'=>strlen($content),'sha256'=>hash('sha256',$content),'content_b64'=>base64_encode($content),'base_sha256'=>$base,'validation'=>$v,'transport'=>'POST']);
    if(!$pr['ok']) apiOut([KCL_PROTOCOL,'ERROR workspace_propose','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString($pr['code']),'END'],500);
    apiOut([KCL_PROTOCOL,'READY workspace_proposal','FACT request_id='.apiKclString($rid),'FACT proposal_id='.apiKclString($pr['id']),'FACT path='.apiKclString($path),'FACT base_sha256='.apiKclString($base),'FACT proposed_sha256='.apiKclString(hash('sha256',$content)),'FACT bytes='.strlen($content),'FACT transport="POST"','REQUIRES human_approval=true','LINK admin="admin.php"','END'],202);

case 'WORKSPACE_ROLLBACK':
    $path=kicomSafeRelativePath((string)($data['path']??'')); $revision=trim((string)($data['revision']??''));
    if($path===null||$revision==='') apiOut([KCL_PROTOCOL,'ERROR workspace_rollback','FACT request_id='.apiKclString($rid),'FACT code="INVALID_REQUEST"','END'],400);
    $row=kicomHistoryGet($path,$revision); if($row===null) apiOut([KCL_PROTOCOL,'ERROR workspace_rollback','FACT request_id='.apiKclString($rid),'FACT code="REVISION_NOT_FOUND"','END'],404);
    if(!is_string($row['content_b64']??null)) apiOut([KCL_PROTOCOL,'ERROR workspace_rollback','FACT request_id='.apiKclString($rid),'FACT code="REVISION_HAS_NO_CONTENT"','END'],422);
    $content=base64_decode((string)$row['content_b64'],true); if($content===false) apiOut([KCL_PROTOCOL,'ERROR workspace_rollback','FACT request_id='.apiKclString($rid),'FACT code="REVISION_CORRUPT"','END'],500);
    $base=kicomCurrentHash($path); $pr=kicomCreateProposal(['kind'=>'rollback','path'=>$path,'bytes'=>strlen($content),'sha256'=>hash('sha256',$content),'content_b64'=>base64_encode($content),'base_sha256'=>$base,'source_revision'=>$revision,'validation'=>kicomValidateContent($path,$content),'transport'=>'POST']);
    if(!$pr['ok']) apiOut([KCL_PROTOCOL,'ERROR workspace_rollback','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString($pr['code']),'END'],500);
    apiOut([KCL_PROTOCOL,'READY workspace_rollback','FACT request_id='.apiKclString($rid),'FACT proposal_id='.apiKclString($pr['id']),'FACT path='.apiKclString($path),'FACT source_revision='.apiKclString($revision),'FACT current_sha256='.apiKclString($base),'FACT target_sha256='.apiKclString(hash('sha256',$content)),'FACT transport="POST"','REQUIRES human_approval=true','LINK admin="admin.php"','END'],202);

case 'MEMORY_PROPOSE':
    $name=strtoupper(trim((string)($data['resource']??''))); $res=kicomReadMemoryResource($name); $content=apiContent($data,true);
    if($res===null) apiOut([KCL_PROTOCOL,'ERROR memory_propose','FACT request_id='.apiKclString($rid),'FACT code="UNKNOWN_MEMORY_RESOURCE"','END'],400);
    if($content===null) apiOut([KCL_PROTOCOL,'ERROR memory_propose','FACT request_id='.apiKclString($rid),'FACT code="INVALID_CONTENT"','END'],400);
    if(strlen($content)>KICOM_MAX_MEMORY_PROPOSAL_BYTES) apiOut([KCL_PROTOCOL,'ERROR memory_propose','FACT request_id='.apiKclString($rid),'FACT code="CONTENT_TOO_LARGE"','FACT max_bytes='.KICOM_MAX_MEMORY_PROPOSAL_BYTES,'END'],413);
    $base=strtolower(trim((string)($data['base_sha256']??''))); if(!preg_match('/^[a-f0-9]{64}$/',$base)) apiOut([KCL_PROTOCOL,'ERROR memory_propose','FACT request_id='.apiKclString($rid),'FACT code="BASE_REQUIRED"','FACT current_sha256='.apiKclString($res['sha256']),'END'],409);
    if(!hash_equals((string)$res['sha256'],$base)) apiOut([KCL_PROTOCOL,'ERROR memory_propose','FACT request_id='.apiKclString($rid),'FACT code="BASE_CONFLICT"','FACT current_sha256='.apiKclString($res['sha256']),'FACT supplied_base='.apiKclString($base),'END'],409);
    $v=kicomValidateMemoryContent($name,$content); if($v['status']==='error') apiOut([KCL_PROTOCOL,'ERROR memory_propose','FACT request_id='.apiKclString($rid),'FACT code="VALIDATION_FAILED"','FACT validation='.apiKclString($v['message']),'END'],422);
    $pr=kicomCreateProposal(['kind'=>'memory_write','resource'=>$name,'bytes'=>strlen($content),'sha256'=>hash('sha256',$content),'content_b64'=>base64_encode($content),'base_sha256'=>$base,'validation'=>$v,'transport'=>'POST']);
    if(!$pr['ok']) apiOut([KCL_PROTOCOL,'ERROR memory_propose','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString($pr['code']),'END'],500);
    apiOut([KCL_PROTOCOL,'READY memory_proposal','FACT request_id='.apiKclString($rid),'FACT proposal_id='.apiKclString($pr['id']),'FACT resource='.apiKclString($name),'FACT base_sha256='.apiKclString($base),'FACT proposed_sha256='.apiKclString(hash('sha256',$content)),'FACT bytes='.strlen($content),'FACT validation='.apiKclString($v['message']),'FACT transport="POST"','REQUIRES human_approval=true','LINK admin="admin.php"','END'],202);

case 'MEMORY_PATCH_PROPOSE':
    $name=strtoupper(trim((string)($data['resource']??''))); $base=(string)($data['base_sha256']??'');
    $find=kicomDecodeBase64Url((string)($data['find']??''),false); $replace=kicomDecodeBase64Url((string)($data['replace']??''),true);
    if($find===null||$replace===null) apiOut([KCL_PROTOCOL,'ERROR memory_patch_propose','FACT request_id='.apiKclString($rid),'FACT code="INVALID_PATCH_ENCODING"','END'],400);
    $r=kicomMemoryPatchCandidate($name,$base,$find,$replace); if(!$r['ok']){ $lines=[KCL_PROTOCOL,'ERROR memory_patch_propose','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString((string)$r['code'])]; if(isset($r['current_sha256']))$lines[]='FACT current_sha256='.apiKclString((string)$r['current_sha256']); if(isset($r['matches']))$lines[]='FACT matches='.(int)$r['matches']; $lines[]='END'; apiOut($lines,$r['code']==='BASE_CONFLICT'?409:422); }
    $content=(string)$r['content']; $v=$r['validation']; $pr=kicomCreateProposal(['kind'=>'memory_write','resource'=>$name,'bytes'=>strlen($content),'sha256'=>$r['sha256'],'content_b64'=>base64_encode($content),'base_sha256'=>$r['base_sha256'],'validation'=>$v,'patch'=>true,'transport'=>'POST']);
    if(!$pr['ok']) apiOut([KCL_PROTOCOL,'ERROR memory_patch_propose','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString($pr['code']),'END'],500);
    apiOut([KCL_PROTOCOL,'READY memory_patch_proposal','FACT request_id='.apiKclString($rid),'FACT proposal_id='.apiKclString($pr['id']),'FACT resource='.apiKclString($name),'FACT base_sha256='.apiKclString($r['base_sha256']),'FACT proposed_sha256='.apiKclString($r['sha256']),'FACT bytes='.strlen($content),'FACT transport="POST"','REQUIRES human_approval=true','LINK admin="admin.php"','END'],202);

case 'MEMORY_ROLLBACK':
    $name=strtoupper(trim((string)($data['resource']??''))); $revision=trim((string)($data['revision']??''));
    if(!isset(kicomMemoryResources()[$name])||$revision==='') apiOut([KCL_PROTOCOL,'ERROR memory_rollback','FACT request_id='.apiKclString($rid),'FACT code="INVALID_REQUEST"','END'],400);
    $row=kicomMemoryHistoryGet($name,$revision); if($row===null) apiOut([KCL_PROTOCOL,'ERROR memory_rollback','FACT request_id='.apiKclString($rid),'FACT code="REVISION_NOT_FOUND"','END'],404);
    $content=base64_decode((string)($row['content_b64']??''),true); if($content===false) apiOut([KCL_PROTOCOL,'ERROR memory_rollback','FACT request_id='.apiKclString($rid),'FACT code="REVISION_CORRUPT"','END'],500);
    $v=kicomValidateMemoryContent($name,$content); if($v['status']==='error') apiOut([KCL_PROTOCOL,'ERROR memory_rollback','FACT request_id='.apiKclString($rid),'FACT code="REVISION_VALIDATION_FAILED"','END'],422);
    $base=kicomMemoryCurrentHash($name); $pr=kicomCreateProposal(['kind'=>'memory_rollback','resource'=>$name,'bytes'=>strlen($content),'sha256'=>hash('sha256',$content),'content_b64'=>base64_encode($content),'base_sha256'=>$base,'source_revision'=>$revision,'validation'=>$v,'transport'=>'POST']);
    if(!$pr['ok']) apiOut([KCL_PROTOCOL,'ERROR memory_rollback','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString($pr['code']),'END'],500);
    apiOut([KCL_PROTOCOL,'READY memory_rollback','FACT request_id='.apiKclString($rid),'FACT proposal_id='.apiKclString($pr['id']),'FACT resource='.apiKclString($name),'FACT source_revision='.apiKclString($revision),'FACT current_sha256='.apiKclString($base),'FACT target_sha256='.apiKclString(hash('sha256',$content)),'FACT transport="POST"','REQUIRES human_approval=true','LINK admin="admin.php"','END'],202);

case 'MEMORY_DIFF':
    $name=strtoupper(trim((string)($data['resource']??''))); $res=kicomReadMemoryResource($name); $content=apiContent($data,true);
    if($res===null||$content===null) apiOut([KCL_PROTOCOL,'ERROR memory_diff','FACT request_id='.apiKclString($rid),'FACT code="INVALID_REQUEST"','END'],400);
    if(strlen($content)>KICOM_MAX_MEMORY_PROPOSAL_BYTES) apiOut([KCL_PROTOCOL,'ERROR memory_diff','FACT request_id='.apiKclString($rid),'FACT code="CONTENT_TOO_LARGE"','END'],413);
    $d=kicomUnifiedDiff((string)$res['raw'],$content,500); if(!$d['ok']) apiOut([KCL_PROTOCOL,'ERROR memory_diff','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString($d['code']),'END'],422);
    $lines=[KCL_PROTOCOL,'OK memory_diff','FACT request_id='.apiKclString($rid),'FACT resource='.apiKclString($name),'FACT base_sha256='.apiKclString($res['sha256']),'FACT proposed_sha256='.apiKclString(hash('sha256',$content)),'FACT changed='.($d['changed']?'true':'false'),'FACT transport="POST"','BEGIN_DIFF']; foreach($d['lines'] as $line)$lines[]=$line; $lines[]='END_DIFF';$lines[]='END'; apiOut($lines);

case 'MEMORY_VALIDATE':
    $name=strtoupper(trim((string)($data['resource']??''))); if(!isset(kicomMemoryResources()[$name])) apiOut([KCL_PROTOCOL,'ERROR memory_validate','FACT request_id='.apiKclString($rid),'FACT code="UNKNOWN_MEMORY_RESOURCE"','END'],400);
    $content=apiContent($data,true); if($content===null) apiOut([KCL_PROTOCOL,'ERROR memory_validate','FACT request_id='.apiKclString($rid),'FACT code="INVALID_CONTENT"','END'],400);
    $v=kicomValidateMemoryContent($name,$content); apiOut([KCL_PROTOCOL,($v['status']==='ok'?'OK':'ERROR').' memory_validate','FACT request_id='.apiKclString($rid),'FACT resource='.apiKclString($name),'FACT source="candidate"','FACT result='.apiKclString($v['message']),'FACT transport="POST"','END'],$v['status']==='error'?422:200);


case 'DEPLOY_PROPOSE':
    $r=kicomCreateDeployProposal((string)($data['target']??''),(string)($data['workspace_path']??''),(string)($data['dest_path']??''),(string)($data['workspace_sha256']??''),(string)($data['target_base_sha256']??''),'POST');
    if(!$r['ok']){ $lines=[KCL_PROTOCOL,'ERROR deploy_propose','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString((string)$r['code'])]; if(isset($r['current_workspace_sha256']))$lines[]='FACT current_workspace_sha256='.apiKclString((string)$r['current_workspace_sha256']); if(isset($r['current_target_sha256']))$lines[]='FACT current_target_sha256='.apiKclString((string)$r['current_target_sha256']); $lines[]='END'; apiOut($lines,409); }
    apiOut([KCL_PROTOCOL,'READY deploy_proposal','FACT request_id='.apiKclString($rid),'FACT proposal_id='.apiKclString($r['proposal_id']),'FACT target='.apiKclString($r['target']),'FACT target_class='.apiKclString($r['target_class']),'FACT workspace_path='.apiKclString($r['workspace_path']),'FACT dest_path='.apiKclString($r['dest_path']),'FACT workspace_sha256='.apiKclString($r['workspace_sha256']),'FACT target_base_sha256='.apiKclString($r['target_sha256']),'FACT bytes='.$r['bytes'],'FACT transport="POST"','REQUIRES human_approval=true','LINK admin="admin.php"','END'],202);

case 'DEPLOY_ROLLBACK':
    $r=kicomCreateDeployRollbackProposal(trim((string)($data['deployment']??'')),'POST');
    if(!$r['ok']) apiOut([KCL_PROTOCOL,'ERROR deploy_rollback','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString((string)$r['code']),'END'],409);
    apiOut([KCL_PROTOCOL,'READY deploy_rollback','FACT request_id='.apiKclString($rid),'FACT proposal_id='.apiKclString($r['proposal_id']),'FACT target='.apiKclString($r['target']),'FACT dest_path='.apiKclString($r['dest_path']),'FACT current_sha256='.apiKclString($r['current_sha256']),'FACT target_sha256='.apiKclString($r['target_sha256']),'FACT transport="POST"','REQUIRES human_approval=true','LINK admin="admin.php"','END'],202);


case 'DEPLOY_PACKAGE_PROPOSE':
    $r=kicomCreateDeployPackageProposal(
        (string)($data['target']??''),
        (string)($data['manifest_path']??''),
        (string)($data['manifest_sha256']??''),
        'POST'
    );
    if(!$r['ok']){
        $lines=[KCL_PROTOCOL,'ERROR deploy_package_propose','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString((string)$r['code'])];
        if(isset($r['entry']))$lines[]='FACT entry='.(int)$r['entry'];
        if(isset($r['current_manifest_sha256']))$lines[]='FACT current_manifest_sha256='.apiKclString((string)$r['current_manifest_sha256']);
        $lines[]='END';apiOut($lines,409);
    }
    apiOut([
        KCL_PROTOCOL,'READY deploy_package_proposal','FACT request_id='.apiKclString($rid),
        'FACT proposal_id='.apiKclString($r['proposal_id']),'FACT target='.apiKclString($r['target']),
        'FACT target_class='.apiKclString($r['target_class']),'FACT package_name='.apiKclString($r['package_name']),
        'FACT manifest_path='.apiKclString($r['manifest_path']),'FACT manifest_sha256='.apiKclString($r['manifest_sha256']),
        'FACT files_count='.$r['files_count'],'FACT bytes='.$r['bytes'],'FACT transport="POST"',
        'REQUIRES human_approval=true','LINK admin="admin.php"','END'
    ],202);

case 'DEPLOY_PACKAGE_ROLLBACK':
    $r=kicomCreateDeployPackageRollbackProposal(trim((string)($data['deployment']??'')),'POST');
    if(!$r['ok']){
        $lines=[KCL_PROTOCOL,'ERROR deploy_package_rollback','FACT request_id='.apiKclString($rid),'FACT code='.apiKclString((string)$r['code'])];
        if(isset($r['entry']))$lines[]='FACT entry='.(int)$r['entry'];
        $lines[]='END';apiOut($lines,409);
    }
    apiOut([
        KCL_PROTOCOL,'READY deploy_package_rollback','FACT request_id='.apiKclString($rid),
        'FACT proposal_id='.apiKclString($r['proposal_id']),'FACT target='.apiKclString($r['target']),
        'FACT package_name='.apiKclString($r['package_name']),'FACT source_deployment='.apiKclString($r['source_deployment']),
        'FACT files_count='.$r['files_count'],'FACT transport="POST"',
        'REQUIRES human_approval=true','LINK admin="admin.php"','END'
    ],202);

default:
    apiOut([KCL_PROTOCOL,'ERROR request','FACT request_id='.apiKclString($rid),'FACT code="UNKNOWN_OPERATION"','FACT received='.apiKclString($op,80),'END'],400);
}
