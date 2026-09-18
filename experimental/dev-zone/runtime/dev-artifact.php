<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/lib.php';
require_once __DIR__.'/DevSession.php';
require_once __DIR__.'/DevArtifactImporter.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
    http_response_code(405);header('Allow: POST');
    echo json_encode(['ok'=>false,'code'=>'DEV_ARTIFACT_POST_REQUIRED']),"\n";exit;
}
if(!function_exists('kicomEnsureStorage')||!kicomEnsureStorage()){
    http_response_code(503);echo json_encode(['ok'=>false,'code'=>'KICOM_STORAGE_UNAVAILABLE']),"\n";exit;
}

$raw=file_get_contents('php://input');
$body=json_decode(is_string($raw)?$raw:'',true);
if(!is_array($body)){
    http_response_code(400);echo json_encode(['ok'=>false,'code'=>'DEV_ARTIFACT_JSON_REQUIRED']),"\n";exit;
}
$operation=strtoupper(trim((string)($body['operation']??'')));
$payload=is_array($body['payload']??null)?(array)$body['payload']:[];
$sid=(string)($_SERVER['HTTP_X_KICOM_DEV_SESSION']??'');
$token=(string)($_SERVER['HTTP_X_KICOM_DEV_TOKEN']??'');

$sessions=new KiComDevSessionManager(kicomVarDir().'/dev_zone/sessions');
$required=in_array($operation,['INSTALL'],true)?'workspace.write':'source.snapshot.read';
$auth=$sessions->authenticate($sid,$token,$required);
if(empty($auth['ok'])){
    http_response_code(in_array((string)($auth['code']??''),['DEV_SESSION_TOKEN_REJECTED','DEV_SESSION_REVOKED','DEV_SESSION_EXPIRED','DEV_SESSION_IDLE_EXPIRED'],true)?401:403);
    echo json_encode($auth,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";exit;
}

try{
    $importer=new KiComDevArtifactImporter(__DIR__,kicomVarDir().'/dev_artifacts');
    if($operation==='STATUS'){
        $result=$importer->status();
    }elseif($operation==='VERIFY'){
        $result=$importer->verifyRemote((string)($payload['url']??''),(string)($payload['sha256']??''),(string)($payload['format']??'auto'));
    }elseif($operation==='INSTALL'){
        $result=$importer->installRemote((string)($payload['url']??''),(string)($payload['sha256']??''),(string)($payload['format']??'auto'));
    }else{
        $result=['ok'=>false,'code'=>'DEV_ARTIFACT_OPERATION_FORBIDDEN'];
    }
}catch(Throwable $e){
    $result=['ok'=>false,'code'=>'DEV_ARTIFACT_RUNTIME_FAILED'];
}

http_response_code(!empty($result['ok'])?200:400);
echo json_encode($result+['scope'=>'dev-only','session_gate'=>$required],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
