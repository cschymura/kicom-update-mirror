<?php
declare(strict_types=1);
/**
 * ISOLATED LINUX RUNNER FIXTURE ONLY. NOT AN ACTUAL KiCom POLICY CONTROLLER.
 * PHP server runs as unprivileged kicom_inner_ci. Distinct
 * kicom_membrane_ci sends synthetic bytes; only the test server writes an
 * exact receipt to its own internal directory.
 */
if ((string)($_SERVER['SERVER_PORT'] ?? '') !== '18731'
    || !in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1','::1'], true)) {
    http_response_code(403);exit;
}
$method=(string)($_SERVER['REQUEST_METHOD']??'');
if (!in_array($method,['GET','POST'],true)) {http_response_code(405);exit;}
$body=$method==='POST' ? file_get_contents('php://input') : (string)($_SERVER['QUERY_STRING']??'');
if(!is_string($body) || strlen($body)>262144){http_response_code(413);exit;}
$serial=(string)($_GET['serial']??'');
if(!preg_match('/^[a-z0-9_-]{1,24}$/D',$serial)){http_response_code(400);exit;}
$receipts=(string)(getenv('KICOM_MEMBRANE_ISOLATED_RECEIPTS')?:'');
if(!preg_match('~^/tmp/kicom-membrane-os-[a-zA-Z0-9_-]+/interior/receipts$~D',$receipts)) {
    http_response_code(500);exit;
}
$digest=hash('sha256',$method."\0".$serial."\0".$body);
if(file_put_contents($receipts,$serial.' '.$digest."\n",FILE_APPEND|LOCK_EX)===false) {
    http_response_code(500);exit;
}
header('Content-Type: application/octet-stream');
header('X-Local-Receipt: '.$digest);
http_response_code($method==='POST'?201:200);
echo $body;
