<?php
declare(strict_types=1);
$root=__DIR__;$m=json_decode((string)@file_get_contents($root.'/MEMORY_SYNC_0.9.15.json'),true);
function failt(string $m):never{fwrite(STDERR,"FAIL $m\n");exit(1);}function b64u(string $v):string{return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
function phaseStats(array $ops,array $idxs):array{$p=[];$resources=[];foreach($idxs as $i){$p[]=$ops[$i];$resources[]=(string)$ops[$i]['resource'];}$json=json_encode($p,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false)failt('json');$gz=gzencode($json,9);if($gz===false)failt('gzip');return ['resources'=>$resources,'raw_bytes'=>strlen($json),'base64url_chars'=>strlen(b64u($json)),'gzip_bytes'=>strlen($gz),'gzip_base64url_chars'=>strlen(b64u($gz)),'sha256'=>hash('sha256',$json)];}
if(!is_array($m)||!is_array($m['operations']??null)||count($m['operations'])!==4)failt('sync manifest');$ops=array_values($m['operations']);
/* Report singles for diagnostics. */
foreach([0,1,2,3] as $i){$s=phaseStats($ops,[$i]);echo 'SINGLE resource='.implode('+',$s['resources']).' raw='.$s['raw_bytes'].' b64url='.$s['base64url_chars'].' gzip='.$s['gzip_bytes'].' gzip_b64url='.$s['gzip_base64url_chars'].' sha256='.$s['sha256']."\n";}
/* Three phases means exactly one pair plus two singles. Pick the split minimizing the longest URL payload. */
$pairs=[[0,1],[0,2],[0,3],[1,2],[1,3],[2,3]];$rows=[];
foreach($pairs as $pair){$rest=array_values(array_diff([0,1,2,3],$pair));$groups=[$pair,[$rest[0]],[$rest[1]]];$phases=[];$maxB64=0;$maxRaw=0;foreach($groups as $idxs){$s=phaseStats($ops,$idxs);$phases[]=$s;$maxB64=max($maxB64,$s['base64url_chars']);$maxRaw=max($maxRaw,$s['raw_bytes']);}$rows[]=['phases'=>$phases,'max_base64url_chars'=>$maxB64,'max_raw_bytes'=>$maxRaw];}
usort($rows,fn($x,$y)=>$x['max_base64url_chars']<=>$y['max_base64url_chars']);$best=$rows[0]??null;if(!is_array($best))failt('no split');
echo "BEST_THREE_PHASE_SPLIT\n";foreach($best['phases'] as $n=>$p)echo 'PHASE '.($n+1).' resources='.implode('+',$p['resources']).' raw='.$p['raw_bytes'].' b64url='.$p['base64url_chars'].' gzip='.$p['gzip_bytes'].' gzip_b64url='.$p['gzip_base64url_chars'].' sha256='.$p['sha256']."\n";
echo 'MAX_B64URL='.$best['max_base64url_chars']."\n";if($best['max_raw_bytes']>6144)failt('phase exceeds KiCom compact batch decoded limit');if($best['max_base64url_chars']>3500)failt('three-phase GET still too large');echo "ALL THREE-PHASE TRANSPORT TESTS PASSED\n";
