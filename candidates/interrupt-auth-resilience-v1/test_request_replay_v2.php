<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/kicom-replay-'.bin2hex(random_bytes(4));@mkdir($root,0700,true);
function kicomAuthDir():string{global $root;return $root;}
function kicomAuthJsonRead(string $f):?array{if(!is_file($f))return null;$r=json_decode((string)file_get_contents($f),true);return is_array($r)?$r:null;}
function kicomAuthJsonWrite(string $f,array $r):bool{@mkdir(dirname($f),0700,true);return file_put_contents($f,json_encode($r),LOCK_EX)!==false;}
require __DIR__.'/request_replay_v2.php';
function ck(bool $b,string $m):void{if(!$b){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "OK $m\n";}
$s='aaaaaaaaaaaaaaaaaaaaaaaa';$rid='req-1234567890abcdef';$tok=str_repeat('b',64);$resp=['ok'=>true,'next_token'=>str_repeat('c',64),'value'=>7];
$r=kicomReplayStore($s,$rid,'AUTONOMY_BUILD_STATUS',['build_id'=>'x','token'=>$tok],$tok,$resp);ck(!empty($r['ok']),'store');
$raw=(string)file_get_contents(kicomReplayFile($s,$rid));ck(!str_contains($raw,$tok)&&!str_contains($raw,str_repeat('c',64)),'no plaintext tokens');
$r=kicomReplayLookup($s,$rid,'AUTONOMY_BUILD_STATUS',['build_id'=>'x','token'=>$tok],$tok);ck(!empty($r['ok'])&&($r['response']['next_token']??'')===str_repeat('c',64),'exact replay');
$r=kicomReplayLookup($s,$rid,'AUTONOMY_BUILD_PATCH',['build_id'=>'x'],$tok);ck(empty($r['ok'])&&($r['code']??'')==='REPLAY_FINGERPRINT_MISMATCH','different action rejected');
$r=kicomReplayLookup($s,$rid,'AUTONOMY_BUILD_STATUS',['build_id'=>'x'],str_repeat('d',64));ck(empty($r['ok'])&&($r['code']??'')==='REPLAY_TOKEN_MISMATCH','different token rejected');
echo "ALL TESTS PASSED\n";
