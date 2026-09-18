<?php
declare(strict_types=1);
require_once __DIR__.'/cell-evolution-readiness-status.php';

$root=sys_get_temp_dir().'/kicom-readiness-status-'.bin2hex(random_bytes(5));
$living=$root.'/living';
foreach(['perception','action','evolution'] as $d) mkdir($living.'/'.$d,0700,true);
$write=function(string $rel,array $v)use($living):void{file_put_contents($living.'/'.$rel,json_encode($v,JSON_UNESCAPED_SLASHES));};
$append=function(string $rel,array $v)use($living):void{file_put_contents($living.'/'.$rel,json_encode($v,JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND);};
$write('perception/current.json',['overall_state'=>'AVAILABLE']);
$write('action/model.json',['actions'=>[['id'=>'perception.refresh']]]);
$write('action/boundaries.json',['boundaries'=>[['id'=>'external-protected']]]);
$write('evolution/policy.json',['promotion_policy'=>'deferred-until-perception-action-memory-is-implemented-and-verified']);
for($i=0;$i<3;$i++)$append('perception/history.jsonl',['snapshot'=>['overall_state'=>'AVAILABLE']]);
$append('action/history.jsonl',['action'=>'perception.refresh','result'=>'success']);
$append('action/history.jsonl',['action'=>'federation.tick.receive','result'=>'success']);
$r=kicomCellEvolutionReadinessStatus($root);
if(empty($r['evidence_ready'])||!empty($r['promotion_performed'])||!empty($r['authority_changed'])){fwrite(STDERR,"positive diagnostic failed\n");exit(1);}
$append('action/history.jsonl',['action'=>'perception.refresh','result'=>'failed']);
$r2=kicomCellEvolutionReadinessStatus($root);
if(!empty($r2['evidence_ready'])||!in_array('recent-action-failure-or-degradation-present',$r2['reasons'],true)){fwrite(STDERR,"fail-closed diagnostic failed\n");exit(1);}
$rm=function($p)use(&$rm):void{if(is_dir($p)){foreach(scandir($p)?:[] as $x)if($x!=='.'&&$x!=='..')$rm($p.'/'.$x);rmdir($p);}elseif(is_file($p))unlink($p);};$rm($root);
echo "CELL_EVOLUTION_READINESS_STATUS_SELFTEST_OK\n";
