<?php
declare(strict_types=1);

$root=__DIR__;
$manifestFile=$root.'/PROMOTION_MANIFEST.json';
$cfg=json_decode((string)@file_get_contents($manifestFile),true);
if(!is_array($cfg)||!is_array($cfg['runtime_include']??null)){fwrite(STDERR,"invalid promotion manifest\n");exit(2);}
$files=array_values(array_filter($cfg['runtime_include'],'is_string'));
if(!$files){fwrite(STDERR,"empty runtime_include\n");exit(2);}
$excluded=array_fill_keys(array_values(array_filter($cfg['runtime_exclude_superseded']??[],'is_string')),true);
foreach($files as $file){
    if(isset($excluded[$file])){fwrite(STDERR,"promote/exclude conflict $file\n");exit(2);}
    if(!preg_match('/^[A-Za-z0-9_.-]+\.php$/',$file)){fwrite(STDERR,"invalid runtime filename $file\n");exit(2);}
}
$candidate=(string)($cfg['candidate']??'interrupt-auth-resilience-v5');
$out="<?php\ndeclare(strict_types=1);\n\n/* GENERATED KiCom interrupt/auth resilience living bundle.\n * Candidate only. Deterministically built from PROMOTION_MANIFEST runtime_include.\n */\n";
$sources=[];
foreach($files as $file){
    $path=$root.'/'.$file;
    $raw=@file_get_contents($path);
    if($raw===false){fwrite(STDERR,"missing $file\n");exit(3);}
    $sources[$file]=hash('sha256',$raw);
    $raw=preg_replace('/\A(?:\xEF\xBB\xBF)?<\?php\s*/','',$raw,1);
    $raw=preg_replace('/\Adeclare\(strict_types=1\);\s*/','',$raw,1);
    $raw=preg_replace('/^require_once __DIR__\s*\.\s*[\'\"][^\'\"]+[\'\"]\s*;\s*\R/m','',$raw);
    if($raw===null){fwrite(STDERR,"rewrite failed $file\n");exit(4);}
    $out.="\n/* BEGIN $file sha256={$sources[$file]} */\n".rtrim($raw)."\n/* END $file */\n";
}
$dir=$root.'/generated';
if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){fwrite(STDERR,"mkdir failed\n");exit(5);}
$bundleName='living_bundle_v5.php';
$bundle=$dir.'/'.$bundleName;
if(file_put_contents($bundle,$out)===false){fwrite(STDERR,"bundle write failed\n");exit(6);}
$bundleSha=hash('sha256',$out);
$meta=[
    'schema'=>2,
    'candidate'=>$candidate,
    'promotion_manifest_sha256'=>hash_file('sha256',$manifestFile)?:'',
    'bundle'=>$bundleName,
    'bytes'=>strlen($out),
    'sha256'=>$bundleSha,
    'sources'=>$sources,
];
file_put_contents($dir.'/living_bundle_v5.json',json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
file_put_contents($dir.'/living_bundle_v5.sha256',$bundleSha."  $bundleName\n");
echo "CANDIDATE=$candidate\n";
echo "FILES=".count($files)."\n";
echo "BUNDLE_BYTES=".strlen($out)."\n";
echo "BUNDLE_SHA256=$bundleSha\n";
