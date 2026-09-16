<?php
declare(strict_types=1);
$root=__DIR__;$file=$root.'/MEMORY_SYNC_0.9.15.json';
$m=json_decode((string)@file_get_contents($file),true);
function failx(string $m):never{fwrite(STDERR,"FAIL $m\n");exit(1);}function okx(string $m):void{echo "OK $m\n";}
if(!is_array($m))failx('manifest json');
if(($m['schema']??0)!==2)failx('schema');
if(($m['target_runtime']??'')!=='0.9.15'||($m['target_genome_id']??'')!=='kicom-0.9.15-g16'||(int)($m['target_genome_generation']??0)!==16)failx('target baseline');okx('target baseline');
$ops=$m['operations']??null;if(!is_array($ops)||count($ops)!==5)failx('five operations');okx('five operations');
$expectedCounts=['PROJECT_STATE'=>1,'PROTOCOL'=>1,'CHANGELOG'=>1,'NEXT'=>2];$counts=array_fill_keys(array_keys($expectedCounts),0);
foreach($ops as $i=>$op){
    if(!is_array($op)||($op['kind']??'')!=='memory_patch')failx("kind $i");
    $r=(string)($op['resource']??'');if(!array_key_exists($r,$counts))failx("resource $i");$counts[$r]++;
    $base=strtolower((string)($op['base_sha256']??''));if(!preg_match('/^[a-f0-9]{64}$/',$base))failx("base sha $r/$i");
    $find=(string)($op['find']??'');$replace=(string)($op['replace']??'');if($find===''||strlen($find)>2048||strlen($replace)>4096)failx("patch bounds $r/$i");
}
if($counts!==$expectedCounts)failx('resource multiplicity');okx('resource multiplicity and patch bounds');
if(($ops[3]['resource']??'')!=='NEXT'||($ops[4]['resource']??'')!=='NEXT')failx('NEXT order');
if(strtolower((string)$ops[3]['base_sha256'])!=='25c899e99b232171da6dd7bdd0d1f72f4fbf50563e57dc7d9f5e2fd50abc37db')failx('NEXT live base');
if(strtolower((string)$ops[4]['base_sha256'])!=='b7596f525a855432294e64f6822adf8e89e77f71114c72a29188549c587a4063')failx('NEXT intermediate base');
$finalNext=strtolower((string)($m['expected_final_next_sha256']??''));if($finalNext!=='0aab01dd07b29f3fd5372b08bae69ae5cf9150033e91f4c29030e6b6a22d06e9')failx('NEXT final sha');okx('NEXT SHA chain pinned');
$compact=json_encode($ops,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($compact===false)failx('compact encode');$bytes=strlen($compact);if($bytes>6144)failx("compact payload too large $bytes");$sha=hash('sha256',$compact);okx("compact payload bytes=$bytes sha256=$sha");
$raw=(string)file_get_contents($file);foreach(['token','totp','freeotp','password','secret','session_id'] as $needle)if(stripos($raw,'"'.$needle.'"')!==false)failx("secret-like field $needle");okx('no authentication secret fields');
echo "ALL MEMORY SYNC TESTS PASSED\n";
