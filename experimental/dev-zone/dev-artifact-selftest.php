<?php
declare(strict_types=1);
require_once __DIR__.'/DevArtifactImporter.php';

function afail(string $m): never { fwrite(STDERR,"FAIL: $m\n"); exit(1); }
function aok(bool $v,string $m): void { if(!$v) afail($m); echo "OK: $m\n"; }
function arrm(string $d): void { if(!is_dir($d))return; $i=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($i as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($d); }

$base=sys_get_temp_dir().'/kicom-artifact-'.bin2hex(random_bytes(4));
$dev=$base.'/dev';$store=$base.'/store';@mkdir($dev,0700,true);
file_put_contents($dev.'/existing.php',"<?php\ndeclare(strict_types=1);\n// old\n");
try{
    $importer=new KiComDevArtifactImporter($dev,$store);
    $status=$importer->status();aok(!empty($status['ok'])&&($status['scope']??'')==='dev-only','importer ready and dev-only');

    $files=[
        ['path'=>'existing.php','content'=>"<?php\ndeclare(strict_types=1);\n// new\n"],
        ['path'=>'nested/new.json','content'=>"{\"ok\":true}\n"],
    ];
    $rows=[];foreach($files as $f){$rows[]=['path'=>$f['path'],'sha256'=>hash('sha256',$f['content']),'content_b64'=>base64_encode($f['content'])];}
    $bundle=json_encode(['schema'=>1,'type'=>'kicom-dev-bundle','target'=>'dev','created_at'=>gmdate('c'),'files'=>$rows],JSON_UNESCAPED_SLASHES);
    aok(is_string($bundle),'bundle encoded');
    $sha=hash('sha256',$bundle);
    $bad=$importer->installVerifiedBytes($bundle,str_repeat('0',64),'bundle','bad-sha');
    aok(empty($bad['ok'])&&($bad['code']??'')==='DEV_ARTIFACT_SHA_MISMATCH','wrong bundle hash rejected');
    $r=$importer->installVerifiedBytes($bundle,$sha,'bundle','selftest');
    aok(!empty($r['ok'])&&($r['code']??'')==='DEV_ARTIFACT_INSTALLED','bundle installed');
    aok(str_contains((string)file_get_contents($dev.'/existing.php'),'// new'),'existing file replaced');
    aok(json_decode((string)file_get_contents($dev.'/nested/new.json'),true)['ok']===true,'new nested file installed');
    $receipts=glob($store.'/archive/*/receipt.json')?:[];aok(count($receipts)===1,'install receipt retained');
    $archiveDir=dirname($receipts[0]);aok(is_file($archiveDir.'/before/existing.php'),'previous version archived');
    aok(str_contains((string)file_get_contents($archiveDir.'/before/existing.php'),'// old'),'archive contains original bytes');

    $evil=json_encode(['schema'=>1,'type'=>'kicom-dev-bundle','target'=>'dev','files'=>[[
        'path'=>'../escape.php','sha256'=>hash('sha256','x'),'content_b64'=>base64_encode('x')
    ]]],JSON_UNESCAPED_SLASHES);
    $e=$importer->installVerifiedBytes((string)$evil,hash('sha256',(string)$evil),'bundle','path-test');
    aok(empty($e['ok']),'path traversal rejected');
    aok(!is_file($base.'/escape.php'),'no write escaped DEV root');

    echo "DEV ARTIFACT SELFTEST PASS\n";
} finally { arrm($base); }
