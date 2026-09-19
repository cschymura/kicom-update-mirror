<?php
declare(strict_types=1);
/** Isolated cross-UID transport check; no external endpoints or credentials. */
if(($argv[1]??'')!=='http://127.0.0.1:18731')throw new RuntimeException('Local fixture required');
$n=0;
function crossOk(bool $c,string $s):void {global $n;++$n;if(!$c)throw new RuntimeException('FAIL '.$s);echo 'PASS '.$s."\n";}
function send(string $method,string $serial,string $payload):array {
    $url='http://127.0.0.1:18731/fixture-isolated-transport.php?serial='.$serial;
    $context=stream_context_create(['http'=>[
        'method'=>$method,'ignore_errors'=>true,'timeout'=>5,'follow_location'=>0,
        'header'=>'Content-Type: application/octet-stream'."\r\n",
        'content'=>$method==='POST'?$payload:''
    ]]);
    $response=@file_get_contents($url,false,$context);
    if(!is_string($response))throw new RuntimeException('Cross-UID response absent');
    $status=0;$type='';$receipt='';
    foreach(($http_response_header??[]) as $header) {
        if(preg_match('~^HTTP/\S+\s+(\d{3})~D',$header,$m))$status=(int)$m[1];
        if(stripos($header,'Content-Type:')===0)$type=trim(substr($header,13));
        if(stripos($header,'X-Local-Receipt:')===0)$receipt=trim(substr($header,16));
    }
    return compact('status','type','receipt','response');
}
foreach([
    ['GET','read',''],
    ['POST','slack','{"type":"app_mention","text":"synthetic-only"}'],
    ['POST','mail',"Subject: Fixture\r\n\r\nSynthetic only\x00"],
    ['POST','binary',"\x50\x4b\x03\x04\x00\xff".str_repeat("\x7a",4096)]
] as [$method,$serial,$payload]) {
    $r=send($method,$serial,$payload);
    $expected=$method==='GET'?'serial='.$serial:$payload;
    crossOk($r['response']===$expected,'Cross-UID '.$serial.' body remains byte-identical');
    crossOk($r['status']===($method==='GET'?200:201)
        && $r['type']==='application/octet-stream'
        && $r['receipt']===hash('sha256',$method."\0".$serial."\0".$expected),
        'Cross-UID '.$serial.' status/content-type and exact receipt');
}
echo "MEMBRANE_CROSS_UID_TRANSPORT_TESTS_PASSED=$n\n";
