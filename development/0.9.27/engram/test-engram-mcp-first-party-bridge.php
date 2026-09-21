<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramMcpFirstPartyBridge.php';
$n=0;
function ok48(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function wipe48(string $p):void{
 if(is_link($p)||is_file($p)){@unlink($p);return;}
 if(!is_dir($p))return;
 foreach(scandir($p) as $x)if($x!=='.'&&$x!=='..')wipe48($p.'/'.$x);
 @rmdir($p);
}
$base=sys_get_temp_dir().'/mirage-bridge-'.bin2hex(random_bytes(8));
$web=$base.'/web';$private=$base.'/engram-private';$data=$private.'/data';
$owners=$private.'/owners';$mcp=$private.'/mcp';
mkdir($base,0700);mkdir($web,0755);mkdir($private,0700);
foreach([$data,$owners,$mcp] as $dir)mkdir($dir,0700);
try{
 $now=1800000600;
 $fp=hash('sha256','synthetic-already-enrolled-passkey');
 $binding=hash('sha256',"mirage-owner\0".$fp);
 $hostId=hash('sha256','synthetic-reviewed-host');
 $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
 $owner=['enabled'=>true,'credential_fingerprint'=>$fp,'subject'=>'mirage-owner',
   'namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']];
 $registry=$owners.'/engram-owners.json';
 $writeOwner=static function(array $record)use($registry,$fp):void{
    file_put_contents($registry,json_encode(['schema'=>1,'owners'=>[$fp=>$record]],JSON_THROW_ON_ERROR)."\n");
    chmod($registry,0600);
 };
 $writeOwner($owner);
 $record=[
  'schema'=>1,'enabled'=>true,'token_sha256'=>hash('sha256',$token),
  'connector_id'=>'mcp-bridge-dev','credential_fingerprint'=>$fp,
  'owner_binding'=>$binding,'host_evidence_id'=>$hostId,
  'issued_at'=>$now-60,'expires_at'=>$now+1800,
 ];
 $tokenPath=$mcp.'/mirage-mcp-token.json';
 $writeToken=static function(array $rec)use($tokenPath):void{
    file_put_contents($tokenPath,json_encode($rec,JSON_THROW_ON_ERROR)."\n");
    chmod($tokenPath,0600);
 };
 $writeToken($record);
 $policy=[
  'enabled'=>true,'operator_approved'=>true,'host_isolation_verified'=>true,
  'review_enabled'=>true,'mcp_connector_enabled'=>true,
  'runtime_source'=>'server-only-reviewed','private_memory_scope'=>'dev-verified-owner',
  'admin_subject'=>'mirage-owner','expected_origin'=>'https://kicom.rurtalbahn.info',
  'rp_id'=>'kicom.rurtalbahn.info','mcp_connector_id'=>'mcp-bridge-dev',
  'owner_binding'=>$binding,'host_evidence_id'=>$hostId,
  'web_root'=>$web,'data_dir'=>$data,'owner_registry'=>$registry,
 ];
 $store=new KiComEngramStore($data,$web);
 $entry=$store->create('mirage-owner','project','technical','Synthetic first-party bridge remembers a crimson signal.','synthetic_test','mcp://bridge-dev');
 ok48(($entry['revision']??null)===1,'synthetic memory prepared in true KiCom private SQLite store');
 $activationPath=$data.'/mirage-activation.sqlite';
 $db=new PDO('sqlite:'.$activationPath);
 $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $db->exec('CREATE TABLE activation_state(singleton INTEGER PRIMARY KEY,state TEXT,owner_binding TEXT,host_evidence_id TEXT)');
 $stmt=$db->prepare('INSERT INTO activation_state VALUES(1,?,?,?)');
 $stmt->execute(['active',$binding,$hostId]);
 unset($db); chmod($activationPath,0600);
 $server=[
  'HTTPS'=>'on','REQUEST_METHOD'=>'POST','HTTP_HOST'=>'kicom.rurtalbahn.info',
  'CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json, text/event-stream',
  'HTTP_MCP_PROTOCOL_VERSION'=>'2025-06-18','HTTP_AUTHORIZATION'=>'Bearer '.$token,
 ];
 $rpc=static fn(int $id,string $method,array $params=[])=>json_encode(
  ['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$params],JSON_THROW_ON_ERROR);
 $send=static fn(array $p,array $s,string $wire):array=>KiComEngramMcpFirstPartyBridge::handle(
  $s,$wire,$p,$web,$now
 );
 $call=$rpc(1,'tools/call',['name'=>'engram_search','arguments'=>['query'=>'crimson signal','limit'=>2]]);
 $result=$send($policy,$server,$call);
 $answer=json_decode($result['body'],true,16,JSON_THROW_ON_ERROR);
 $items=json_decode($answer['result']['content'][0]['text']??'[]',true);
 ok48($result['http_status']===200&&count($items)===1,
    'first-party router reaches private owner-scoped SQLite over MCP');
 ok48($items[0]['body']==='Synthetic first-party bridge remembers a crimson signal.',
    'exact synthetic private record read back through first-party bridge');
 ok48(array_keys($items[0])===['id','revision','body','source_kind'],
    'response excludes other private metadata');
 $inactive=$policy;
 foreach(['enabled','operator_approved','host_isolation_verified','review_enabled','mcp_connector_enabled'] as $flag)$inactive[$flag]=false;
 $inactive['runtime_source']='setup-pending';$inactive['private_memory_scope']='setup-pending';
 unset($inactive['mcp_connector_id'],$inactive['owner_binding'],$inactive['host_evidence_id']);
 ok48($send($inactive,$server,$call)['http_status']===404,
    'real KiCom 0.9.29/0.9.30-style inactive scaffold refuses MCP access');
 $baseline=scandir($data);
 foreach(['enabled','operator_approved','host_isolation_verified','review_enabled','mcp_connector_enabled'] as $flag){
    $p=$policy;$p[$flag]=false;
    ok48($send($p,$server,$call)['http_status']===404,
       'first-party bridge fails closed without '.$flag);
 }
 ok48($baseline===scandir($data),'inactive calls do not create databases or nonce files');
 $p=$policy;$p['web_root']=$base.'/other';
 ok48($send($p,$server,$call)['http_status']===404,'client or foreign web root not trusted');
 $p=$policy;$p['data_dir']=$base.'/other';
 ok48($send($p,$server,$call)['http_status']===404,'foreign data dir not trusted');
 $p=$policy;$p['owner_registry']=$base.'/other';
 ok48($send($p,$server,$call)['http_status']===404,'foreign owner registry not trusted');
 $registryDenied=$owner;$registryDenied['enabled']=false;$writeOwner($registryDenied);
 ok48($send($policy,$server,$call)['http_status']===404,
   'revoked first-party passkey registry owner fails closed');
 $writeOwner($owner);
 $registryDenied=$owner;$registryDenied['subject']='foreign-owner';$writeOwner($registryDenied);
 ok48($send($policy,$server,$call)['http_status']===404,'foreign registry owner denied');
 $writeOwner($owner);
 chmod($registry,0644);
 ok48($send($policy,$server,$call)['http_status']===404,
   'world-readable private owner registry denied');
 chmod($registry,0600);
 chmod($activationPath,0644);
 ok48($send($policy,$server,$call)['http_status']===404,
   'world-readable activation receipt denied before PDO');
 chmod($activationPath,0600);
 rename($activationPath,$activationPath.'.off');
 ok48($send($policy,$server,$call)['http_status']===404,
   'missing activation receipt never created by HTTP request');
 ok48(!file_exists($activationPath),'missing activation database stays missing after request');
 rename($activationPath.'.off',$activationPath);
 chmod($private,0755);
 ok48($send($policy,$server,$call)['http_status']===404,'unprotected private root denied');
 chmod($private,0700);
 chmod($mcp,0755);
 ok48($send($policy,$server,$call)['http_status']===404,'unprotected MCP token directory denied');
 chmod($mcp,0700);
 $s=$server;$s['HTTP_AUTHORIZATION']='Bearer invalid';
 ok48($send($policy,$s,$call)['http_status']===404,'invalid connector bearer denied');
 $written=$rpc(2,'tools/call',['name'=>'engram_remember','arguments'=>['query'=>'unapproved']]);
 $response=$send($policy,$server,$written);
 $decoded=json_decode($response['body'],true,16,JSON_THROW_ON_ERROR);
 ok48(($decoded['error']['code']??null)===-32602,'MCP writing remains unavailable without signed per-record consent');
 ok48(count($store->search('mirage-owner','project','unapproved'))===0,'unapproved write leaves data unchanged');
 ok48(($store->health()['quick_check']??null)==='ok','private database remains healthy after all rejections');
 echo "KICOM_ENGRAM_FIRST_PARTY_MCP_BRIDGE_TESTS_PASSED=$n\n";
}finally{unset($store);wipe48($base);}
