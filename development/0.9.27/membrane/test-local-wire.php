<?php
declare(strict_types=1);
/**
 * Compare isolated native R3 HTTP data plane byte-for-byte with the passive
 * observer loaded via PHP auto_prepend_file. No live host, no messages sent.
 */
$base = $argv[1] ?? '';
$shadow = $argv[2] ?? '';
if (!preg_match('~^http://127\.0\.0\.1:[0-9]{4,5}$~D',$base)
    || !preg_match('~^http://127\.0\.0\.1:[0-9]{4,5}$~D',$shadow)) {
    throw new RuntimeException('Only two local isolated HTTP fixtures permitted');
}
$checks=0;
function wireCheck(bool $ok,string $name):void {
    global $checks;
    ++$checks;
    if(!$ok)throw new RuntimeException('FAIL '.$name);
    echo "PASS $name\n";
}
function get(string $root,string $path):array {
    $context=stream_context_create(['http'=>[
        'method'=>'GET','ignore_errors'=>true,'timeout'=>5,'follow_location'=>0
    ]]);
    $body=@file_get_contents($root.'/'.$path,false,$context);
    if(!is_string($body))throw new RuntimeException('Local response unavailable');
    $status=0;
    $type='';
    foreach(($http_response_header??[]) as $header) {
        if(preg_match('~^HTTP/\S+\s+(\d{3})~D',$header,$m))$status=(int)$m[1];
        if(stripos($header,'Content-Type:')===0)$type=trim(substr($header,13));
    }
    // KiCom assigns a request ID per request; this is the only volatility
    // removed from the fixed-route fixture. No body payload is decoded.
    $normalized=preg_replace('/^FACT request_id="[^"]+"$/m',
        'FACT request_id="<volatile>"',$body);
    return ['status'=>$status,'type'=>$type,'body'=>$normalized];
}
foreach([
    ['index.php?q=PING','KCL ping'],
    ['index.php?q=HELLO','KCL hello'],
    ['index.php?q=ECHO&value='.rawurlencode('A B + ä'),'KCL echo Unicode'],
    ['index.php?q=DESCRIBE','KCL capabilities'],
    ['api.php','Original GET-to-POST method guard']
] as [$path,$label]) {
    $a=get($base,$path);$b=get($shadow,$path);
    wireCheck($a===$b,'Byte-identical normalized response: '.$label);
    wireCheck($a['status']>0 && $a['status']<500,
        'Original endpoint remains reachable: '.$label);
}
foreach([
    ['index.php?q=SLACK_STATUS','Slack status'],
    ['index.php?q=MAIL_STATUS','Mail status'],
    ['index.php?q=CHAT_UPDATE_STATUS','Chat transport status']
] as [$path,$label]) {
    $a=get($base,$path);$b=get($shadow,$path);
    wireCheck($a['status']===$b['status'] && $a['type']===$b['type'],
        'Read-only transport channel retained: '.$label);
    wireCheck(str_starts_with($a['body'],'KCL/1'."\n")
        && str_starts_with($b['body'],'KCL/1'."\n"),
        'Original communication protocol retained: '.$label);
}
echo "MEMBRANE_LOCAL_WIRE_TESTS_PASSED=$checks\n";
