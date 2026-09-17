<?php
declare(strict_types=1); require __DIR__.'/common.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET') kicomCellOut(['ok'=>false,'code'=>'CELL_GET_REQUIRED'],405);
$s=kicomCellNode()->status();if(!is_array($s))kicomCellOut(['ok'=>false,'code'=>'CELL_NOT_INITIALIZED'],404);
$public=[];foreach(['schema','state','cell_id','root_id','parent_id','generation','public_key','base_url','capabilities','created_at','activated_at','living_ready','perception_action_ready','world_model_ready'] as $k)if(array_key_exists($k,$s))$public[$k]=$s[$k];
if(isset($s['living'])&&is_array($s['living']))$public['living']=[
    'code'=>(string)($s['living']['code']??''),
    'living_ready'=>!empty($s['living']['living_ready']),
    'subsystems'=>$s['living']['subsystems']??[],
    'drift_count'=>(int)($s['living']['drift_count']??0),
    'lkg_ok'=>!empty($s['living']['lkg_ok']),
    'genome_id'=>(string)($s['living']['genome_id']??''),
];
if(isset($s['perception_action'])&&is_array($s['perception_action']))$public['perception_action']=[
    'code'=>(string)($s['perception_action']['code']??''),
    'ready'=>!empty($s['perception_action']['ready']),
    'perception_state'=>(string)($s['perception_action']['perception_state']??'UNKNOWN'),
    'observed_at'=>(string)($s['perception_action']['observed_at']??''),
    'neighbors'=>(int)($s['perception_action']['neighbors']??0),
    'actions'=>(int)($s['perception_action']['actions']??0),
    'boundaries'=>(int)($s['perception_action']['boundaries']??0),
    'expansion_opportunities'=>(int)($s['perception_action']['expansion_opportunities']??0),
    'states'=>$s['perception_action']['states']??[],
];
if(isset($s['world_model'])&&is_array($s['world_model']))$public['world_model']=[
    'code'=>(string)($s['world_model']['code']??''),
    'ready'=>!empty($s['world_model']['ready']),
    'knowledge_state'=>(string)($s['world_model']['knowledge_state']??'UNKNOWN'),
    'world_id'=>(string)($s['world_model']['world_id']??''),
    'observed_at'=>(string)($s['world_model']['observed_at']??''),
    'neighbors'=>(int)($s['world_model']['neighbors']??0),
    'known'=>(int)($s['world_model']['known']??0),
    'unknown'=>(int)($s['world_model']['unknown']??0),
    'forbidden'=>(int)($s['world_model']['forbidden']??0),
    'stale'=>(int)($s['world_model']['stale']??0),
];
kicomCellOut(['ok'=>true,'code'=>'CELL_STATUS','cell'=>$public]);