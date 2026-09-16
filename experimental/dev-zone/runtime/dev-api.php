<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/lib.php';
require_once __DIR__.'/DevSession.php';
require_once __DIR__.'/DevRouter.php';
require_once __DIR__.'/DevHttpAdapter.php';
require_once __DIR__.'/DevKiComBindings.php';
require_once __DIR__.'/DevDiagnostics.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

if (!function_exists('kicomEnsureStorage')||!kicomEnsureStorage()) {
    http_response_code(503);
    echo "{\"ok\":false,\"code\":\"KICOM_STORAGE_UNAVAILABLE\"}\n";
    exit;
}

$store=kicomVarDir().'/dev_zone';
$sessions=new KiComDevSessionManager($store.'/sessions');
$bindings=array_merge(KiComDevRuntimeBindings::handlers(),KiComDevDiagnostics::handlers());
$router=new KiComDevRouter($sessions,$bindings);
$http=new KiComDevHttpAdapter($router);
$raw=file_get_contents('php://input');
$result=$http->handle($_SERVER,$raw===false?'':$raw);
http_response_code((int)($result['http_status']??500));
echo json_encode($result['body']??['ok'=>false,'code'=>'DEV_RESPONSE_INVALID'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
