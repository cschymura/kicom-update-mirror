<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/lib.php';
require_once __DIR__.'/DevSession.php';
require_once __DIR__.'/DevExpansionBindings.php';
require_once __DIR__.'/DevObserver.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

function devExpansionOut(array $body,int $status=200): never
{
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
    header('Allow: POST');
    devExpansionOut(['ok'=>false,'code'=>'DEV_EXPANSION_POST_REQUIRED'],405);
}
if(!function_exists('kicomEnsureStorage')||!kicomEnsureStorage()) devExpansionOut(['ok'=>false,'code'=>'KICOM_STORAGE_UNAVAILABLE'],503);

$raw=file_get_contents('php://input');
if(!is_string($raw)||strlen($raw)>16384) devExpansionOut(['ok'=>false,'code'=>'DEV_EXPANSION_BODY_INVALID'],400);
$body=json_decode($raw,true);
if(!is_array($body)) devExpansionOut(['ok'=>false,'code'=>'DEV_EXPANSION_JSON_INVALID'],400);

$operation=strtoupper(trim((string)($body['operation']??'')));
$capability=match($operation){
    'STATUS'=>'expansion.resource.status',
    'EXECUTE_SANDBOX','REPAIR_SANDBOX_FEDERATION','UPGRADE_SANDBOX_LIVING'=>'expansion.test.execute',
    default=>'',
};
if($capability==='') devExpansionOut(['ok'=>false,'code'=>'DEV_EXPANSION_OPERATION_FORBIDDEN'],403);

$sid=strtolower(trim((string)($_SERVER['HTTP_X_KICOM_DEV_SESSION']??'')));
$token=strtolower(trim((string)($_SERVER['HTTP_X_KICOM_DEV_TOKEN']??'')));
$sessions=new KiComDevSessionManager(kicomVarDir().'/dev_zone/sessions');
$auth=$sessions->authenticate($sid,$token,$capability);
if(empty($auth['ok'])) devExpansionOut($auth,401);

$observer=new KiComDevObserver(kicomVarDir().'/dev_observer');
$opId=$observer->begin($operation,['capability'=>$capability]);
$observer->stage($opId,'AUTH','OK');

try{
    $bindings=new KiComDevExpansionBindings(__DIR__.'/expansion','https://kicom.rurtalbahn.info');
    $observer->stage($opId,'BINDINGS','OK');
    $result=match($operation){
        'STATUS'=>$bindings->resourceStatus(),
        'EXECUTE_SANDBOX'=>$bindings->executeSandbox(),
        'REPAIR_SANDBOX_FEDERATION'=>$bindings->repairSandboxFederation(),
        'UPGRADE_SANDBOX_LIVING'=>$bindings->upgradeSandboxLiving(),
        default=>['ok'=>false,'code'=>'DEV_EXPANSION_OPERATION_FORBIDDEN'],
    };
    $observer->finish($opId,!empty($result['ok']),(string)($result['code']??'UNKNOWN'),[
        'operation'=>$operation,
        'scope'=>'dev',
        'target'=>'sandbox',
    ]);
}catch(Throwable $e){
    $observer->finish($opId,false,'DEV_EXPANSION_RUNTIME_FAILED',['exception'=>get_class($e)]);
    devExpansionOut(['ok'=>false,'code'=>'DEV_EXPANSION_RUNTIME_FAILED','operation_id'=>$opId],500);
}

$status=!empty($result['ok'])?200:422;
$result['scope']='dev';
$result['capability']=$capability;
$result['operation']=$operation;
$result['operation_id']=$opId;
devExpansionOut($result,$status);
