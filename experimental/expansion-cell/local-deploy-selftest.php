<?php
declare(strict_types=1);

require_once __DIR__.'/ExpansionLocalFilesystemDeployer.php';

function mustLocal(bool $ok,string $message): void { if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);} }
function rmLocal(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);} @rmdir($dir); }
function modeLocal(string $path): int { clearstatcache(true,$path); return fileperms($path)&0777; }

$base=sys_get_temp_dir().'/kicom-local-deploy-'.bin2hex(random_bytes(5));
$pkg=$base.'/pkg'; $web=$base.'/web';
@mkdir($pkg.'/lib',0700,true); @mkdir($pkg.'/var',0700,true); @mkdir($web,0700,true);
try {
    $exp='exp-'.str_repeat('a',24);
    file_put_contents($pkg.'/bootstrap.php','<?php echo "ok";');
    file_put_contents($pkg.'/federation.php','<?php echo "ok";');
    file_put_contents($pkg.'/status.php','<?php echo "ok";');
    file_put_contents($pkg.'/lib/test.php','<?php return true;');
    file_put_contents($pkg.'/.htaccess','Options -Indexes');
    file_put_contents($pkg.'/var/.htaccess','Require all denied');
    file_put_contents($pkg.'/cell-manifest.json','{"schema":1}');
    file_put_contents($pkg.'/bootstrap.config.php',"<?php return ['expansion_id'=>'$exp','base_url'=>'https://sandbox.example/kicom'];\n");

    $d=new KiComExpansionLocalFilesystemDeployer();
    $r=$d->deploy($pkg,$web);
    mustLocal(!empty($r['ok'])&&($r['code']??'')==='EXPANSION_DEPLOYED_LOCAL','local deploy succeeds');
    mustLocal(is_file($web.'/kicom/bootstrap.php'),'bootstrap copied');
    mustLocal(is_file($web.'/kicom/lib/test.php'),'nested file copied');
    mustLocal(($r['transport']??'')==='local-filesystem','transport marker');
    mustLocal(($r['target']??'')==='kicom/','relative target marker');
    mustLocal(!isset($r['local_directory'],$r['stage_directory']),'server paths not returned');
    mustLocal(modeLocal($web.'/kicom')===0755,'web cell root is traversable');
    mustLocal(modeLocal($web.'/kicom/bootstrap.php')===0644,'public endpoint is web-readable');
    mustLocal(modeLocal($web.'/kicom/lib')===0755,'public lib directory is traversable');
    mustLocal(modeLocal($web.'/kicom/bootstrap.config.php')===0600,'bootstrap secret remains private');
    mustLocal(modeLocal($web.'/kicom/var')===0700,'state directory remains private');

    // Simulate the first live failure: public tree permissions became too strict
    // after deployment, but the managed cell itself is intact.
    chmod($web.'/kicom',0700); chmod($web.'/kicom/bootstrap.php',0600); chmod($web.'/kicom/federation.php',0600);
    $repair=$d->repairExisting($web);
    mustLocal(!empty($repair['ok'])&&($repair['code']??'')==='EXPANSION_EXISTING_CELL_REPAIRED','managed existing cell can be repaired');
    mustLocal(($repair['expansion_id']??'')===$exp,'repair returns only safe expansion id');
    mustLocal(($repair['child_base_url']??'')==='https://sandbox.example/kicom','repair returns child public URL');
    mustLocal(modeLocal($web.'/kicom')===0755&&modeLocal($web.'/kicom/bootstrap.php')===0644,'repair restores web-serving permissions');

    $again=$d->deploy($pkg,$web);
    mustLocal(empty($again['ok'])&&($again['code']??'')==='EXPANSION_TARGET_EXISTS','existing target is not overwritten');
    mustLocal(!isset($again['local_directory'],$again['stage_directory']),'existing-target error hides server paths');

    $bad=$d->deploy($pkg,$base.'/missing');
    mustLocal(empty($bad['ok'])&&($bad['code']??'')==='EXPANSION_LOCAL_WEBROOT_INVALID','unknown webroot rejected');

    $foreign=$base.'/foreign'; @mkdir($foreign.'/kicom',0700,true); file_put_contents($foreign.'/kicom/random.txt','x');
    $refuse=$d->repairExisting($foreign);
    mustLocal(empty($refuse['ok'])&&($refuse['code']??'')==='EXPANSION_EXISTING_TARGET_UNMANAGED','repair refuses foreign existing target');

    echo "KiCom Expansion Local Filesystem selftest: PASS\n";
} finally { rmLocal($base); }
