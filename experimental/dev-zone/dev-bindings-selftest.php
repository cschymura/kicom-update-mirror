<?php
declare(strict_types=1);

const KICOM_VERSION = '0.9.15';
$GLOBALS['stage'] = [];
$GLOBALS['hist'] = [];
$GLOBALS['build_root'] = sys_get_temp_dir().'/kicom-dev-bindings-'.bin2hex(random_bytes(4));
@mkdir($GLOBALS['build_root'], 0700, true);

function kicomSafeRelativePath(string $p): ?string { $p=trim($p); return preg_match('~^[A-Za-z0-9_./-]+\.(?:php|txt|json|js|css|md|html)$~',$p)&&!str_contains($p,'..')?$p:null; }
function kicomEncodeBase64Url(string $v): string { return rtrim(strtr(base64_encode($v),'+/','-_'),'='); }
function kicomDecodeBase64Url(string $v,bool $empty=false): ?string { if($v===''&&$empty)return ''; $s=strtr($v,'-_','+/');$p=strlen($s)%4;if($p)$s.=str_repeat('=',4-$p);$r=base64_decode($s,true);return $r===false?null:$r; }
function kicomReadStage(string $p): ?string { return $GLOBALS['stage'][$p]??null; }
function kicomCurrentHash(string $p): string { return isset($GLOBALS['stage'][$p])?hash('sha256',$GLOBALS['stage'][$p]):'NEW'; }
function kicomHistoryCount(string $p): int { return count($GLOBALS['hist'][$p]??[]); }
function kicomHistoryFiles(string $p): array { return $GLOBALS['hist'][$p]??[]; }
function kicomValidateContent(string $p,string $c): array { return ['status'=>'ok','message'=>'TEST_OK']; }
function kicomAtomicStageWrite(string $p,string $c,string $a,?string $b=null): array { if($b!==null&&!hash_equals(kicomCurrentHash($p),$b))return ['ok'=>false,'code'=>'BASE_CONFLICT'];$GLOBALS['stage'][$p]=$c;$r=['revision'=>'r'.(count($GLOBALS['hist'][$p]??[])+1),'created_at'=>gmdate('c'),'action'=>$a,'bytes'=>strlen($c),'sha256'=>hash('sha256',$c)];$GLOBALS['hist'][$p][]=$r;return ['ok'=>true,'code'=>'WRITE_OK','revision'=>$r['revision'],'sha256'=>$r['sha256'],'bytes'=>$r['bytes']]; }
function kicomDeleteStageWithHistory(string $p): array { if(!isset($GLOBALS['stage'][$p]))return ['ok'=>false,'code'=>'FILE_NOT_FOUND'];unset($GLOBALS['stage'][$p]);return ['ok'=>true,'code'=>'DELETE_OK']; }
function kicomAutonomySourceList(): array { return [['path'=>'lib.php','bytes'=>3,'sha256'=>hash('sha256','abc')]]; }
function kicomAutonomySourceRead(string $p,int $o=0,int $l=49152): array { return ['ok'=>true,'code'=>'SOURCE_OK','path'=>$p,'offset'=>$o,'next_offset'=>$o+3,'eof'=>true,'data'=>kicomEncodeBase64Url('abc')]; }
function kicomFastBuildBegin(string $sid): array { return ['ok'=>true,'code'=>'BUILD_BEGIN','build_id'=>'11111111111111111111','session_id'=>$sid]; }
function kicomFastBuildPatch(string $sid,string $id,string $p,string $b,string $f,string $r): array { return ['ok'=>true,'code'=>'BUILD_PATCH','build_id'=>$id,'path'=>$p]; }
function kicomFastBuildStatus(string $sid,string $id): array { return ['ok'=>true,'code'=>'BUILD_STATUS','build_id'=>$id,'status'=>'open']; }
function kicomFastBuildPrepareRelease(string $sid,string $id,string $v,string $reason,string $summary=''): array { return ['ok'=>true,'code'=>'PREPARED','candidate_version'=>$v]; }
function kicomFastBuildDir(string $id): string { return $GLOBALS['build_root'].'/'.$id; }
function kicomFastBuildMeta(string $sid,string $id): array { $d=kicomFastBuildDir($id);$m=json_decode((string)@file_get_contents($d.'/meta.json'),true);if(!is_array($m)||($m['session_id']??'')!==$sid)return ['ok'=>false,'code'=>'BUILD_NOT_FOUND'];return ['ok'=>true,'dir'=>$d,'meta'=>$m]; }
function kicomFastBuildRm(string $d): void { if(!is_dir($d))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}@rmdir($d); }
function kicomGenomeCurrent(): array { return ['id'=>'kicom-0.9.15-g16','generation'=>16]; }
function kicomSafeUpdatePath(string $p): ?string { return preg_match('~^[A-Za-z0-9_./-]+$~',$p)&&!str_contains($p,'..')?$p:null; }
function kicomSelfUpdateZipInspect(string $f): array { return is_file($f)?['ok'=>true,'code'=>'VERIFIED']:['ok'=>false,'code'=>'MISSING']; }
function kicomSelfUpdateRiskClass(array $i): array { return ['class'=>'red']; }
function kicomAuthJsonWrite(string $f,array $r): bool { $j=json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);return is_string($j)&&@file_put_contents($f,$j."\n",LOCK_EX)!==false; }

