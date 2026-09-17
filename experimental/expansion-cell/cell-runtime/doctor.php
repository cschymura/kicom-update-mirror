<?php
declare(strict_types=1); require __DIR__.'/common.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET') kicomCellOut(['ok'=>false,'code'=>'CELL_GET_REQUIRED'],405);
$node=kicomCellNode();$status=$node->status();if(!is_array($status))kicomCellOut(['ok'=>false,'code'=>'CELL_NOT_INITIALIZED'],404);
$living=(new KiComExpansionCellLiving(__DIR__.'/var'))->doctor(false);
$pa=(new KiComExpansionCellPerceptionAction(__DIR__.'/var'))->status();
$public=[
    'living_ready'=>!empty($living['status']['living_ready']),
    'subsystems'=>$living['status']['subsystems']??[],
    'drift_count'=>(int)($living['status']['drift_count']??0),
    'lkg_ok'=>!empty($living['status']['lkg_ok']),
    'genome_id'=>(string)($living['status']['genome_id']??''),
    'scan_code'=>(string)($living['scan']['code']??''),
    'components'=>(int)($living['scan']['components']??0),
    'perception_action_ready'=>!empty($pa['ready']),
    'perception_state'=>(string)($pa['perception_state']??'UNKNOWN'),
    'neighbors'=>(int)($pa['neighbors']??0),
    'actions'=>(int)($pa['actions']??0),
    'boundaries'=>(int)($pa['boundaries']??0),
    'expansion_opportunities'=>(int)($pa['expansion_opportunities']??0),
];
$healthy=!empty($public['living_ready'])&&!empty($public['perception_action_ready']);
kicomCellOut(['ok'=>$healthy,'code'=>$healthy?'CELL_DOCTOR_HEALTHY':'CELL_DOCTOR_DEGRADED','doctor'=>$public],$healthy?200:503);
