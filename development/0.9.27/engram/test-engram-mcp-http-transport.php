<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramMcpHttpTransport.php';
$n=0;
function t47(bool $v,string $label):void{
    global $n;if(!$v)throw new RuntimeException('FAIL '.$label);
    ++$n;echo "PASS $label\n";
}
function clean47(string $p):void{
    if(is_link($p)||is_file($p)){@unlink($p);return;}
    if(!is_dir($p))return;
    foreach(scandir($p) as $f)if($f!=='.'&&$f!=='..')clean47($p.'/'.$f);
    @rmdir($p);
}
$base=sys_get_temp_dir().'/mirage-mcp-http-'.bin2hex(random_bytes(7));
$web=$base.'/web';$private=$base.'/engram-private';$tokenDir=$private.'/mcp';
mkdir($base,0700);mkdir($web,0755);mkdir($private,0700);mkdir($tokenDir,0700);
try{
    $secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    $now=1800000500;
    $fp=hash('sha256','synthetic-owner-credential');
    $binding=hash('sha256',"mirage-owner\0".$fp);
    $host=hash('sha256','synthetic-host-evidence');
    $record=[
        'schema'=>1,'enabled'=>true,'token_sha256'=>hash('sha256',$secret),
        'connector_id'=>'mirage-http-dev','credential_fingerprint'=>$fp,
        'owner_binding'=>$binding,'host_evidence_id'=>$host,
        'issued_at'=>$now-30,'expires_at'=>$now+1800,
    ];
    $tokenFile=$tokenDir.'/mirage-mcp-token.json';
    $put=static function(array $data)use($tokenFile):void{
        file_put_contents($tokenFile,json_encode($data,JSON_THROW_ON_ERROR)."\n");
        chmod($tokenFile,0600);clearstatcache(true,$tokenFile);
    };
    $put($record);
    $policy=[
        'enabled'=>true,'operator_approved'=>true,'host_isolation_verified'=>true,
        'review_enabled'=>true,'mcp_connector_enabled'=>true,
        'runtime_source'=>'server-only-reviewed',
        'private_memory_scope'=>'dev-verified-owner',
        'admin_subject'=>'mirage-owner','expected_origin'=>'https://kicom.rurtalbahn.info',
        'rp_id'=>'kicom.rurtalbahn.info','mcp_connector_id'=>'mirage-http-dev',
        'owner_binding'=>$binding,'host_evidence_id'=>$host,
    ];
    $owner=[
        'enabled'=>true,'credential_fingerprint'=>$fp,'subject'=>'mirage-owner',
        'namespaces'=>['project'],'engram_rights'=>['engram.read'],
    ];
    $lookup=static fn(string $x):?array=>$x===$fp?$owner:null;
    $activation=new PDO('sqlite::memory:');
    $activation->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $activation->exec('CREATE TABLE activation_state(singleton INTEGER PRIMARY KEY,state TEXT,owner_binding TEXT,host_evidence_id TEXT)');
    $insert=$activation->prepare('INSERT INTO activation_state VALUES(1,?,?,?)');
    $insert->execute(['active',$binding,$host]);
    $store=new KiComEngramStore($private,$web);
    $created=$store->create('mirage-owner','project','technical','Synthetic purple ferry memory.','synthetic_test','mcp://http-dev');
    t47(($created['revision']??null)===1,'synthetic private memory fixture inserted');
    $nonceDb=new PDO('sqlite::memory:');
    $calls=0;
    $factory=static function(array $id)use($store,$nonceDb,&$calls):KiComEngramMcpJsonAdapter{
        ++$calls;
        return new KiComEngramMcpJsonAdapter(
            new KiComEngramMcpController($store,new KiComEngramMcpContract($nonceDb,'mirage-owner'),'mirage-owner','active')
        );
    };
    $headers=[
        'HTTPS'=>'on','REQUEST_METHOD'=>'POST','HTTP_HOST'=>'kicom.rurtalbahn.info',
        'CONTENT_TYPE'=>'application/json',
        'HTTP_ACCEPT'=>'application/json, text/event-stream',
        'HTTP_MCP_PROTOCOL_VERSION'=>'2025-06-18',
        'HTTP_AUTHORIZATION'=>'Bearer '.$secret,
    ];
    $rpc=static fn(int $id,string $method,array $params=[])=>json_encode(
      ['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$params],JSON_THROW_ON_ERROR);
    $send=static fn(array $s,string $raw,array $p) =>
      KiComEngramMcpHttpTransport::handle($s,$raw,$web,$tokenFile,$p,$activation,$lookup,$now,$factory);
    $identity=KiComEngramMcpBearerVerifier::verify($tokenFile,$web,'Bearer '.$secret,$now);
    t47(($identity['authenticated']??null)===true && $identity['owner_binding']===$binding,
        'private token hash resolves connector identity, not client JSON');
    t47(!array_key_exists('token_sha256',$identity),'plaintext and stored token hash excluded from identity');
    $hello=$send($headers,$rpc(1,'initialize',[
       'protocolVersion'=>'2025-06-18','capabilities'=>[],
       'clientInfo'=>['name'=>'http-harness','version'=>'1.0.0']
    ]),$policy);
    $parsed=json_decode($hello['body'],true,16,JSON_THROW_ON_ERROR);
    t47($hello['http_status']===200&&$parsed['result']['protocolVersion']==='2025-06-18',
        'HTTPS POST bearer initializes actual MCP JSON-RPC protocol');
    t47(($hello['headers']['Cache-Control']??null)==='no-store, private' &&
        ($hello['headers']['Content-Type']??null)==='application/json; charset=utf-8',
        'HTTP response has correct protocol MIME and no-store headers');
    $list=json_decode($send($headers,$rpc(2,'tools/list'),$policy)['body'],true,16,JSON_THROW_ON_ERROR);
    t47(($list['result']['tools'][0]['name']??null)==='engram_search',
        'authenticated tools/list exposes only read tool');
    $read=$send($headers,$rpc(3,'tools/call',[
      'name'=>'engram_search','arguments'=>['query'=>'purple ferry','limit'=>2]
    ]),$policy);
    $result=json_decode($read['body'],true,16,JSON_THROW_ON_ERROR);
    $items=json_decode($result['result']['content'][0]['text']??'[]',true);
    t47(($result['result']['isError']??null)===false&&count($items)===1,
        'real HTTP semantics to private synthetic SQLite read roundtrip');
    t47(($items[0]['body']??null)==='Synthetic purple ferry memory.',
        'exact synthetic memory returned by private owner scope');
    t47($calls===1,'initialization and listing do not instantiate private store');
    $n0=$calls;
    $reject=static function(array $s,array $p,string $name)use($send,$rpc,&$calls,$n0):void{
      $res=$send($s,$rpc(4,'tools/list'),$p);
      t47($res['http_status']===404&&$res['body']===''&&$calls===$n0,$name);
    };
    foreach(['HTTPS'=>'','REQUEST_METHOD'=>'GET','HTTP_HOST'=>'other.example',
              'CONTENT_TYPE'=>'text/plain',
              'HTTP_ACCEPT'=>'application/json',
              'HTTP_MCP_PROTOCOL_VERSION'=>'2025-01-01',
              'HTTP_AUTHORIZATION'=>'Bearer wrong'] as $key=>$val){
        $s=$headers;$s[$key]=$val;
        $reject($s,$policy,'bad '.$key.' denied before protocol or store');
    }
    $s=$headers;$s['HTTP_ORIGIN']='https://attacker.example';
    $reject($s,$policy,'untrusted browser origin denied');
    $s=$headers;$s['HTTP_ORIGIN']='https://kicom.rurtalbahn.info';
    t47($send($s,$rpc(5,'ping'),$policy)['http_status']===200,
        'matching optional first-party Origin accepted');
    $policyInactive=$policy;$policyInactive['enabled']=false;
    $reject($headers,$policyInactive,'inactive host blocks bearer-valid MCP access');
    $policyInactive=$policy; $policyInactive['mcp_connector_enabled']=false;
    $reject($headers,$policyInactive,'connector-disabled host blocks bearer-valid MCP access');

    $r=$record;$r['enabled']=false;$put($r);
    $reject($headers,$policy,'revoked token denied before runtime');
    $put($record);
    $r=$record;$r['expires_at']=$now;$put($r);
    $reject($headers,$policy,'expired token denied before runtime');
    $put($record);
    $r=$record;$r['token_sha256']=hash('sha256','another-token');$put($r);
    $reject($headers,$policy,'wrong token hash denied');
    $put($record);
    chmod($tokenFile,0644);
    $reject($headers,$policy,'world-readable token record denied');
    chmod($tokenFile,0600);
    $r=$record;$r['secret']='unexpected';$put($r);
    $reject($headers,$policy,'secret extra field in token record denied');
    $put($record);
    chmod($tokenDir,0755);
    $reject($headers,$policy,'unprotected token directory denied');
    chmod($tokenDir,0700);

    $request=$rpc(6,'tools/call',['name'=>'engram_remember','arguments'=>['query'=>'unsolicited']]);
    $denied=json_decode($send($headers,$request,$policy)['body'],true,16,JSON_THROW_ON_ERROR);
    t47(($denied['error']['code']??null)===-32602,'unsolicited write tool cannot be invoked via HTTPS transport');
    t47(count($store->search('mirage-owner','project','unsolicited'))===0,'rejected HTTP write does not mutate private memory');
    t47(($store->health()['quick_check']??null)==='ok','synthetic private SQLite remains healthy');
    echo "KICOM_ENGRAM_MCP_HTTP_TESTS_PASSED=$n\n";
}finally{unset($store,$activation,$nonceDb);clean47($base);}
