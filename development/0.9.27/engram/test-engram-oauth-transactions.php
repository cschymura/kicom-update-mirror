<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramOAuthTransactions.php';
$checks=0;
function t51(bool $v,string $name):void{
 global $checks;
 if(!$v)throw new RuntimeException('FAIL '.$name);
 ++$checks;echo "PASS ".$name."\n";
}
function denied51(callable $cb,string $name):void{
 try{$cb();}catch(RuntimeException $e){
  t51($e->getMessage()==='MIRAGE_OAUTH_REQUEST_DENIED',$name);return;
 }
 throw new RuntimeException('FAIL '.$name);
}
$db=new PDO('sqlite::memory:');
KiComEngramOAuthTransactions::install($db);
$now=1800000700;
$session=str_repeat('a',48);
$client=[
 'client_id'=>'https://chatgpt.com/oauth/client.json',
 'redirect_uri'=>'https://chatgpt.com/connector/oauth/callback',
 'connector_id'=>'mirage-chatgpt-oauth',
 'host_evidence_id'=>hash('sha256','synthetic-reviewed-host'),
];
$verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
$p=[
 'client_id'=>$client['client_id'],'redirect_uri'=>$client['redirect_uri'],
 'response_type'=>'code','scope'=>'engram.read',
 'resource'=>'https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP',
 'state'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'='),
 'code_challenge'=>$challenge,'code_challenge_method'=>'S256',
];
$fp=hash('sha256','synthetic verified signed KiCom credential');
$owner=['enabled'=>true,'credential_fingerprint'=>$fp,
 'subject'=>'mirage-owner','namespaces'=>['project'],
 'engram_rights'=>['engram.read','engram.write']];
$lookup=static fn(string $f):?array=>$f===$fp?$owner:null;
$start=KiComEngramOAuthTransactions::begin($db,$p,$client,$session,$now);
t51(isset($start['request_id'])&&strlen($start['request_id'])===43,
 'fresh opaque pending OAuth request, code still withheld');
denied51(fn()=>KiComEngramOAuthTransactions::issueApprovedCode($db,$start['request_id'],$now),
 'authorization code cannot be issued before explicit approved consent');
denied51(fn()=>KiComEngramOAuthTransactions::approve(
 $db,$start['request_id'],$session,$fp,$lookup,false,$now),
 'explicit consent is mandatory');
denied51(fn()=>KiComEngramOAuthTransactions::approve(
 $db,$start['request_id'],str_repeat('b',48),$fp,$lookup,true,$now),
 'foreign admin session cannot approve pending authorization');
denied51(fn()=>KiComEngramOAuthTransactions::approve(
 $db,$start['request_id'],$session,hash('sha256','other-passkey'),$lookup,true,$now),
 'nonbound passkey fingerprint cannot approve');
$ok=KiComEngramOAuthTransactions::approve(
 $db,$start['request_id'],$session,$fp,$lookup,true,$now+10);
t51($ok['approved']===true&&$ok['redirect_uri']===$client['redirect_uri']
    &&$ok['state']===$p['state'],'fresh verified bound owner approves exact client and state');
denied51(fn()=>KiComEngramOAuthTransactions::approve(
 $db,$start['request_id'],$session,$fp,$lookup,true,$now+11),
 'consent cannot be replayed');
$issued=KiComEngramOAuthTransactions::issueApprovedCode($db,$start['request_id'],$now+12);
t51(strlen($issued['code'])===43&&$issued['state']===$p['state']
    &&$issued['redirect_uri']===$client['redirect_uri'],'one-time code issued for pinned callback');
denied51(fn()=>KiComEngramOAuthTransactions::issueApprovedCode($db,$start['request_id'],$now+12),
 'authorization code cannot be emitted twice');
$exchange=[
 'grant_type'=>'authorization_code','code'=>$issued['code'],
 'code_verifier'=>$verifier,'redirect_uri'=>$client['redirect_uri'],
 'client_id'=>$client['client_id'],'resource'=>$p['resource'],
];
$bad=$exchange;$bad['code_verifier']=str_repeat('x',64);
denied51(fn()=>KiComEngramOAuthTransactions::exchange($db,$bad,$client,$now+13),
 'wrong PKCE verifier denies exchange without consuming code');
