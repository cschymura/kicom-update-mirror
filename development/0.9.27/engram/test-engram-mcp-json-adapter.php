<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramMcpJsonAdapter.php';
$n=0;
function oka(bool $x,string $m):void{global $n;if(!$x)throw new RuntimeException('FAIL '.$m);$n++;echo "PASS $m\n";}
function decodea(string $s):array{$v=json_decode($s,true,16,JSON_THROW_ON_ERROR);if(!is_array($v))throw new RuntimeException('bad json');return $v;}
function rmra(string $p):void{if(is_link($p)||is_file($p)){@unlink($p);return;}if(!is_dir($p))return;foreach(scandir($p) as $x)if($x!=='.'&&$x!=='..')rmra($p.'/'.$x);@rmdir($p);}
$root=sys_get_temp_dir().'/kicom-mcp-json-'.bin2hex(random_bytes(6));$web=$root.'/web';$private=$root.'/private';mkdir($root,0700);mkdir($web,0755);mkdir($private,0700);
try{
 $store=new KiComEngramStore($private,$web);$nonceDb=new PDO('sqlite::memory:');$contract=new KiComEngramMcpContract($nonceDb,'mirage-owner');$controller=new KiComEngramMcpController($store,$contract,'mirage-owner','active');$adapter=new KiComEngramMcpJsonAdapter($controller,2048,8192);$now=1800000200;$id=['authenticated'=>true,'owner'=>'mirage-owner','connector_id'=>'chatgpt-mcp-dev'];
 $base=['v'=>1,'op'=>'append','owner'=>'mirage-owner','namespace'=>'collaboration','nonce'=>'json-001','issued_at'=>$now,'limit'=>3,'query'=>'synthetic adapter memory'];
 $a=decodea($adapter->handle(json_encode($base),$id,$now));oka($a['ok']===true&&$a['result']['status']==='SYNTHETIC_APPEND_OK','connector JSON append reaches controller and store');
 $read=$base;$read['op']='read';$read['nonce']='json-002';$read['query']='adapter';$r=decodea($adapter->handle(json_encode($read),$id,$now));oka($r['ok']===true&&$r['result']['count']===1,'connector JSON read completes full synthetic roundtrip');oka($r['result']['items'][0]['body']==='synthetic adapter memory','roundtrip body matches synthetic fixture');
 $bad=decodea($adapter->handle('{bad',$id,$now));oka($bad===['ok'=>false,'error'=>'REQUEST_JSON'],'malformed JSON returns minimized error');
 $list=decodea($adapter->handle('[1,2]',$id,$now));oka($list===['ok'=>false,'error'=>'REQUEST_JSON'],'top-level JSON list denied');
 $oversize=decodea($adapter->handle(str_repeat('x',2049),$id,$now));oka($oversize===['ok'=>false,'error'=>'REQUEST_SIZE'],'oversize request denied before parsing');
 $unknown=$read;$unknown['nonce']='json-003';$unknown['extra']='x';$u=decodea($adapter->handle(json_encode($unknown),$id,$now));oka($u===['ok'=>false,'error'=>'REQUEST_DENIED'],'unknown request field denied without internal detail');
 $spoof=$read;$spoof['nonce']='json-004';$spoof['owner']='foreign-owner';$s=decodea($adapter->handle(json_encode($spoof),$id,$now));oka($s===['ok'=>false,'error'=>'REQUEST_DENIED'],'JSON owner spoof cannot replace server identity');
 $foreign=$id;$foreign['owner']='foreign-owner';$x=$read;$x['nonce']='json-005';$f=decodea($adapter->handle(json_encode($x),$foreign,$now));oka($f===['ok'=>false,'error'=>'REQUEST_DENIED'],'foreign verified identity denied with minimized error');
 $x=$read;$x['nonce']='json-006';$x['limit']=11;$l=decodea($adapter->handle(json_encode($x),$id,$now));oka($l===['ok'=>false,'error'=>'REQUEST_DENIED'],'disclosure limit violation minimized');
 $inactiveController=new KiComEngramMcpController($store,new KiComEngramMcpContract(new PDO('sqlite::memory:'),'mirage-owner'),'mirage-owner','inactive');$inactive=new KiComEngramMcpJsonAdapter($inactiveController);$x=$read;$x['nonce']='json-007';$i=decodea($inactive->handle(json_encode($x),$id,$now));oka($i===['ok'=>false,'error'=>'REQUEST_DENIED'],'inactive private API remains fail closed');
 $tiny=new KiComEngramMcpJsonAdapter($controller,2048,512);$x=$read;$x['nonce']='json-008';$x['query']='adapter';$tr=decodea($tiny->handle(json_encode($x),$id,$now));oka(isset($tr['ok'])&&is_bool($tr['ok']),'response cap path always emits bounded JSON envelope');oka(strlen(json_encode($tr))<=512,'response envelope remains within configured cap');
 oka(count($store->search('foreign-owner','collaboration','adapter'))===0,'adapter never creates foreign-owner memory');oka(($store->health()['quick_check']??null)==='ok','private SQLite healthy after adapter negatives');
 echo "KICOM_ENGRAM_MCP_JSON_ADAPTER_TESTS_PASSED=$n\n";
}finally{unset($store);rmra($root);}
