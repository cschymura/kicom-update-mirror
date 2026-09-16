<?php
declare(strict_types=1);
require_once __DIR__.'/DevHttpAdapter.php';

function failh(string $m): never { fwrite(STDERR,"FAIL: $m\n"); exit(1); }
function okh(bool $v,string $m): void { if(!$v) failh($m); echo "OK: $m\n"; }

$dir=sys_get_temp_dir().'/kicom-dev-http-'.bin2hex(random_bytes(4));
$sessions=new KiComDevSessionManager($dir,7200,3600);
$issued=$sessions->issue(['auth_method'=>'selftest']);
$sid=(string)$issued['session_id'];$token=(string)$issued['token'];
$router=new KiComDevRouter($sessions,[
    'DEV_WORKSPACE_READ'=>static fn(array $payload,array $auth): array => ['ok'=>true,'code'=>'READ_OK','path'=>(string)($payload['path']??'')],
]);
$http=new KiComDevHttpAdapter($router);

$r=$http->handle(['REQUEST_METHOD'=>'GET','CONTENT_TYPE'=>'application/json'],'{}');
okh(($r['http_status']??0)===405,'GET rejected');
$r=$http->handle(['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'text/plain'],'{}');
okh(($r['http_status']??0)===415,'non-json rejected');
$r=$http->handle(['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json'],'{');
okh(($r['http_status']??0)===400,'invalid json rejected');
$r=$http->handle(['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json'],json_encode(['operation'=>'DEV_WORKSPACE_READ','payload'=>[]]));
okh(($r['http_status']??0)===401,'missing headers rejected');

$server=[
    'REQUEST_METHOD'=>'POST',
    'CONTENT_TYPE'=>'application/json',
    'HTTP_X_KICOM_DEV_SESSION'=>$sid,
    'HTTP_X_KICOM_DEV_TOKEN'=>$token,
];
$r=$http->handle($server,json_encode(['operation'=>'DEV_WORKSPACE_READ','payload'=>['path'=>'x.php']]));
okh(($r['http_status']??0)===200&&(($r['body']['code']??'')==='READ_OK'),'header-authenticated DEV operation works');
$r=$http->handle($server,json_encode(['operation'=>'SELF_UPDATE_INSTALL','payload'=>[]]));
okh(($r['http_status']??0)===403&&(($r['body']['code']??'')==='DEV_OPERATION_FORBIDDEN'),'production operation blocked at router');

$bad=$server;$bad['HTTP_X_KICOM_DEV_TOKEN']=str_repeat('0',64);
$r=$http->handle($bad,json_encode(['operation'=>'DEV_WORKSPACE_READ','payload'=>[]]));
okh(($r['http_status']??0)===401,'bad bearer token maps to 401');

echo "DEV HTTP SELFTEST PASS\n";
