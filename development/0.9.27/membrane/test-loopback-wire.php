<?php
declare(strict_types=1);
/**
 * LOCAL synthetic bidirectional transport parity; fixture-loopback.php is NOT
 * a KiCom endpoint. All payloads stay on 127.0.0.1 and use test identities.
 * No real SMTP, IMAP, Slack, Passkey, update push or host policy is exercised.
 */
$base=$argv[1]??'';$shadow=$argv[2]??'';
if ($base!=='http://127.0.0.1:18729' || $shadow!=='http://127.0.0.1:18730') {
    throw new RuntimeException('Expected two exact localhost loopback servers');
}
$n=0;
function loopOk(bool $ok,string $label):void {
    global $n;++$n;
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}
function transact(string $url,string $method,string $serial,string $body):array {
    $path='/fixture-loopback.php?serial='.rawurlencode($serial);
    if($method==='GET')$path.='&body='.rawurlencode($body);
    $context=stream_context_create(['http'=>[
        'method'=>$method,'ignore_errors'=>true,'timeout'=>5,'follow_location'=>0,
        'header'=> 'Content-Type: application/octet-stream'."\r\n"
            .'Content-Length: '.($method==='POST'?strlen($body):0)."\r\n",
        'content'=>$method==='POST'?$body:''
    ]]);
    $raw=@file_get_contents($url.$path,false,$context);
    if(!is_string($raw))throw new RuntimeException('Loopback local transport failed');
    $status=0;$type='';$receipt='';$responseMethod='';
    foreach(($http_response_header??[]) as $header) {
        if(preg_match('~^HTTP/\S+\s+(\d{3})~D',$header,$m))$status=(int)$m[1];
        if(stripos($header,'Content-Type:')===0)$type=trim(substr($header,13));
        if(stripos($header,'X-Test-Receipt:')===0)$receipt=trim(substr($header,15));
        if(stripos($header,'X-Test-Method:')===0)$responseMethod=trim(substr($header,14));
    }
    return compact('status','type','receipt','responseMethod')+['body'=>$raw];
}
$fixtures=[
    ['GET','read','q=ECHO&value='.rawurlencode("Grüße + & = 🔧")],
    ['POST','slack','{"type":"event_callback","event":{"text":"test only"}}'],
    ['POST','smtp',"Subject: Synthetic\r\n\r\nLocal outbound\nNever sent"],
    ['POST','imap',"* 1 FETCH (BODY[] {5}\r\nA\0B\nC)\r\n"],
    ['POST','dev','{"operation":"SANDBOX_ONLY","data":"%0A +"}'],
    ['POST','zip',"\x50\x4b\x03\x04\x00\xff\x00".str_repeat("\x7f",4096)],
];
foreach($fixtures as [$method,$serial,$bytes]) {
    $a=transact($base,$method,$serial,$bytes);
    $b=transact($shadow,$method,$serial,$bytes);
    $expected=$method==='GET'?'serial='.$serial.'&body='.rawurlencode($bytes):$bytes;
    $expectedReceipt=hash('sha256',$method."\0".$serial."\0".$expected);
    loopOk($a===$b,'Positive '.$serial.' request and response are byte-identical across local boundary');
    loopOk($a['body']===$expected && $a['receipt']===$expectedReceipt,
        'Positive '.$serial.' receipt binds exact method, serial and opaque bytes');
    loopOk($a['status']===($method==='POST'?201:200)
        && $a['responseMethod']===$method
        && str_starts_with($a['type'],'application/octet-stream'),
        'Positive '.$serial.' status, method and binary content-type preserved');
}
$baseHits=file('/tmp/kicom-membrane-loopback-base-hits',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$shadowHits=file('/tmp/kicom-membrane-loopback-shadow-hits',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
loopOk(is_array($baseHits)&&is_array($shadowHits)
    && count($baseHits)===count($fixtures)&&count($shadowHits)===count($fixtures),
    'Local receiver records exactly one delivery per synthetic request on each server');
loopOk($baseHits===$shadowHits,'Local receiver records identical serial/receipt order without duplicate delivery');
echo "MEMBRANE_POSITIVE_LOOPBACK_TESTS_PASSED=$n\n";
