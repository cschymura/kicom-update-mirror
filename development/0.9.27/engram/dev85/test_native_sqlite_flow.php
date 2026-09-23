<?php
declare(strict_types=1);
/**
 * DEV-85 reproducible native-PDO-SQLite regression for the exact DEV-84
 * KiComEngramOAuthTransactions source. NON-PRODUCTION synthetic data only.
 *
 * Invoke AFTER staging the full original 0.9.37 source with DEV-82 full
 * atomic/rotating patch + DEV-84 read-fallback patch, exactly once each:
 * KICOM_DEV85_NATIVE_FILE=/path/to/staging/modules/engram/KiComEngramOAuthTransactions.php php test_native_sqlite_flow.php
 *
 * Requires ext-pdo_sqlite. Do not treat the local FFI-compatible test as
 * native PDO proof. No real owner, token, credential or chat data are read.
 */
$native=getenv('KICOM_DEV85_NATIVE_FILE');
if(!is_string($native)||$native===''||!is_file($native))throw new RuntimeException('Exact staged native OAuth class required');
if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('ext-pdo_sqlite is required for native PHP integration');
require $native;
$n=0;
function ok(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS ".$label."\n";}
function rejected(callable $f,string $label):void{
    try{$f();}catch(Throwable){ok(true,$label);return;}
    throw new RuntimeException('FAIL '.$label);
}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
KiComEngramOAuthTransactions::install($db);
$client=[
 'client_id'=>'https://chatgpt.com/oauth/client.json',
 'connector_id'=>'mirage-engram',
 'host_evidence_id'=>str_repeat('c',64),
 'redirect_uri'=>'https://chatgpt.com/connector_platform_oauth_redirect',
];
$resource='https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP';
$admin=str_repeat('S',32);$fingerprint=str_repeat('b',64);$now=1790130000;
$verifier=str_repeat('v',43);
$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
$authorization=[
 'client_id'=>$client['client_id'],'redirect_uri'=>$client['redirect_uri'],
 'response_type'=>'code','scope'=>'engram.read','resource'=>$resource,
 'state'=>str_repeat('s',30),'code_challenge'=>$challenge,'code_challenge_method'=>'S256',
];
function codeFor(PDO $db,array $request,array $client,string $session,string $fingerprint,int $at):string{
 $pending=KiComEngramOAuthTransactions::begin($db,$request,$client,$session,$at);
 $owner=static fn(string $f):array=>[
   'enabled'=>true,'subject'=>'mirage-owner','credential_fingerprint'=>$f,
   'namespaces'=>['project'],'engram_rights'=>['engram.read'],
 ];
 KiComEngramOAuthTransactions::approve($db,$pending['request_id'],$session,$fingerprint,$owner,true,$at+1);
 return KiComEngramOAuthTransactions::issueApprovedCode($db,$pending['request_id'],$session,$fingerprint,$at+2)['code'];
}
function exchangeArgs(string $code,string $verifier,array $client,string $resource):array{
 return ['grant_type'=>'authorization_code','code'=>$code,'code_verifier'=>$verifier,
   'redirect_uri'=>$client['redirect_uri'],'client_id'=>$client['client_id'],'resource'=>$resource];
}
ok((int)$db->query('SELECT COUNT(*) FROM mirage_oauth_refresh_tokens')->fetchColumn()===0,'initial empty refresh schema');
$db->exec('DROP TABLE mirage_oauth_refresh_tokens');
$code=codeFor($db,$authorization,$client,$admin,$fingerprint,$now);
$legacy=KiComEngramOAuthTransactions::exchange($db,exchangeArgs($code,$verifier,$client,$resource),$client,$now+3);
ok(isset($legacy['access_token'])&&!isset($legacy['refresh_token']),'unmigrated database preserves original read authorization');
ok((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mirage_oauth_refresh_tokens'")->fetchColumn()===0,'token HTTP code cannot create private schema');
ok((KiComEngramOAuthTransactions::verify($db,$legacy['access_token'],$now+4)['authenticated']??false)===true,'pre-migration read token works');
KiComEngramOAuthTransactions::prepareRefreshSchema($db);
ok((int)$db->query('SELECT COUNT(*) FROM mirage_oauth_tokens')->fetchColumn()===1,'additive schema retains pre-existing access token');
$code=codeFor($db,$authorization,$client,$admin,$fingerprint,$now+5);
$args=exchangeArgs($code,$verifier,$client,$resource);
$first=KiComEngramOAuthTransactions::exchange($db,$args,$client,$now+8);
ok(isset($first['access_token'],$first['refresh_token'])&&$first['scope']==='engram.read','atomically issues initial read access and refresh');
ok((int)$db->query('SELECT COUNT(*) FROM mirage_oauth_tokens')->fetchColumn()===2,'old and new access coexist');
ok((int)$db->query('SELECT COUNT(*) FROM mirage_oauth_refresh_tokens')->fetchColumn()===1,'initial refresh hashed row exists');
rejected(fn()=>KiComEngramOAuthTransactions::exchange($db,$args,$client,$now+9),'used code replay rejected');
$secondCode=codeFor($db,$authorization,$client,$admin,$fingerprint,$now+10);
$bad=exchangeArgs($secondCode,str_repeat('w',43),$client,$resource);
rejected(fn()=>KiComEngramOAuthTransactions::exchange($db,$bad,$client,$now+11),'invalid PKCE rejected');
$second=KiComEngramOAuthTransactions::exchange($db,exchangeArgs($secondCode,$verifier,$client,$resource),$client,$now+12);
ok(isset($second['refresh_token']),'PKCE denial did not consume valid code');
$rotated=KiComEngramOAuthTransactions::refresh($db,$first['refresh_token'],$client,$now+3610);
ok(isset($rotated['access_token'],$rotated['refresh_token'])&&$rotated['scope']==='engram.read','expired access renews with valid refresh');
ok((KiComEngramOAuthTransactions::verify($db,$rotated['access_token'],$now+3611)['authenticated']??false)===true,'rotated read token is usable');
rejected(fn()=>KiComEngramOAuthTransactions::refresh($db,$first['refresh_token'],$client,$now+3611),'old refresh replay denied');
$other=$client;$other['host_evidence_id']=str_repeat('d',64);
rejected(fn()=>KiComEngramOAuthTransactions::refresh($db,$rotated['refresh_token'],$other,$now+3611),'host mismatch denied');
KiComEngramOAuthTransactions::revoke($db,$rotated['access_token']);
rejected(fn()=>KiComEngramOAuthTransactions::refresh($db,$rotated['refresh_token'],$client,$now+3611),'revoked parent denies refresh');
$db->exec('ALTER TABLE mirage_oauth_refresh_tokens RENAME TO healthy_refresh_backup');
$db->exec('CREATE TABLE mirage_oauth_refresh_tokens(refresh_hash TEXT PRIMARY KEY)');
$before=(int)$db->query('SELECT COUNT(*) FROM mirage_oauth_tokens')->fetchColumn();
$thirdCode=codeFor($db,$authorization,$client,$admin,$fingerprint,$now+20);
rejected(fn()=>KiComEngramOAuthTransactions::exchange($db,exchangeArgs($thirdCode,$verifier,$client,$resource),$client,$now+23),'existing malformed refresh schema fails closed');
ok((int)$db->query('SELECT COUNT(*) FROM mirage_oauth_tokens')->fetchColumn()===$before,'malformed-schema failure rolls back access insert');
$db->exec('DROP TABLE mirage_oauth_refresh_tokens');
$db->exec('ALTER TABLE healthy_refresh_backup RENAME TO mirage_oauth_refresh_tokens');
$recovered=KiComEngramOAuthTransactions::exchange($db,exchangeArgs($thirdCode,$verifier,$client,$resource),$client,$now+24);
ok(isset($recovered['refresh_token']),'failed exchange code remains redeemable after repair');
$other=$client;$other['client_id']='https://chatgpt.com/oauth/other/client.json';
rejected(fn()=>KiComEngramOAuthTransactions::refresh($db,$recovered['refresh_token'],$other,$now+25),'foreign client denied');
$other=$client;$other['connector_id']='foreign-connector';
rejected(fn()=>KiComEngramOAuthTransactions::refresh($db,$recovered['refresh_token'],$other,$now+25),'foreign connector denied');
rejected(fn()=>KiComEngramOAuthTransactions::refresh($db,$recovered['refresh_token'],$client,$now+604825),'expired refresh denied');
ok($db->query('PRAGMA quick_check')->fetchColumn()==='ok','SQLite integrity');
echo "DEV85_NATIVE_PDO_SQLITE_ASSERTIONS=$n\n";
