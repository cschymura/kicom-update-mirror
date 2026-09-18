<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/lib.php';
require_once __DIR__.'/DevObserver.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok'=>false,'code'=>'DEV_DOCTOR_GET_REQUIRED']),"\n";
    exit;
}

try{
    if(!function_exists('kicomVarDir')) throw new RuntimeException('KICOM_RUNTIME_UNAVAILABLE');
    $observer=new KiComDevObserver(kicomVarDir().'/dev_observer');
    $result=$observer->doctor(__DIR__);
    http_response_code(!empty($result['ok'])?200:503);
    echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
}catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['ok'=>false,'code'=>'DEV_DOCTOR_RUNTIME_FAILED']),"\n";
}
