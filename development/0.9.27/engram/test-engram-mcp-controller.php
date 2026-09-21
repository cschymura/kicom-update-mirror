<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramMcpController.php';
$n=0;
function okc(bool $x,string $m):void{global $n;if(!$x)throw new RuntimeException('FAIL '.$m);$n++;echo "PASS $m\n";}
function denyc(callable $f,string $want,string $m):void{try{$f();}catch(RuntimeException $e){okc($e->getMessage()===$want,$m);return;}throw new RuntimeException('FAIL '.$m);}
function rmrf(string $p):void{if(is_link($p)||is_file($p)){@unlink($p);return;}if(!is_dir($p))return;foreach(scandir($p) as $x)if($x!=='.'&&$x!=='..')rmrf($p.'/'.$x);@rmdir($p);}
$root=sys_get_temp_dir().'/kicom-mcp-controller-'.bin2hex(random_bytes(6));
$web=$root.'/web';$private=$root.'/private';mkdir($root,0700);mkdir($web,0755);mkdir($private,0700);
try{
  $store=new KiComEngramStore($private,$web);
  $nonceDb=new PDO('sqlite::memory:');
  $contract=new KiComEngramMcpContract($nonceDb,'mirage-owner');
  $now=1800000100;
  $id=['authenticated'=>true,'owner'=>'mirage-owner','connector_id'=>'chatgpt-mcp-dev'];
  $base=['v'=>1,'op'=>'read','owner'=>'mirage-owner','namespace'=>'collaboration','nonce'=>'ctrl-001','issued_at'=>$now,'limit'=>3,'query'=>'synthetic'];
  $inactive=new KiComEngramMcpController($store,$contract,'mirage-owner','inactive');
  denyc(fn()=>$inactive->dispatch($base,$id,$now),'PRIVATE_API_INACTIVE','controller remains fail-closed while inactive');
  okc(count($store->search('mirage-owner','collaboration','synthetic'))===0,'inactive request cannot touch store');
  $active=new KiComEngramMcpController($store,$contract,'mirage-owner','active');
  $append=$base;$append['op']='append';$append['nonce']='ctrl-002';$append['query']='synthetic controller memory';
  $a=$active->dispatch($append,$id,$now);okc($a['status']==='SYNTHETIC_APPEND_OK'&&$a['revision']===1,'authenticated synthetic append reaches store');
  $read=$base;$read['nonce']='ctrl-003';$read['query']='controller';
  $r=$active->dispatch($read,$id,$now);okc($r['status']==='SYNTHETIC_READ_OK'&&$r['count']===1,'authenticated synthetic read reaches store');
  okc(array_keys($r['items'][0])===['id','revision','body','source_kind'],'read response is minimum disclosure projection');
  okc($r['items'][0]['body']==='synthetic controller memory','synthetic roundtrip body matches');
  denyc(fn()=>$active->dispatch($read,$id,$now),'REPLAY_DENIED','controller preserves nonce replay denial');
  $foreign=$id;$foreign['owner']='foreign-owner';$x=$read;$x['nonce']='ctrl-004';
  denyc(fn()=>$active->dispatch($x,$foreign,$now),'IDENTITY_DENIED','controller preserves identity isolation');
  okc(count($store->search('foreign-owner','collaboration','controller'))===0,'foreign subject cannot observe owner store');
  $x=$read;$x['nonce']='ctrl-005';$x['limit']=11;denyc(fn()=>$active->dispatch($x,$id,$now),'LIMIT_DENIED','controller preserves disclosure bound');
  $x=$append;$x['nonce']='ctrl-006';$x['query']='';denyc(fn()=>$active->dispatch($x,$id,$now),'APPEND_BODY_REQUIRED','empty append rejected before store mutation');
  okc(count($store->search('mirage-owner','collaboration','controller'))===1,'failed append leaves existing store unchanged');
  okc(($store->health()['quick_check']??null)==='ok','private SQLite remains healthy');
  echo "KICOM_ENGRAM_MCP_CONTROLLER_TESTS_PASSED=$n\n";
}finally{unset($store);rmrf($root);}
