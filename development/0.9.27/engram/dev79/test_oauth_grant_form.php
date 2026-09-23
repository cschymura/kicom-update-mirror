<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramOAuthGrantForm.php';
$n=0;
function ok(bool $b,string $m):void{global $n;if(!$b)throw new RuntimeException('FAIL '.$m);$n++;echo "PASS $m\n";}
function denied(callable $f,string $m):void{$bad=false;try{$f();}catch(RuntimeException){$bad=true;}ok($bad,$m);}
$client=['client_id'=>'https://chatgpt.com/oauth/client.json'];
$resource='https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP';
$token=str_repeat('a',43);
$refresh='grant_type=refresh_token&refresh_token='.$token.'&client_id='.rawurlencode($client['client_id']).'&resource='.rawurlencode($resource);
$code='grant_type=authorization_code&code='.$token.'&code_verifier='.str_repeat('b',43).'&redirect_uri='.rawurlencode('https://chatgpt.com/connector_platform_oauth_redirect').'&client_id='.rawurlencode($client['client_id']).'&resource='.rawurlencode($resource);
$r=KiComEngramOAuthGrantForm::parse($code,$client);
ok($r['kind']==='authorization_code'&&count($r['arguments'])===6,'original code grant remains six-field intact');
$r=KiComEngramOAuthGrantForm::parse($refresh,$client);
ok($r['kind']==='refresh_token'&&$r['arguments']===['refresh_token'=>$token],'server-bound read refresh accepted');
$r=KiComEngramOAuthGrantForm::parse($refresh.'&scope=engram.read',$client);
ok($r['kind']==='refresh_token'&&!isset($r['arguments']['scope']),'optional exact read-only scope cannot expand rights');
$bad=[
['unknown grant',str_replace('refresh_token&','password&',$refresh)],
['different client',str_replace('https%3A%2F%2Fchatgpt.com%2Foauth%2Fclient.json','https%3A%2F%2Fevil.example%2Foauth%2Fclient.json',$refresh)],
['changed resource',$refresh.'&resource=evil'],
['write scope',$refresh.'&scope=engram.write'],
['duplicate grant',$refresh.'&grant_type=refresh_token'],
['duplicate token',$refresh.'&refresh_token='.$token],
['extra bearer',$refresh.'&access_token='.$token],
['client secret',$refresh.'&client_secret=secret'],
['invalid percent',$refresh.'&scope=%ZX'],
['invalid token',str_replace('refresh_token='.$token,'refresh_token=short',$refresh)],
['empty form',''],
['oversize',$refresh.str_repeat('x',2049)],
['bracket injection',$refresh.'&client_id%5B%5D=evil'],
['key normalization',$refresh.'&client.id=evil'],
['newline scope',$refresh.'&scope=engram.read%0A'],
['extra code verifier',$refresh.'&code_verifier='.str_repeat('b',43)],
['code grant with refresh parameter',$code.'&refresh_token='.$token],
['code grant write scope',$code.'&scope=engram.write'],
['code grant duplicate scope',$code.'&scope=engram.read'],
['missing client',str_replace('&client_id='.rawurlencode($client['client_id']),'',$refresh)],
['missing resource',str_replace('&resource='.rawurlencode($resource),'',$refresh)],
];
foreach($bad as [$label,$input])denied(fn()=>KiComEngramOAuthGrantForm::parse($input,$client),$label);
foreach(['owner','namespace','write_grant','credential_fingerprint','client_id_override'] as $key)
 denied(fn()=>KiComEngramOAuthGrantForm::parse($refresh.'&'.$key.'=x',$client),'injected '.$key.' rejected');
echo "KICOM_DEV79_ASSERTIONS=$n\n";
