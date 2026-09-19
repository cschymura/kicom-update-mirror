<?php
declare(strict_types=1);
/**
 * Strictly localhost-only synthetic transport fixture, NOT a KiCom endpoint.
 * Echoes opaque GET and POST bytes, never writes to a real mail/Slack server.
 * Its separate process is used only to prove that an optional passive observer
 * preserves positive bidirectional communication, including binary payloads.
 */
if (!in_array((string)($_SERVER['SERVER_PORT'] ?? ''), ['18729','18730'], true)
    || !in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1','::1'], true)) {
    http_response_code(403);exit;
}
$method=(string)($_SERVER['REQUEST_METHOD']??'');
if (!in_array($method,['GET','POST'],true)) {http_response_code(405);exit;}
$body=$method==='POST' ? file_get_contents('php://input') : (string)($_SERVER['QUERY_STRING']??'');
if (!is_string($body) || strlen($body)>262144) {http_response_code(413);exit;}
$serial=(string)($_GET['serial']??'');
$serial=(preg_match('/^[a-z0-9_-]{1,30}$/D',$serial) ? $serial : 'unknown');
$hits=(string)(getenv('KICOM_MEMBRANE_TEST_HITS') ?: '');
if (!in_array($hits,[
    '/tmp/kicom-membrane-loopback-base-hits',
    '/tmp/kicom-membrane-loopback-shadow-hits'
],true)) {http_response_code(500);exit;}
$receipt=hash('sha256', $method."\0".$serial."\0".$body);
if (file_put_contents($hits,$serial." ".$receipt."\n",FILE_APPEND|LOCK_EX)===false) {
    http_response_code(500);exit;
}
header('Content-Type: application/octet-stream');
header('X-Test-Receipt: '.$receipt);
header('X-Test-Method: '.$method);
header('Cache-Control: no-store');
http_response_code($method==='POST'?201:200);
echo $body;
