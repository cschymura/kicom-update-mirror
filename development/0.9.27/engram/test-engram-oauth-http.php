<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramOAuthHttp.php';
$n=0;
function check53(bool $v,string $name):void{
 global $n;if(!$v)throw new RuntimeException('FAIL '.$name);
 ++$n;echo "PASS $name\n";
}
$now=1800000800;
$host=[
 'enabled'=>true,'operator_approved'=>true,'host_isolation_verified'=>true,
 'review_enabled'=>true,'mcp_connector_enabled'=>true,'oauth_enabled'=>true,
 'runtime_source'=>'server-only-reviewed','private_memory_scope'=>'dev-verified-owner',
 'expected_origin'=>'https://kicom.rurtalbahn.info','rp_id'=>'kicom.rurtalbahn.info',
];
$inactive=$host;$inactive['oauth_enabled']=false;
$httpGet=['HTTPS'=>'on','HTTP_HOST'=>'kicom.rurtalbahn.info','REQUEST_METHOD'=>'GET'];
$httpPost=['HTTPS'=>'on','HTTP_HOST'=>'kicom.rurtalbahn.info',
 'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/x-www-form-urlencoded'];
$decode=static fn(array $r):array=>json_decode($r['body'],true,16,JSON_THROW_ON_ERROR);
$pr=KiComEngramOAuthHttp::discovery($httpGet,$host,'protected_resource');
$p=$decode($pr);
check53($pr['http_status']===200
 &&$p['resource']==='https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP'
 &&$p['authorization_servers']===['https://kicom.rurtalbahn.info']
 &&$p['scopes_supported']===['engram.read'],
 'protected-resource metadata binds exact KiCom MCP resource and issuer');
check53(($pr['headers']['Cache-Control']??null)==='no-store, private'
 &&($pr['headers']['Content-Type']??null)==='application/json; charset=utf-8',
 'public discovery uses JSON content type and no-store headers');
$as=KiComEngramOAuthHttp::discovery($httpGet,$host,'authorization_server');
$metadata=$decode($as);
check53($as['http_status']===200
 &&$metadata['issuer']===$p['authorization_servers'][0]
 &&$metadata['authorization_endpoint']==='https://kicom.rurtalbahn.info/admin.php?engram_oauth=1'
 &&$metadata['token_endpoint']==='https://kicom.rurtalbahn.info/api.php?q=ENGRAM_OAUTH_TOKEN',
 'issuer metadata advertises the exact future first-party authorization and token routes');
check53($metadata['code_challenge_methods_supported']===['S256']
 &&$metadata['token_endpoint_auth_methods_supported']===['none']
 &&$metadata['response_types_supported']===['code']
 &&$metadata['grant_types_supported']===['authorization_code'],
 'OAuth metadata supports only public-client authorization code with mandatory S256');
check53($metadata['client_id_metadata_document_supported']===false
 &&!isset($metadata['registration_endpoint'])
 &&!isset($metadata['authorization_response_iss_parameter_supported']),
 'unimplemented CIMD, DCR and issuer-response features are not falsely advertised');
check53(KiComEngramOAuthHttp::discovery($httpGet,$inactive,'protected_resource')['http_status']===404,
 'real 0.9.31 inactive/default OAuth policy never advertises a nonexistent login');
foreach(['operator_approved','host_isolation_verified','enabled','mcp_connector_enabled','review_enabled'] as $flag){
 $off=$host;$off[$flag]=false;
 check53(KiComEngramOAuthHttp::discovery($httpGet,$off,'authorization_server')['http_status']===404,
  'missing trusted '.$flag.' denies metadata');
}
$h=$httpGet;$h['HTTPS']='off';
check53(KiComEngramOAuthHttp::discovery($h,$host,'protected_resource')['http_status']===404,
 'HTTP resource discovery rejected');
$h=$httpGet;$h['HTTP_HOST']='foreign.example';
check53(KiComEngramOAuthHttp::discovery($h,$host,'authorization_server')['http_status']===404,
 'foreign host discovery rejected');
check53(KiComEngramOAuthHttp::discovery($httpPost,$host,'protected_resource')['http_status']===404,
 'POST does not disclose protected-resource metadata');
check53(KiComEngramOAuthHttp::discovery($httpGet,$host,'other')['http_status']===404,
 'unknown OAuth metadata kind unavailable');
$challenge=KiComEngramOAuthHttp::challenge($httpGet,$host);
check53($challenge['http_status']===401&&$challenge['body']===''
 &&str_contains($challenge['headers']['WWW-Authenticate'],
  'resource_metadata="https://kicom.rurtalbahn.info/.well-known/oauth-protected-resource"')
 &&str_contains($challenge['headers']['WWW-Authenticate'],'scope="engram.read"'),
 'active resource returns OAuth 401 with protected-resource discovery challenge');
check53(KiComEngramOAuthHttp::challenge($httpGet,$inactive)['http_status']===404,
 'inactive resource fails closed instead of showing broken OAuth login');

$db=new PDO('sqlite::memory:');
KiComEngramOAuthTransactions::install($db);
$client=['client_id'=>'https://chatgpt.com/oauth/client.json',
 'redirect_uri'=>'https://chatgpt.com/connector/oauth/callback',
 'connector_id'=>'mirage-test-oauth',
 'host_evidence_id'=>hash('sha256','synthetic-host-review')];
