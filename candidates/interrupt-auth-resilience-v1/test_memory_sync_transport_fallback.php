<?php
declare(strict_types=1);
$root=__DIR__;$m=json_decode((string)@file_get_contents($root.'/MEMORY_SYNC_0.9.15.json'),true);
function failt(string $m):never{fwrite(STDERR,"FAIL $m\n");exit(1);}function b64u(string $v):string{return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
function phaseStats(array $ops,array $idxs):array{$p=[];$resources=[];foreach($idxs as $i){$p[]=$ops[$i];$resources[]=(string)$ops[$i]['resource'];}$json=json_encode($p,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false)failt('json');$gz=gzencode($json,9);if($gz===false)failt('gzip');return ['indexes'=>$idxs,'resources'=>$resources,'raw_bytes'=>strlen($json),'base64url_chars'=>strlen(b64u($json)),'gzip_bytes'=>strlen($gz),'gzip_base64url_chars'=>strlen(b64u($gz)),'sha256'=>hash('sha256',$json)];}
if(!is_array($m)||!is_array($m['operations']??null)||count($m['operations'])!==5)failt('sync manifest');$ops=array_values($m['operations']);
foreach(range(0,4) as $i){$s=phaseStats($ops,[$i]);echo 'SINGLE op='.$i.' resource='.implode('+',$s['resources']).' raw='.$s['raw_bytes'].' b64url='.$s['base64url_chars'].' gzip='.$s['gzip_bytes'].' gzip_b64url='.$s['gzip_base64url_chars'].' sha256='.$s['sha256']."\n";}
/* Preserve manifest order. Enumerate every contiguous 3-phase split. The two SHA-dependent NEXT operations must never share a phase. */
$rows=[];
for($c1=1;$c1<=3;$c1++)for($c2=$c1+1;$c2<=4;$c2++){
    $groups=[range(0,$c1-1),range($c1,$c2-1),range($c2,4)];
    $invalid=false;$phases=[];$maxB64=0;$maxRaw=0;
    foreach($groups as $idxs){if(in_array(3,$idxs,true)&&in_array(4,$idxs,true)){$invalid=true;break;}$s=phaseStats($ops,$idxs);$phases[]=$s;$maxB64=max($maxB64,$s['base64url_chars']);$maxRaw=max($maxRaw,$s['raw_bytes']);}
    if(!$invalid)$rows[]=['phases'=>$phases,'max_base64url_chars'=>$maxB64,'max_raw_bytes'=>$maxRaw];
}
if(!$rows)failt('no safe split');usort($rows,fn($x,$y)=>($x['max_base64url_chars']<=>$y['max_base64url_chars'])?:($x['max_raw_bytes']<=>$y['max_raw_bytes']));$best=$rows[0];
echo "BEST_SAFE_THREE_PHASE_SPLIT\n";foreach($best['phases'] as $n=>$p)echo 'PHASE '.($n+1).' ops='.implode(',',$p['indexes']).' resources='.implode('+',$p['resources']).' raw='.$p['raw_bytes'].' b64url='.$p['base64url_chars'].' gzip='.$p['gzip_bytes'].' gzip_b64url='.$p['gzip_base64url_chars'].' sha256='.$p['sha256']."\n";
echo 'MAX_B64URL='.$best['max_base64url_chars']."\n";if($best['max_raw_bytes']>6144)failt('phase exceeds KiCom compact batch decoded limit');if($best['max_base64url_chars']>3500)failt('safe three-phase GET still too large');echo "ALL SAFE THREE-PHASE TRANSPORT TESTS PASSED\n";
