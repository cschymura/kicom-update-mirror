<?php
declare(strict_types=1);
$root=__DIR__;$file=$root.'/MEMORY_SYNC_0.9.15.json';
$m=json_decode((string)@file_get_contents($file),true);
function failx(string $m):never{fwrite(STDERR,"FAIL $m\n");exit(1);}function okx(string $m):void{echo "OK $m\n";}
if(!is_array($m))failx('manifest json');
if(($m['target_runtime']??'')!=='0.9.15'||($m['target_genome_id']??'')!=='kicom-0.9.15-g16'||(int)($m['target_genome_generation']??0)!==16)failx('target baseline');okx('target baseline');
$ops=$m['operations']??null;if(!is_array($ops)||count($ops)!==4)failx('four operations');okx('four operations');
$expected=['PROJECT_STATE','PROTOCOL','CHANGELOG','NEXT'];$seen=[];
foreach($ops as $i=>$op){
    if(!is_array($op)||($op['kind']??'')!=='memory_patch')failx("kind $i");
    $r=(string)($op['resource']??'');if(!in_array($r,$expected,true)||isset($seen[$r]))failx("resource $i");$seen[$r]=true;
    $base=strtolower((string)($op['base_sha256']??''));if(!preg_match('/^[a-f0-9]{64}$/',$base))failx("base sha $r");
    $find=(string)($op['find']??'');$replace=(string)($op['replace']??'');if($find===''||strlen($find)>2048||strlen($replace)>4096)failx("patch bounds $r");
}
sort($expected);$keys=array_keys($seen);sort($keys);if($keys!==$expected)failx('resource set');okx('resource set and patch bounds');
$compact=json_encode($ops,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($compact===false)failx('compact encode');$bytes=strlen($compact);if($bytes>6144)failx("compact payload too large $bytes");$sha=hash('sha256',$compact);okx("compact payload bytes=$bytes sha256=$sha");
$raw=(string)file_get_contents($file);foreach(['token','totp','freeotp','password','secret','session_id'] as $needle)if(stripos($raw,'"'.$needle.'"')!==false)failx("secret-like field $needle");okx('no authentication secret fields');
echo "ALL MEMORY SYNC TESTS PASSED\n";
