<?php
declare(strict_types=1); require __DIR__.'/common.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET') kicomCellOut(['ok'=>false,'code'=>'CELL_GET_REQUIRED'],405);
$s=kicomCellNode()->status();if(!is_array($s))kicomCellOut(['ok'=>false,'code'=>'CELL_NOT_INITIALIZED'],404);
$public=[];foreach(['schema','state','cell_id','root_id','parent_id','generation','public_key','base_url','capabilities','created_at','activated_at'] as $k)if(array_key_exists($k,$s))$public[$k]=$s[$k];
kicomCellOut(['ok'=>true,'code'=>'CELL_STATUS','cell'=>$public]);