$bad=$exchange;$bad['resource']='https://example.org/mcp';
denied51(fn()=>KiComEngramOAuthTransactions::exchange($db,$bad,$client,$now+13),
 'foreign OAuth resource denied');
$bad=$exchange;$bad['client_id']='https://evil.example/oauth/client.json';
denied51(fn()=>KiComEngramOAuthTransactions::exchange($db,$bad,$client,$now+13),
 'foreign OAuth client denied');
$bad=$exchange;$bad['redirect_uri']='https://chatgpt.com/other';
denied51(fn()=>KiComEngramOAuthTransactions::exchange($db,$bad,$client,$now+13),
 'redirect mismatch denied');
$bad=$exchange;$bad['access_token']='request injection';
denied51(fn()=>KiComEngramOAuthTransactions::exchange($db,$bad,$client,$now+13),
 'extra token request field denied');
$token=KiComEngramOAuthTransactions::exchange($db,$exchange,$client,$now+14);
t51($token['token_type']==='Bearer'&&$token['scope']==='engram.read'
 &&strlen($token['access_token'])===43&&$token['expires_in']===3600,
 'code+PKCE generates a scoped, bounded, opaque access token');
denied51(fn()=>KiComEngramOAuthTransactions::exchange($db,$exchange,$client,$now+14),
 'authorization code replay refused');
$who=KiComEngramOAuthTransactions::verify($db,$token['access_token'],$now+15);
t51(($who['authenticated']??false)===true
 &&$who['connector_id']===$client['connector_id']
 &&$who['host_evidence_id']===$client['host_evidence_id']
 &&$who['credential_fingerprint']===$fp
 &&$who['owner_binding']===hash('sha256',"mirage-owner\0".$fp),
 'server-side verified token resolves exact DEV-44 connector/host/owner identity');
t51(!isset($who['access_token'])&&!isset($who['token_hash']),
 'token and hash never disclosed in resolved connector identity');
t51(KiComEngramOAuthTransactions::verify($db,str_repeat('a',43),$now+15)===null,
 'unknown token rejected');
t51(KiComEngramOAuthTransactions::verify($db,$token['access_token'],$now+3614)===null,
 'expired token rejected');
KiComEngramOAuthTransactions::revoke($db,$token['access_token']);
t51(KiComEngramOAuthTransactions::verify($db,$token['access_token'],$now+16)===null,
 'revoked token rejected even before expiry');
$badp=$p;$badp['code_challenge_method']='plain';
denied51(fn()=>KiComEngramOAuthTransactions::begin($db,$badp,$client,$session,$now),
 'weak PKCE plain method denied');
$badp=$p;$badp['redirect_uri']='https://chatgpt.com/foreign';
denied51(fn()=>KiComEngramOAuthTransactions::begin($db,$badp,$client,$session,$now),
 'unpinned OAuth redirect URI denied');
$badp=$p;$badp['scope']='engram.write';
denied51(fn()=>KiComEngramOAuthTransactions::begin($db,$badp,$client,$session,$now),
 'write scope denied');
$badp=$p;$badp['subject']='other';
denied51(fn()=>KiComEngramOAuthTransactions::begin($db,$badp,$client,$session,$now),
 'client-injected subject denied');
$another=KiComEngramOAuthTransactions::begin($db,$p,$client,$session,$now);
denied51(fn()=>KiComEngramOAuthTransactions::approve(
 $db,$another['request_id'],$session,$fp,$lookup,true,$now+301),
 'expired authorization request cannot be approved');
$revokedOwner=$owner;$revokedOwner['enabled']=false;
$more=KiComEngramOAuthTransactions::begin($db,$p,$client,$session,$now);
denied51(fn()=>KiComEngramOAuthTransactions::approve(
 $db,$more['request_id'],$session,$fp,
 static fn(string $f):?array=>$revokedOwner,true,$now+1),
 'revoked owner cannot approve client consent');
$remaining=$db->query('SELECT count(*) FROM mirage_oauth_tokens')->fetchColumn();
t51((int)$remaining===1,'failed grants never mint access tokens');
echo "KICOM_ENGRAM_OAUTH_TRANSACTION_TESTS_PASSED=$checks\n";
