<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramOAuthMcpHostBridge.php';

$count=0;
function assert54(bool $result,string $message):void{
    global $count;
    if(!$result)throw new RuntimeException('FAIL '.$message);
    ++$count;echo "PASS $message\n";
}
function purge54(string $path):void{
    if(is_link($path)||is_file($path)){@unlink($path);return;}
    if(!is_dir($path))return;
    foreach(scandir($path) as $name)
        if($name!=='.'&&$name!=='..')purge54($path.'/'.$name);
    @rmdir($path);
}

$root=sys_get_temp_dir().'/mirage-oauth-mcp-'.bin2hex(random_bytes(8));
$web=$root.'/web';
$private=$root.'/engram-private';
$data=$private.'/data';
$registryPath=$private.'/owners/engram-owners.json';
$activationFile=$data.'/mirage-activation.sqlite';
$oauthFile=$data.'/mirage-oauth.sqlite';
mkdir($root,0700);mkdir($web,0755);mkdir($private,0700);
mkdir($data,0700);mkdir($private.'/owners',0700);
try {
    $now=1800009000;
    $fp=hash('sha256','synthetic original KiCom passkey');
    $binding=hash('sha256',"mirage-owner\0".$fp);
    $hostId=hash('sha256','synthetic verified host evidence');
    $owner=[
        'enabled'=>true,'credential_fingerprint'=>$fp,'subject'=>'mirage-owner',
        'namespaces'=>['project'],'engram_rights'=>['engram.read']
    ];
    file_put_contents($registryPath,json_encode([
        'schema'=>1,'owners'=>[$fp=>$owner]],JSON_THROW_ON_ERROR));
    chmod($registryPath,0600);
    $runtime=[
        'enabled'=>true,'operator_approved'=>true,
        'host_isolation_verified'=>true,'review_enabled'=>true,
        'mcp_connector_enabled'=>true,'oauth_enabled'=>true,
        'runtime_source'=>'server-only-reviewed',
        'private_memory_scope'=>'dev-verified-owner',
        'expected_origin'=>'https://kicom.rurtalbahn.info',
        'rp_id'=>'kicom.rurtalbahn.info',
        'admin_subject'=>'mirage-owner','web_root'=>$web,
        'data_dir'=>$data,'owner_registry'=>$registryPath,
        'mcp_connector_id'=>'mirage-oauth-mcp-dev',
        'owner_binding'=>$binding,'host_evidence_id'=>$hostId
    ];
    $inactive=$runtime;
    foreach(['enabled','operator_approved','host_isolation_verified',
             'review_enabled','mcp_connector_enabled','oauth_enabled']as $flag)
        $inactive[$flag]=false;
    $inactive['runtime_source']='setup-pending';
    $inactive['private_memory_scope']='setup-pending';

    $server=[
        'HTTPS'=>'on','HTTP_HOST'=>'kicom.rurtalbahn.info',
        'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json',
        'HTTP_ACCEPT'=>'application/json, text/event-stream',
        'HTTP_MCP_PROTOCOL_VERSION'=>'2025-06-18'
    ];
    $rpc=static fn(int $id,string $method,array $params=[]):string=>
        json_encode(['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,
            'params'=>$params],JSON_THROW_ON_ERROR);
    $send=static fn(array $s,string $wire,array $policy):array=>
        KiComEngramOAuthMcpHostBridge::handle($s,$wire,$policy,$web,$now);
    $body=$rpc(1,'tools/list');
    $before=$send($server,$body,$inactive);
    assert54($before['http_status']===404&&$before['body']==='',
        'real 0.9.31-style inactive host denies even MCP tools/list');
    assert54(!is_file($oauthFile)&&!is_file($activationFile),
        'inactive MCP route did not create any private SQLite files');
    assert54($send($server,$body,$runtime)['http_status']===404,
        'enabled config alone does not create missing activation or OAuth storage');
    assert54(!is_file($oauthFile)&&!is_file($activationFile),
        'enabled-but-unprovisioned host remains read-only');

    $activation=new PDO('sqlite:'.$activationFile);
    $activation->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $activation->exec('CREATE TABLE activation_state(
        singleton INTEGER PRIMARY KEY,state TEXT,owner_binding TEXT,host_evidence_id TEXT)');
    $ins=$activation->prepare('INSERT INTO activation_state VALUES(1,?,?,?)');
    $ins->execute(['active',$binding,$hostId]);
    chmod($activationFile,0600);

    $oauth=new PDO('sqlite:'.$oauthFile);
    KiComEngramOAuthTransactions::install($oauth);
    chmod($oauthFile,0600);
    $store=new KiComEngramStore($data,$web);
    $created=$store->create('mirage-owner','project','technical',
        'Synthetic cobalt horizon memory on operator test server.',
        'synthetic_test','mcp://dev54');
    assert54(($created['revision']??null)===1,
        'artificial owner-scoped memory exists in private SQLite storage');

    $client=[
        'client_id'=>'https://chatgpt.com/oauth/client.json',
        'redirect_uri'=>'https://chatgpt.com/connector/oauth/callback',
        'connector_id'=>'mirage-oauth-mcp-dev',
        'host_evidence_id'=>$hostId
    ];
    $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
    $challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $adminSession=bin2hex(random_bytes(32));
    $params=[
        'client_id'=>$client['client_id'],'redirect_uri'=>$client['redirect_uri'],
        'response_type'=>'code','scope'=>'engram.read',
        'resource'=>KiComEngramOAuthHttp::RESOURCE,
        'state'=>rtrim(strtr(base64_encode(random_bytes(20)),'+/','-_'),'='),
        'code_challenge'=>$challenge,'code_challenge_method'=>'S256'
    ];
    $pending=KiComEngramOAuthTransactions::begin(
        $oauth,$params,$client,$adminSession,$now-20);
    $lookup=static fn(string $f):?array=>$f===$fp?$owner:null;
    $approved=KiComEngramOAuthTransactions::approve(
        $oauth,$pending['request_id'],$adminSession,$fp,$lookup,true,$now-10);
    $code=KiComEngramOAuthTransactions::issueApprovedCode(
        $oauth,$pending['request_id'],$adminSession,$fp,$now-9);
    assert54(($approved['approved']??null)===true&&strlen($code['code'])===43,
        'explicit synthetic owner-bound consent issues one OAuth authorization code');
    $exchange=[
        'grant_type'=>'authorization_code','code'=>$code['code'],
        'code_verifier'=>$verifier,'redirect_uri'=>$client['redirect_uri'],
        'client_id'=>$client['client_id'],'resource'=>KiComEngramOAuthHttp::RESOURCE
    ];
    $token=KiComEngramOAuthTransactions::exchange(
        $oauth,$exchange,$client,$now-8);
    $auth=$server;
    $auth['HTTP_AUTHORIZATION']='Bearer '.$token['access_token'];

    $hello=$send($auth,$rpc(2,'initialize',[
        'protocolVersion'=>'2025-06-18','capabilities'=>[],
        'clientInfo'=>['name'=>'synthetic-chatgpt','version'=>'1.0.0']
    ]),$runtime);
    $helloJson=json_decode($hello['body'],true,16,JSON_THROW_ON_ERROR);
    assert54($hello['http_status']===200&&
        $helloJson['result']['protocolVersion']==='2025-06-18',
        'OAuth token authenticates real MCP JSON-RPC initialize through host bridge');
    $tools=$send($auth,$rpc(3,'tools/list'),$runtime);
    $toolsJson=json_decode($tools['body'],true,16,JSON_THROW_ON_ERROR);
    assert54($tools['http_status']===200&&count($toolsJson['result']['tools'])===1&&
        $toolsJson['result']['tools'][0]['name']==='engram_search',
        'only read-only Engram tool is advertised to authenticated OAuth connector');
    assert54($toolsJson['result']['tools'][0]['securitySchemes']===
        [['type'=>'oauth2','scopes'=>['engram.read']]],
        'OAuth-protected MCP tool declares exact read scope');

    $search=$rpc(4,'tools/call',['name'=>'engram_search',
        'arguments'=>['query'=>'cobalt horizon','limit'=>2]]);
    $response=$send($auth,$search,$runtime);
    $json=json_decode($response['body'],true,16,JSON_THROW_ON_ERROR);
    $memories=json_decode($json['result']['content'][0]['text'],true,16,JSON_THROW_ON_ERROR);
    assert54($response['http_status']===200&&
        $json['result']['isError']===false&&count($memories)===1,
        'genuine OAuth bearer passes active owner/host gate and returns synthetic SQLite memory');
    assert54($memories[0]['body']==='Synthetic cobalt horizon memory on operator test server.',
        'MCP response reads original synthetic private owner record unchanged');
    assert54(array_keys($memories[0])===['id','revision','body','source_kind'],
        'projection excludes host configuration and private authentication metadata');

    // DEV-62: the REAL OAuth -> MCP -> private SQLite path is also exercised
    // with Christoph's EXPLICIT shared-host policy, not fake UID isolation.
    $shared=$runtime;
    $shared['host_isolation_verified']=false;
    $shared['operator_accepts_shared_host_risk']=true;
    $shared['hosting_policy_mode']='shared-host-explicit-operator-acceptance/v1';
    $shared['hosting_policy_source']='protected-operator-host-config';
    $shared['hosting_policy_owner']='mirage-owner';
    $shared['hosting_policy_known_limitation']='shared-php-uid-not-verified';
    $shared['hosting_policy_acknowledged_at_utc']='2026-09-21T13:00:00Z';
    $sharedResponse=$send($auth,$search,$shared);
    $sharedJson=json_decode($sharedResponse['body'],true,16,JSON_THROW_ON_ERROR);
    $sharedMemories=json_decode($sharedJson['result']['content'][0]['text'],true,16,JSON_THROW_ON_ERROR);
    assert54($sharedResponse['http_status']===200 &&count($sharedMemories)===1
        &&$sharedMemories[0]['body']==='Synthetic cobalt horizon memory on operator test server.',
        'explicit accepted shared host completes genuine OAuth to private SQLite read');
    assert54($shared['host_isolation_verified']===false,
        'successful synthetic shared-host read never fabricates PHP UID isolation');
    $noAcceptance=$shared;
    $noAcceptance['operator_accepts_shared_host_risk']=false;
    assert54($send($auth,$search,$noAcceptance)['http_status']===404,
        'client bearer cannot replace missing original operator shared-host acceptance');
    $noReview=$shared;
    $noReview['hosting_policy_source']='client-request';
    assert54($send($auth,$search,$noReview)['http_status']===404,
        'HTTP request cannot self-assert protected server-side hosting policy');
    $sharedDisabled=$shared;
    $sharedDisabled['oauth_enabled']=false;
    assert54($send($auth,$search,$sharedDisabled)['http_status']===404,
        'operator shared-host risk acceptance never activates disabled OAuth');
    $sharedUnapproved=$shared;
    $sharedUnapproved['operator_approved']=false;
    assert54($send($auth,$search,$sharedUnapproved)['http_status']===404,
        'shared-host consent cannot bypass separate private-memory approval');

    $noToken=$send($server,$search,$runtime);
    assert54($noToken['http_status']===401&&
        str_contains($noToken['headers']['WWW-Authenticate']??'','resource_metadata='),
        'OAuth active host issues protected-resource 401 challenge on missing token');
    $wrong=$auth;$wrong['HTTP_AUTHORIZATION']='Bearer '.str_repeat('z',43);
    assert54($send($wrong,$search,$runtime)['http_status']===401,
        'unknown OAuth token cannot access private memory');
    $wrong=$auth;$wrong['REQUEST_METHOD']='GET';
    assert54($send($wrong,$search,$runtime)['http_status']===404,
        'GET cannot access MCP private store even with valid token');
    $wrong=$auth;$wrong['HTTP_ORIGIN']='https://other.example';
    assert54($send($wrong,$search,$runtime)['http_status']===404,
        'foreign browser origin rejected before private OAuth lookup');
    $wrong=$auth;$wrong['HTTP_ACCEPT']='application/json';
    assert54($send($wrong,$search,$runtime)['http_status']===404,
        'missing event-stream capability rejected');
    $off=$runtime;$off['oauth_enabled']=false;
    assert54($send($auth,$search,$off)['http_status']===404,
        'previously issued valid token cannot enable disabled host');
    $off=$runtime;$off['host_isolation_verified']=false;
    assert54($send($auth,$search,$off)['http_status']===404,
        'previously issued valid token cannot substitute for real host review');
    $off=$runtime;$off['owner_binding']=hash('sha256','other-owner');
    assert54($send($auth,$search,$off)['http_status']===404,
        'owner-binding mismatch blocked by runtime gate');
    $off=$runtime;$off['host_evidence_id']=hash('sha256','other-host');
    assert54($send($auth,$search,$off)['http_status']===404,
        'host-evidence mismatch blocked by runtime gate');

    $revokedOwner=$owner;$revokedOwner['enabled']=false;
    file_put_contents($registryPath,json_encode([
        'schema'=>1,'owners'=>[$fp=>$revokedOwner]],JSON_THROW_ON_ERROR));
    chmod($registryPath,0600);
    assert54($send($auth,$search,$runtime)['http_status']===404,
        'fresh owner-registry lookup denies revoked passkey without token renewal');
    file_put_contents($registryPath,json_encode([
        'schema'=>1,'owners'=>[$fp=>$owner]],JSON_THROW_ON_ERROR));
    chmod($registryPath,0600);
    $append=$rpc(5,'tools/call',['name'=>'engram_remember',
        'arguments'=>['query'=>'unapproved persistent memory']]);
    $blocked=$send($auth,$append,$runtime);
    $blockedJson=json_decode($blocked['body'],true,16,JSON_THROW_ON_ERROR);
    assert54($blockedJson['error']['code']===-32602,
        'OAuth read grant cannot call memory write tool');
    assert54(count($store->search('mirage-owner','project','unapproved persistent memory'))===0,
        'blocked MCP OAuth write never mutates private SQLite');
    KiComEngramOAuthTransactions::revoke($oauth,$token['access_token']);
    assert54($send($auth,$search,$runtime)['http_status']===401,
        'revoked OAuth token immediately loses access to installed-style MCP transport');
    assert54(($store->health()['quick_check']??null)==='ok',
        'private synthetic SQLite remains healthy after grant and revocation tests');
    echo "KICOM_ENGRAM_OAUTH_MCP_HOST_BRIDGE_TESTS_PASSED=$count\n";
}finally{
    unset($activation,$oauth,$store);
    purge54($root);
}
