<?php
declare(strict_types=1);

/* Public read-only trusted-source manifest status: hashes only, never file content. */
function kicomSourceManifestStatusV1(): array {
    $rows=kicomAutonomySourceList();$out=[];
    foreach($rows as $r){if(!is_array($r))continue;$path=(string)($r['path']??'');$sha=strtolower((string)($r['sha256']??''));if($path===''||!preg_match('/^[a-f0-9]{64}$/',$sha))continue;$out[]=['path'=>$path,'bytes'=>(int)($r['bytes']??0),'sha256'=>$sha];if(count($out)>=128)break;}
    usort($out,fn($a,$b)=>strcmp($a['path'],$b['path']));$canon=json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'';
    return ['ok'=>true,'code'=>'OK','version'=>defined('KICOM_VERSION')?KICOM_VERSION:'','manifest_sha256'=>hash('sha256',$canon),'files'=>$out];
}
