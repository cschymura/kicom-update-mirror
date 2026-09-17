<?php
declare(strict_types=1);
require_once __DIR__.'/CellEvolutionReadiness.php';
$root=sys_get_temp_dir().'/kicom-evo-readiness-'.bin2hex(random_bytes(4));
$living=$root.'/storage/living';
foreach(['perception','action','evolution'] as $d)mkdir($living.'/'.$d,0700,true);
file_put_contents($living.'/perception/current.json',json_encode(['overall_state'=>'AVAILABLE']));
file_put_contents($living.'/action/model.json',json_encode(['actions'=>[['id'=>'x']]]));
file_put_contents($living.'/action/boundaries.json',json_encode(['boundaries'=>[['id'=>'protected-external']]]));
file_put_contents($living.'/evolution/policy.json',json_encode(['promotion_policy'=>'deferred-until-perception-action-memory-is-implemented-and-verified']));
$rows=[];for($i=0;$i<3;$i++)$rows[]=json_encode(['snapshot'=>['overall_state'=>'AVAILABLE']]);
file_put_contents($living.'/perception/history.jsonl',implode("\n",$rows)."\n");
file_put_contents($living.'/action/history.jsonl',json_encode(['action'=>'perception.refresh','result'=>'success'])."\n".json_encode(['action'=>'federation.tick.receive','result'=>'success'])."\n");
$r=(new KiComExpansionCellEvolutionReadiness($root.'/storage'))->evaluate();
if(empty($r['evidence_ready'])||!empty($r['promotion_performed'])||!empty($r['authority_changed'])){fwrite(STDERR,"readiness positive-path failed\n");exit(1);}
file_put_contents($living.'/action/history.jsonl',json_encode(['action'=>'perception.refresh','result'=>'failed'])."\n",FILE_APPEND);
$r2=(new KiComExpansionCellEvolutionReadiness($root.'/storage'))->evaluate();
if(!empty($r2['evidence_ready'])||!in_array('recent-action-failure-or-degradation-present',$r2['reasons'],true)){fwrite(STDERR,"readiness fail-closed path failed\n");exit(1);}
echo "CELL_EVOLUTION_READINESS_SELFTEST_OK\n";
