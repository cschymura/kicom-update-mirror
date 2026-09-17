<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/lib.php';
require_once __DIR__.'/DevSession.php';
require_once __DIR__.'/DevKiComBindings.php';
require_once __DIR__.'/DevModuleBootstrap0916.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

function bootstrapOut(array $row,int $status=200): never {
    http_response_code($status);echo json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";exit;
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_POST_REQUIRED'],405);}
if(!function_exists('kicomEnsureStorage')||!kicomEnsureStorage())bootstrapOut(['ok'=>false,'code'=>'KICOM_STORAGE_UNAVAILABLE'],503);
$raw=file_get_contents('php://input');$body=json_decode(is_string($raw)?$raw:'',true);if(!is_array($body))bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_JSON_REQUIRED'],400);
$operation=strtoupper(trim((string)($body['operation']??'')));
$sid=(string)($_SERVER['HTTP_X_KICOM_DEV_SESSION']??'');$token=(string)($_SERVER['HTTP_X_KICOM_DEV_TOKEN']??'');
$sessions=new KiComDevSessionManager(kicomVarDir().'/dev_zone/sessions');
$auth=$sessions->authenticate($sid,$token,'build.finalize_candidate');
if(empty($auth['ok']))bootstrapOut($auth,in_array((string)($auth['code']??''),['DEV_SESSION_TOKEN_REJECTED','DEV_SESSION_REVOKED','DEV_SESSION_EXPIRED','DEV_SESSION_IDLE_EXPIRED'],true)?401:403);
$required=['build.begin','build.patch','build.status','build.test','build.finalize_candidate'];foreach($required as $cap)if(!in_array($cap,$auth['capabilities']??[],true))bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_CAPABILITY_MISSING','capability'=>$cap],403);

if($operation==='STATUS'){
    bootstrapOut(['ok'=>true,'code'=>'DEV_BOOTSTRAP_READY','from_version'=>KiComDevModuleBootstrap0916::FROM_VERSION,'to_version'=>KiComDevModuleBootstrap0916::TO_VERSION,'current_version'=>defined('KICOM_VERSION')?(string)KICOM_VERSION:'','creates_production_pending'=>false,'installs_production'=>false,'scope'=>'dev-build-only']);
}
if($operation!=='PREPARE_0916_MODULE_BOOTSTRAP')bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_OPERATION_FORBIDDEN'],400);
if(!defined('KICOM_VERSION')||(string)KICOM_VERSION!==KiComDevModuleBootstrap0916::FROM_VERSION)bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_RUNTIME_VERSION_MISMATCH','current_version'=>defined('KICOM_VERSION')?(string)KICOM_VERSION:''],409);

$expectedLiveLib='9e4616c95839d0bdc133bd0c347244e82c9703f3bb29018b7fa6b61efa997b14';
$begin=KiComDevRuntimeBindings::buildBegin([],$auth);if(empty($begin['ok']))bootstrapOut($begin+['phase'=>'build_begin'],400);
$buildId=(string)($begin['build_id']??$begin['id']??'');if(!preg_match('/^[a-zA-Z0-9._-]{6,120}$/',$buildId))bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_BUILD_ID_INVALID','phase'=>'build_begin'],500);
$x=kicomFastBuildMeta((string)$auth['session_id'],$buildId);if(empty($x['ok']))bootstrapOut($x+['phase'=>'build_meta'],400);
$src=(string)($x['dir']??'').'/src/lib.php';$lib=@file_get_contents($src);if(!is_string($lib))bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_LIB_READ_FAILED','build_id'=>$buildId],500);
$liveHash=hash('sha256',$lib);if(!hash_equals($expectedLiveLib,$liveHash))bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_LIVE_HASH_CONFLICT','build_id'=>$buildId,'expected_sha256'=>$expectedLiveLib,'actual_sha256'=>$liveHash],409);
$plan=KiComDevModuleBootstrap0916::plan($lib);if(empty($plan['ok']))bootstrapOut($plan+['build_id'=>$buildId,'phase'=>'plan'],409);

$working=$lib;
foreach($plan['patches'] as $i=>$patch){
    if(!is_array($patch))bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_PATCH_INVALID','build_id'=>$buildId,'patch'=>$i+1],500);
    $base=hash('sha256',$working);if(!hash_equals((string)$patch['base_sha256'],$base))bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_PATCH_CHAIN_CONFLICT','build_id'=>$buildId,'patch'=>$i+1],500);
    $r=KiComDevRuntimeBindings::buildPatch(['build_id'=>$buildId,'path'=>'lib.php','base_sha256'=>$base,'find_b64'=>kicomEncodeBase64Url((string)$patch['find']),'replace_b64'=>kicomEncodeBase64Url((string)$patch['replace'])],$auth);
    if(empty($r['ok']))bootstrapOut($r+['build_id'=>$buildId,'phase'=>'patch','patch'=>$i+1,'label'=>(string)$patch['label']],400);
    $working=str_replace((string)$patch['find'],(string)$patch['replace'],$working,$count);if($count!==1||!hash_equals((string)$patch['result_sha256'],hash('sha256',$working)))bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_LOCAL_PATCH_VERIFY_FAILED','build_id'=>$buildId,'patch'=>$i+1],500);
}

$x=kicomFastBuildMeta((string)$auth['session_id'],$buildId);$builtLib=empty($x['ok'])?false:@file_get_contents((string)$x['dir'].'/src/lib.php');if(!is_string($builtLib)||!hash_equals(hash('sha256',$working),hash('sha256',$builtLib)))bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_BUILD_HASH_VERIFY_FAILED','build_id'=>$buildId],500);
$test=KiComDevRuntimeBindings::buildTest(['build_id'=>$buildId],$auth);if(empty($test['ok']))bootstrapOut(['ok'=>false,'code'=>'DEV_BOOTSTRAP_BUILD_TEST_FAILED','build_id'=>$buildId,'test'=>$test],422);
$final=KiComDevRuntimeBindings::buildFinalizeCandidate(['build_id'=>$buildId,'version'=>KiComDevModuleBootstrap0916::TO_VERSION,'reason'=>'Trusted module bootstrap for resilient artifact transport','summary'=>'Adds fail-closed genome-bound module loading, modules/ self-update allowlist and optional artifact transport fallback hook. No module is installed by this release.'],$auth);
if(empty($final['ok']))bootstrapOut($final+['build_id'=>$buildId,'phase'=>'finalize'],422);
bootstrapOut($final+['code'=>'DEV_0916_BOOTSTRAP_CANDIDATE_READY','plan_patch_count'=>(int)$plan['patch_count'],'base_lib_sha256'=>$liveHash,'scope'=>'dev-build-only','creates_production_pending'=>false,'installs_production'=>false]);
