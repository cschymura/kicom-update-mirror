<?php
declare(strict_types=1);

$root=__DIR__;
$files=[
    'interrupt_resilience_v1.php',
    'interrupt_resilience_v2.php',
    'request_replay_v3.php',
    'request_guard_v5.php',
    'session_lock_v2.php',
    'session_consume_resilient_v3.php',
    'session_open_resilient_v2.php',
    'guarded_action_v5.php',
    'approval_status_v1.php',
    'update_pending_inspect_v1.php',
];
$out="<?php\ndeclare(strict_types=1);\n\n/* GENERATED KiCom interrupt/auth resilience v5 living bundle.\n * Candidate only. Built deterministically from the explicit allowlist below.\n */\n";
$manifest=[];
foreach($files as $file){
    $path=$root.'/'.$file;
    $raw=@file_get_contents($path);
    if($raw===false){fwrite(STDERR,"missing $file\n");exit(2);}
    $manifest[$file]=hash('sha256',$raw);
    $raw=preg_replace('/\A(?:\xEF\xBB\xBF)?<\?php\s*/','',$raw,1);
    $raw=preg_replace('/\Adeclare\(strict_types=1\);\s*/','',$raw,1);
    $raw=preg_replace('/^require_once __DIR__\s*\.\s*[\'\"][^\'\"]+[\'\"]\s*;\s*\R/m','',$raw);
    if($raw===null){fwrite(STDERR,"rewrite failed $file\n");exit(3);}
    $out.="\n/* BEGIN $file sha256={$manifest[$file]} */\n".rtrim($raw)."\n/* END $file */\n";
}
$dir=$root.'/generated';
if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){fwrite(STDERR,"mkdir failed\n");exit(4);}
$bundle=$dir.'/living_bundle_v5.php';
if(file_put_contents($bundle,$out)===false){fwrite(STDERR,"bundle write failed\n");exit(5);}
$bundleSha=hash('sha256',$out);
$meta=[
    'schema'=>1,
    'candidate'=>'interrupt-auth-resilience-v5',
    'bundle'=>'living_bundle_v5.php',
    'bytes'=>strlen($out),
    'sha256'=>$bundleSha,
    'sources'=>$manifest,
];
file_put_contents($dir.'/living_bundle_v5.json',json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
file_put_contents($dir.'/living_bundle_v5.sha256',$bundleSha."  living_bundle_v5.php\n");
echo "BUNDLE_BYTES=".strlen($out)."\nBUNDLE_SHA256=$bundleSha\n";