$session=bin2hex(random_bytes(32));
$fp=hash('sha256','synthetic-verified-owner-credential');
$owner=['enabled'=>true,'subject'=>'mirage-owner',
 'credential_fingerprint'=>$fp,'namespaces'=>['project'],
 'engram_rights'=>['engram.read']];
$lookup=static fn(string $x):?array=>$x===$fp?$owner:null;
$verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
$challengeS256=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
$resource='https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP';
$authorization=[
 'client_id'=>$client['client_id'],'redirect_uri'=>$client['redirect_uri'],
 'response_type'=>'code','scope'=>'engram.read','resource'=>$resource,
 'state'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'='),
 'code_challenge'=>$challengeS256,'code_challenge_method'=>'S256',
];
$started=KiComEngramOAuthTransactions::begin($db,$authorization,$client,$session,$now);
$approved=KiComEngramOAuthTransactions::approve(
 $db,$started['request_id'],$session,$fp,$lookup,true,$now+1);
$issued=KiComEngramOAuthTransactions::issueApprovedCode(
 $db,$started['request_id'],$session,$fp,$now+2);
check53($approved['approved']===true && strlen($issued['code'])===43,
 'synthetic separately owner-approved authorization code available for HTTPS token tests');
$form=[
 'grant_type'=>'authorization_code','code'=>$issued['code'],
 'code_verifier'=>$verifier,'redirect_uri'=>$client['redirect_uri'],
 'client_id'=>$client['client_id'],'resource'=>$resource,
];
$wire=http_build_query($form,'','&',PHP_QUERY_RFC3986);
$tryToken=static fn(array $s,string $body,array $policy):array=>
 KiComEngramOAuthHttp::token($s,$body,$policy,$client,$db,$now+3);
check53($tryToken($httpPost,$wire,$inactive)['http_status']===404,
 'inactive policy denies token exchange without consuming code');
$h=$httpPost;$h['HTTPS']='off';
check53($tryToken($h,$wire,$host)['http_status']===404,
 'HTTP token exchange refused before database access');
$h=$httpPost;$h['HTTP_HOST']='example.org';
check53($tryToken($h,$wire,$host)['http_status']===404,
 'foreign host refused before token exchange');
$h=$httpPost;$h['REQUEST_METHOD']='GET';
check53($tryToken($h,$wire,$host)['http_status']===404,
 'GET cannot exchange authorization code');
$h=$httpPost;$h['CONTENT_TYPE']='application/json';
check53($tryToken($h,$wire,$host)['http_status']===404,
 'JSON token exchange rejected');
$h=$httpPost;$h['HTTP_AUTHORIZATION']='Bearer synthetic-injected';
check53($tryToken($h,$wire,$host)['http_status']===404,
 'untrusted Authorization header rejected at public-client token endpoint');
$bad=$tryToken($httpPost,$wire.'&code=another',$host);
check53($bad['http_status']===400&&$decode($bad)['error']==='invalid_grant',
 'duplicate form field rejected without using PHP normalized array syntax');
$bad=$tryToken($httpPost,$wire.'&client_secret=injected',$host);
check53($bad['http_status']===400&&$decode($bad)['error']==='invalid_grant',
 'injected client secret rejected instead of broadening public-client auth');
$bad=$tryToken($httpPost,str_replace('%3A','%ZZ',$wire),$host);
check53($bad['http_status']===400&&$decode($bad)['error']==='invalid_grant',
 'malformed percent escape rejected');
$changed=$form;$changed['resource']='https://attacker.invalid';
$bad=$tryToken($httpPost,http_build_query($changed,'','&',PHP_QUERY_RFC3986),$host);
check53($bad['http_status']===400&&$decode($bad)['error']==='invalid_grant',
 'foreign resource cannot exchange the existing single-use authorization code');
$valid=$tryToken($httpPost,$wire,$host);
$received=$decode($valid);
check53($valid['http_status']===200
 &&$received['token_type']==='Bearer'
 &&$received['scope']==='engram.read'
 &&strlen($received['access_token'])===43
 &&($valid['headers']['Cache-Control']??null)==='no-store, private',
 'HTTPS OAuth token endpoint returns opaque owner-scoped short-lived bearer token');
$identity=KiComEngramOAuthTransactions::verify($db,$received['access_token'],$now+4);
check53($identity['connector_id']===$client['connector_id']
 &&$identity['credential_fingerprint']===$fp
 &&$identity['host_evidence_id']===$client['host_evidence_id'],
 'verified HTTP-exchanged token binds exact server-owned connector, owner and host');
$replay=$tryToken($httpPost,$wire,$host);
check53($replay['http_status']===400&&$decode($replay)['error']==='invalid_grant',
 'authorization code cannot be exchanged twice');
KiComEngramOAuthTransactions::revoke($db,$received['access_token']);
check53(KiComEngramOAuthTransactions::verify($db,$received['access_token'],$now+4)===null,
 'revoked HTTP-exchanged token no longer resolves a connector identity');
echo "KICOM_ENGRAM_OAUTH_HTTP_TESTS_PASSED=$n\n";
