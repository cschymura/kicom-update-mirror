<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramMcpProtocol.php';
$checks=0;
function p46(bool $yes,string $why):void{global $checks;if(!$yes)throw new RuntimeException('FAIL '.$why);++$checks;echo "PASS $why\n";}
function clean46(string $p):void{
 if(is_link($p)||is_file($p)){@unlink($p);return;}
 if(!is_dir($p))return;
 foreach(scandir($p) as $f)if($f!=='.'&&$f!=='..')clean46($p.'/'.$f);
 @rmdir($p);
}
$root=sys_get_temp_dir().'/mirage-mcp-protocol-'.bin2hex(random_bytes(6));
mkdir($root,0700);mkdir($root.'/web',0755);mkdir($root.'/private',0700);
try{
 $store=new KiComEngramStore($root.'/private',$root.'/web');
 $created=$store->create('mirage-owner','project','technical',
  'Synthetic test: the signal is violet.','synthetic_test','mcp://dev46');
 p46(($created['revision']??null)===1,'synthetic record inserted in private store');
 $fp=hash('sha256','dev46-dummy-fingerprint');
 $binding=hash('sha256',"mirage-owner\0".$fp);
 $host=hash('sha256','dev46-host');
 $runtime=['enabled'=>true,'operator_approved'=>true,'host_isolation_verified'=>true,
  'review_enabled'=>true,'mcp_connector_enabled'=>true,
  'runtime_source'=>'server-only-reviewed','private_memory_scope'=>'dev-verified-owner',
  'admin_subject'=>'mirage-owner','expected_origin'=>'https://kicom.rurtalbahn.info',
  'rp_id'=>'kicom.rurtalbahn.info','mcp_connector_id'=>'mirage-connector-dev',
  'owner_binding'=>$binding,'host_evidence_id'=>$host];
 $connector=['authenticated'=>true,'connector_id'=>'mirage-connector-dev',
  'credential_fingerprint'=>$fp,'owner_binding'=>$binding,'host_evidence_id'=>$host];
 $owner=['enabled'=>true,'credential_fingerprint'=>$fp,'subject'=>'mirage-owner',
  'namespaces'=>['project'],'engram_rights'=>['engram.read']];
 $lookup=static fn(string $x):?array=>$x===$fp?$owner:null;
 $db=new PDO('sqlite::memory:');
 $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $db->exec('CREATE TABLE activation_state(singleton INTEGER PRIMARY KEY,state TEXT,owner_binding TEXT,host_evidence_id TEXT)');
 $stmt=$db->prepare('INSERT INTO activation_state VALUES(1,?,?,?)');
 $stmt->execute(['active',$binding,$host]);
 $nonce=new PDO('sqlite::memory:');
 $factoryCalls=0;
 $factory=static function(array $identity) use(&$factoryCalls,$store,$nonce):KiComEngramMcpJsonAdapter {
   ++$factoryCalls;
   return new KiComEngramMcpJsonAdapter(new KiComEngramMcpController(
     $store,new KiComEngramMcpContract($nonce,'mirage-owner'),'mirage-owner','active'
   ));
 };
 $now=1800000400;
 $send=static fn(string $json,?array $policy=null,?array $identity=null,?callable $registry=null)
    =>KiComEngramMcpProtocol::handle($json,$policy??$runtime,$db,$registry??$lookup,$identity??$connector,$now,$factory);
 $req=static fn(int $id,string $method,array $params):string=>json_encode(
   ['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$params],JSON_THROW_ON_ERROR);
 $unpack=static fn(array $res):array=>json_decode($res['body'],true,16,JSON_THROW_ON_ERROR);

 $hello=$unpack($send($req(1,'initialize',['protocolVersion'=>'2025-06-18',
   'capabilities'=>[],'clientInfo'=>['name'=>'synthetic-client','version'=>'1.0.0']])));
 p46($hello['result']['protocolVersion']==='2025-06-18','MCP initialize negotiates supported protocol');
 p46($hello['result']['serverInfo']['name']==='mirage-engram','MCP server identity advertised');
 $list=$unpack($send($req(2,'tools/list',[])));
 p46(count($list['result']['tools'])===1&&$list['result']['tools'][0]['name']==='engram_search',
  'read-only search is the sole discoverable MCP tool');
 p46(($list['result']['tools'][0]['inputSchema']['additionalProperties']??null)===false,
  'tool schema disallows injected owner and session fields');
 p46($unpack($send($req(3,'ping',[])))['result']===[],'MCP ping returns JSON-RPC result');
 $notify=$send('{"jsonrpc":"2.0","method":"notifications/initialized"}');
 p46($notify['http_status']===202&&$notify['body']==='','MCP initialized notification has empty 202 response');
 p46($factoryCalls===0,'initialization and tool listing do not access memory store');

 $call=$req(4,'tools/call',['name'=>'engram_search',
     'arguments'=>['query'=>'violet','limit'=>2]]);
 $response=$unpack($send($call));
 p46(($response['result']['isError']??null)===false,'authenticated MCP tools/call succeeds');
 $items=json_decode($response['result']['content'][0]['text'],true);
 p46(count($items)===1&&($items[0]['body']??null)==='Synthetic test: the signal is violet.',
  'MCP JSON-RPC response contains only owner-scoped synthetic record');
 p46(array_keys($items[0])===['id','revision','body','source_kind'],
  'private record projection excludes secrets and host metadata');
 p46($factoryCalls===1,'store adapter created only for actual authorized read');

 $inactive=$runtime;$inactive['enabled']=false;
 $count=$factoryCalls;
 $blocked=$send($call,$inactive);
 p46($blocked['http_status']===404&&$blocked['body']===''&&$count===$factoryCalls,
  'inactive host does not disclose tools or open store');
 p46($send($req(5,'tools/list',[]),$inactive)['http_status']===404,
  'inactive host cannot enumerate MCP capabilities');
 $who=$connector;$who['authenticated']=false;
 p46($send($call,null,$who)['http_status']===404,'unauthenticated connector denied before JSON decode');
 $who=$connector;$who['connector_id']='foreign';
 p46($send($call,null,$who)['http_status']===404,'foreign connector denied before JSON decode');
 $revoked=$owner;$revoked['enabled']=false;
 p46($send($call,null,null,static fn(string $k):?array=>$revoked)['http_status']===404,
  'revoked owner cannot use MCP search');
 $noRead=$owner;$noRead['engram_rights']=['engram.write'];
 p46($send($call,null,null,static fn(string $k):?array=>$noRead)['http_status']===404,
  'owner lacking read right cannot search');

 $cases=[
  ['{bad',-32700,'malformed JSON parse error'],
  ['[]',-32600,'JSON array request'],
  [str_repeat('x',8193),-32600,'oversized JSON request'],
  [$req(6,'does/not/exist',[]),-32601,'unknown MCP method'],
  [$req(7,'tools/call',['name'=>'engram_remember','arguments'=>['query'=>'secret']]),-32602,'write tool unavailable'],
  [$req(8,'tools/call',['name'=>'engram_search','arguments'=>['query'=>'violet','limit'=>4]]),-32602,'request limit above maximum'],
  [$req(9,'tools/call',['name'=>'engram_search','arguments'=>['query'=>'violet','owner'=>'foreign']]),-32602,'caller-supplied owner rejected'],
  [$req(10,'tools/call',['name'=>'engram_search','arguments'=>['query'=>'']]),-32602,'empty search denied'],
  [$req(11,'tools/call',['name'=>'engram_search','arguments'=>['query'=>str_repeat('a',129)]]),-32602,'excessive query denied'],
  [$req(12,'tools/list',['extra'=>1]),-32602,'unexpected tools/list params denied'],
  ['{"jsonrpc":"2.0","id":null,"method":"ping"}',-32600,'null JSON-RPC id denied'],
  ['{"jsonrpc":"2.0","id":13,"method":"ping","owner":"foreign"}',-32600,'unexpected request-root keys denied'],
  ['{"jsonrpc":"2.0","method":"notifications/initialized","id":14}',-32601,'notification with id rejected as unknown method'],
  ['{"jsonrpc":"1.0","id":15,"method":"ping"}',-32600,'wrong JSON-RPC version denied'],
 ];
 foreach($cases as [$wire,$want,$label]){
    $value=$unpack($send($wire));
    p46(($value['error']['code']??null)===$want,$label);
 }
 p46($factoryCalls===1,'invalid MCP methods and params never create adapter');
 $second=$unpack($send($req(16,'tools/call',['name'=>'engram_search','arguments'=>['query'=>'violet']])));
 p46(($second['result']['isError']??null)===false,'second independent request generates a fresh internal nonce');
 p46(count($store->search('mirage-owner','project','secret'))===0,
  'no forbidden MCP write changed private store');
 p46(($store->health()['quick_check']??null)==='ok','synthetic private SQLite store healthy');
 echo "KICOM_ENGRAM_MCP_PROTOCOL_TESTS_PASSED=$checks\n";
}finally{unset($store,$db,$nonce);clean46($root);}
