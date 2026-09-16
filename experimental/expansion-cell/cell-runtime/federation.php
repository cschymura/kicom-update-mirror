<?php
declare(strict_types=1); require __DIR__.'/common.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') kicomCellOut(['ok'=>false,'code'=>'CELL_POST_REQUIRED'],405);
$env=kicomCellInput();$node=kicomCellNode();$status=$node->status();if(!is_array($status)) kicomCellOut(['ok'=>false,'code'=>'CELL_NOT_INITIALIZED'],503);
if(($status['state']??'')==='enrolling') { $r=$node->activate($env); kicomCellOut($r,empty($r['ok'])?422:200); }
if(($status['state']??'')!=='active') kicomCellOut(['ok'=>false,'code'=>'CELL_NOT_ACTIVE'],409);
if(!kicomCellRememberSignedMessage($env,$status)) kicomCellOut(['ok'=>false,'code'=>'FEDERATION_MESSAGE_REJECTED'],401);
$r=$node->handleParentMessage($env); kicomCellOut($r,empty($r['ok'])?422:200);
