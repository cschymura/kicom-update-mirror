<?php
declare(strict_types=1); require __DIR__.'/common.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET') kicomCellOut(['ok'=>false,'code'=>'CELL_GET_REQUIRED'],405);
$node=kicomCellNode();$status=$node->status();
if($status===null){
    $cfg=__DIR__.'/bootstrap.config.php'; if(!is_file($cfg)) kicomCellOut(['ok'=>false,'code'=>'CELL_BOOTSTRAP_CONFIG_MISSING'],503);
    $seed=require $cfg; if(!is_array($seed)) kicomCellOut(['ok'=>false,'code'=>'CELL_BOOTSTRAP_CONFIG_INVALID'],503);
    $init=$node->initialize($seed); if(empty($init['ok'])) kicomCellOut($init,422); @unlink($cfg);
}
$status=$node->status(); if(is_array($status)&&($status['state']??'')==='active') kicomCellOut(['ok'=>true,'code'=>'CELL_ALREADY_ACTIVE','cell_id'=>$status['cell_id']??null,'state'=>'active']);
$hello=$node->enrollmentHello(); if(empty($hello['ok'])) kicomCellOut($hello,422);
$q=(string)($_GET['expansion_id']??''); if($q===''||!hash_equals((string)$hello['expansion_id'],$q)) kicomCellOut(['ok'=>false,'code'=>'CELL_EXPANSION_MISMATCH'],404);
kicomCellOut($hello);