require_once __DIR__.'/DevKiComBindings.php';
function fb(bool $v,string $m): void { if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);} echo "OK: $m\n"; }

fb((KiComDevRuntimeBindings::ready()['ok']??false)===true,'runtime bindings ready');
$h=KiComDevRuntimeBindings::handlers();
$auth=['session_id'=>'aaaaaaaaaaaaaaaaaaaaaaaa','scope'=>'dev'];

$r=$h['DEV_SOURCE_SNAPSHOT']([], $auth);fb(($r['ok']??false)&&($r['count']??0)===1,'source snapshot list');
$r=$h['DEV_WORKSPACE_WRITE'](['path'=>'prototype/test.php','content_b64'=>kicomEncodeBase64Url('<?php echo 1;'),'base_sha256'=>'NEW'],$auth);fb(($r['ok']??false)&&isset($GLOBALS['stage']['prototype/test.php']),'workspace write');
$sha=hash('sha256',$GLOBALS['stage']['prototype/test.php']);
$r=$h['DEV_WORKSPACE_READ'](['path'=>'prototype/test.php'],$auth);fb(($r['ok']??false)&&($r['sha256']??'')===$sha,'workspace read');
$r=$h['DEV_WORKSPACE_HISTORY'](['path'=>'prototype/test.php'],$auth);fb(($r['ok']??false)&&count($r['revisions']??[])===1,'workspace history');
$r=$h['DEV_WORKSPACE_DELETE'](['path'=>'prototype/test.php','base_sha256'=>$sha],$auth);fb(($r['ok']??false)&&!isset($GLOBALS['stage']['prototype/test.php']),'workspace delete');
$r=$h['DEV_BUILD_BEGIN']([], $auth);fb(($r['ok']??false)&&($r['build_id']??'')==='11111111111111111111','build begin');

$id='22222222222222222222';$dir=kicomFastBuildDir($id);@mkdir($dir.'/src/genome',0700,true);
$lib="<?php\ndeclare(strict_types=1);\nconst KICOM_VERSION = '0.9.16';\n";file_put_contents($dir.'/src/lib.php',$lib);
$gen=['id'=>'kicom-0.9.16-g17','version'=>'0.9.16','parent'=>'kicom-0.9.15-g16','generation'=>17,'components'=>[['path'=>'lib.php','sha256'=>hash('sha256',$lib)]]];file_put_contents($dir.'/src/genome/genome.json',json_encode($gen,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
$meta=['id'=>$id,'session_id'=>$auth['session_id'],'status'=>'open','base_files'=>['lib.php'=>hash_file('sha256',$dir.'/src/lib.php'),'genome/genome.json'=>hash_file('sha256',$dir.'/src/genome/genome.json')]];kicomAuthJsonWrite($dir.'/meta.json',$meta);
$r=$h['DEV_BUILD_TEST'](['build_id'=>$id],$auth);fb(($r['ok']??false)&&($r['checked']??0)===2,'build test');
$r=$h['DEV_BUILD_FINALIZE_CANDIDATE'](['build_id'=>$id],$auth);fb(($r['ok']??false)&&($r['code']??'')==='DEV_CANDIDATE_READY'&&($r['production_pending_created']??true)===false,'candidate export without production pending');
$r=$h['DEV_CANDIDATE_READ'](['build_id'=>$id,'offset'=>0,'length'=>64],$auth);fb(($r['ok']??false)&&isset($r['data_b64']),'candidate chunk read');
$r=$h['DEV_CANDIDATE_DISCARD'](['build_id'=>$id],$auth);fb(($r['ok']??false)&&!is_dir($dir),'candidate discard');

echo "DEV BINDINGS SELFTEST PASS\n";
