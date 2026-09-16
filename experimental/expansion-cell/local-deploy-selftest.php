<?php
declare(strict_types=1);

require_once __DIR__.'/ExpansionLocalFilesystemDeployer.php';

function mustLocal(bool $ok,string $message): void { if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);} }
function rmLocal(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);} @rmdir($dir); }

$base=sys_get_temp_dir().'/kicom-local-deploy-'.bin2hex(random_bytes(5));
$pkg=$base.'/pkg'; $web=$base.'/web';
@mkdir($pkg.'/lib',0700,true); @mkdir($web,0700,true);
try {
    file_put_contents($pkg.'/bootstrap.php','<?php echo "ok";');
    file_put_contents($pkg.'/lib/test.php','<?php return true;');
    file_put_contents($pkg.'/.htaccess','Options -Indexes');

    $d=new KiComExpansionLocalFilesystemDeployer();
    $r=$d->deploy($pkg,$web);
    mustLocal(!empty($r['ok'])&&($r['code']??'')==='EXPANSION_DEPLOYED_LOCAL','local deploy succeeds');
    mustLocal(is_file($web.'/kicom/bootstrap.php'),'bootstrap copied');
    mustLocal(is_file($web.'/kicom/lib/test.php'),'nested file copied');
    mustLocal(($r['transport']??'')==='local-filesystem','transport marker');

    $again=$d->deploy($pkg,$web);
    mustLocal(empty($again['ok'])&&($again['code']??'')==='EXPANSION_TARGET_EXISTS','existing target is not overwritten');

    $bad=$d->deploy($pkg,$base.'/missing');
    mustLocal(empty($bad['ok'])&&($bad['code']??'')==='EXPANSION_LOCAL_WEBROOT_INVALID','unknown webroot rejected');

    echo "KiCom Expansion Local Filesystem selftest: PASS\n";
} finally { rmLocal($base); }
