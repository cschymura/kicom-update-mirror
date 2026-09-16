<?php
declare(strict_types=1);

$GLOBALS['diag_root']=sys_get_temp_dir().'/kicom-dev-diag-'.bin2hex(random_bytes(4));
@mkdir($GLOBALS['diag_root'].'/dev_zone/sessions',0700,true);
function kicomVarDir(): string { return $GLOBALS['diag_root']; }
function kicomLivingExperience(): array { return ['events'=>3,'types'=>['test'=>3]]; }
require_once __DIR__.'/DevDiagnostics.php';
function dg(bool $v,string $m): void { if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);} echo "OK: $m\n"; }

$file=$GLOBALS['diag_root'].'/dev_zone/sessions/audit.jsonl';
file_put_contents($file,json_encode(['event'=>'issued','session_id'=>'a'])."\n".json_encode(['event'=>'revoked','session_id'=>'b'])."\n");
$r=KiComDevDiagnostics::read(['stream'=>'dev_audit','limit'=>1],['scope'=>'dev']);
dg(($r['ok']??false)&&count($r['entries']??[])===1&&(($r['entries'][0]['event']??'')==='revoked'),'audit tail bounded');
$r=KiComDevDiagnostics::read(['stream'=>'living_experience'],['scope'=>'dev']);
dg(($r['ok']??false)&&(($r['data']['events']??0)===3),'living experience available');
$r=KiComDevDiagnostics::read(['stream'=>'/var/log/system.log'],['scope'=>'dev']);
dg(($r['ok']??true)===false&&($r['code']??'')==='DEV_LOG_STREAM_FORBIDDEN','arbitrary log path forbidden');

echo "DEV DIAGNOSTICS SELFTEST PASS\n";
