<?php
declare(strict_types=1);
require_once __DIR__.'/lib/ExpansionProtocol.php';
require_once __DIR__.'/lib/CellNode.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
function kicomCellOut(array $body,int $status=200): never { http_response_code($status); echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n"; exit; }
function kicomCellInput(): array { $raw=file_get_contents('php://input'); if(!is_string($raw)||strlen($raw)>131072) kicomCellOut(['ok'=>false,'code'=>'CELL_REQUEST_INVALID'],400); $x=json_decode($raw,true); if(!is_array($x)) kicomCellOut(['ok'=>false,'code'=>'CELL_REQUEST_JSON_INVALID'],400); return $x; }
function kicomCellNode(): KiComExpansionCellNode { return new KiComExpansionCellNode(__DIR__.'/var'); }
function kicomCellRememberSignedMessage(array $env,array $node): bool {
    $check=KiComExpansionProtocol::verifyEnvelope($env,(string)($node['parent_id']??''),(string)($node['cell_id']??''),(string)($node['parent_public_key']??''));
    if(empty($check['ok'])) return false;
    $id=(string)($env['message_id']??''); if(!preg_match('/^msg-[a-f0-9]{24}$/',$id)) return false;
    $path=__DIR__.'/var/http-seen.json';$rows=[];$raw=@file_get_contents($path);if(is_string($raw)){ $d=json_decode($raw,true); if(is_array($d))$rows=$d; }
    $now=time();foreach($rows as $k=>$ts)if(!is_int($ts)||$ts<$now-3600)unset($rows[$k]);if(isset($rows[$id]))return false;$rows[$id]=$now;if(count($rows)>256){asort($rows,SORT_NUMERIC);$rows=array_slice($rows,-256,null,true);} $j=json_encode($rows);if(!is_string($j))return false;return @file_put_contents($path,$j."\n",LOCK_EX)!==false;
}
