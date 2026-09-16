<?php
declare(strict_types=1);

/**
 * GET-only bridge for development tooling that cannot send custom headers.
 * The URL credential is deliberately DEV-only and has no production authority.
 */
$root=dirname(__DIR__);
require_once $root.'/lib.php';
require_once __DIR__.'/DevSession.php';
require_once __DIR__.'/DevRouter.php';
require_once __DIR__.'/DevKiComBindings.php';
require_once __DIR__.'/DevDiagnostics.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

function devBridgeOut(array $body,int $status=200): never
{
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
    exit;
}
function devBridgeB64Decode(string $value): ?string
{
    if ($value==='') return '{}';
    if (!preg_match('/^[A-Za-z0-9_-]{1,32768}$/',$value)) return null;
    $s=strtr($value,'-_','+/');
    $pad=strlen($s)%4;
    if ($pad) $s.=str_repeat('=',4-$pad);
    $raw=base64_decode($s,true);
    return $raw===false?null:$raw;
}

if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {
    header('Allow: GET');
    devBridgeOut(['ok'=>false,'code'=>'DEV_BRIDGE_GET_REQUIRED'],405);
}
if (!function_exists('kicomEnsureStorage')||!kicomEnsureStorage()) devBridgeOut(['ok'=>false,'code'=>'KICOM_STORAGE_UNAVAILABLE'],503);

$sid=strtolower(trim((string)($_GET['sid']??'')));
$key=strtolower(trim((string)($_GET['key']??'')));
$operation=strtoupper(trim((string)($_GET['op']??'')));
$encoded=(string)($_GET['p']??'');
if ($operation==='') devBridgeOut(['ok'=>false,'code'=>'DEV_BRIDGE_OPERATION_REQUIRED'],400);
$raw=devBridgeB64Decode($encoded);
if ($raw===null||strlen($raw)>24576) devBridgeOut(['ok'=>false,'code'=>'DEV_BRIDGE_PAYLOAD_INVALID'],400);
$payload=json_decode($raw,true);
if (!is_array($payload)) devBridgeOut(['ok'=>false,'code'=>'DEV_BRIDGE_PAYLOAD_JSON_INVALID'],400);

$store=kicomVarDir().'/dev_zone';
$sessions=new KiComDevSessionManager($store.'/sessions');
$handlers=array_merge(KiComDevRuntimeBindings::handlers(),KiComDevDiagnostics::handlers());
$router=new KiComDevRouter($sessions,$handlers);
$result=$router->handle($operation,$sid,$key,$payload);
$ok=!empty($result['ok']);
$code=(string)($result['code']??'DEV_UNKNOWN');
$status=200;
if (!$ok) {
    if (str_starts_with($code,'DEV_SESSION_')) $status=401;
    elseif ($code==='DEV_OPERATION_FORBIDDEN'||$code==='DEV_CAPABILITY_FORBIDDEN') $status=403;
    elseif ($code==='DEV_OPERATION_NOT_IMPLEMENTED') $status=501;
    else $status=422;
}
$result['transport']='dev-get-bridge';
$result['credential_scope']='dev-only';
devBridgeOut($result,$status);
