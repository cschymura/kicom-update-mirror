<?php
declare(strict_types=1);
/**
 * Negative-path, byte-aware POST transport parity on two disposable R3
 * localhost fixtures. Invalid/missing credentials only; NEVER real tokens,
 * mail recipients, Slack events, successful mutations, or real ZIP uploads.
 */
$base=$argv[1]??'';$shadow=$argv[2]??'';
foreach ([$base,$shadow] as $url) {
    if (!preg_match('~^http://127\.0\.0\.1:(?:18727|18728)$~D',$url)) {
        throw new RuntimeException('Only dedicated isolated local R3 fixtures permitted');
    }
}
if ($base===$shadow) throw new RuntimeException('Two distinct local instances required');
$n=0;
function postOk(bool $pass,string $name):void {
    global $n; ++$n;
    if (!$pass) throw new RuntimeException('FAIL '.$name);
    echo "PASS $name\n";
}
function sendPost(string $base,string $path,string $body,string $type):array {
    $context=stream_context_create(['http'=>[
        'method'=>'POST',
        'header'=>"Content-Type: $type\r\nContent-Length: ".strlen($body)."\r\n",
        'content'=>$body,
        'ignore_errors'=>true,
        'follow_location'=>0,
        'timeout'=>6
    ]]);
    $raw=@file_get_contents($base.'/api.php'.$path,false,$context);
    if (!is_string($raw))throw new RuntimeException('Local POST unavailable');
    $status=0;$responseType='';
    foreach (($http_response_header??[]) as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~D',$header,$m))$status=(int)$m[1];
        if (stripos($header,'Content-Type:')===0)$responseType=trim(substr($header,13));
    }
    // Only existing KCL request IDs are non-deterministic. JSON errors
    // remain intact; no substantive error code or response is normalized.
    $fixed=preg_replace('/^FACT request_id="[^"]+"$/m',
        'FACT request_id="<volatile>"',$raw);
    return ['status'=>$status,'type'=>$responseType,'body'=>$fixed];
}
$cases=[
    ['','', 'application/json','Empty generic POST'],
    ['','{not valid JSON', 'application/json','Invalid JSON generic POST'],
    ['','{"operation":"UNKNOWN_MEMBRANE_FIXTURE"}', 'application/json','Unsupported JSON operation'],
    ['','operation=AUTONOMY_BATCH', 'application/x-www-form-urlencoded','Batch with no session'],
    ['','operation=MEMORY_PROPOSE&resource=DOES_NOT_EXIST',
        'application/x-www-form-urlencoded','No-op invalid memory proposal'],
    ['?q=DEV_AUTH','{not-json', 'application/json','DEV auth malformed body'],
    ['?q=DEV_API','{not-json', 'application/json','DEV API malformed body'],
    ['?q=CHAT_UPDATE_RAW',"\x00\xff\x00",'application/octet-stream','Raw update no authorized session'],
    ['?q=AUTONOMY_UPDATE_UPLOAD',"\x00\xff\x00",
        'application/octet-stream','Autonomy raw upload no session'],
];
foreach($cases as [$path,$body,$type,$label]) {
    $a=sendPost($base,$path,$body,$type);
    $b=sendPost($shadow,$path,$body,$type);
    postOk($a===$b,'POST status, type and full error response preserved: '.$label);
    postOk($a['status']>=400 && $a['status']<500,
        'Unprivileged invalid request remains rejected: '.$label);
    postOk(!str_contains($a['body'],'next_token=') && !str_contains($a['body'],'"next_token"'),
        'Invalid unauthenticated POST does not rotate or return a session token: '.$label);
}
echo "MEMBRANE_POST_NEGATIVE_TESTS_PASSED=$n\n";
