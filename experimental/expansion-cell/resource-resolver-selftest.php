<?php
declare(strict_types=1);

function mustResource(bool $ok,string $message): void { if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);} }
function rmResource(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);} @rmdir($dir); }

$base=sys_get_temp_dir().'/kicom-resource-resolver-'.bin2hex(random_bytes(5));
$sandbox=$base.'/sandbox'; $stage=$base.'/stage'; $prod=$base.'/prod';
@mkdir($sandbox,0700,true); @mkdir($stage,0700,true); @mkdir($prod,0700,true);

function kicomDeployTarget(string $alias,bool $requireEnabled=true): ?array {
    global $sandbox,$stage,$prod;
    $rows=[
        'sandbox'=>['alias'=>'sandbox','root'=>$sandbox,'health_url'=>'https://sandbox.example/','enabled'=>true,'label'=>'Sandbox','class'=>'test'],
        'stage'=>['alias'=>'stage','root'=>$stage,'health_url'=>'','enabled'=>true,'label'=>'Stage','class'=>'staging'],
        'prod'=>['alias'=>'prod','root'=>$prod,'health_url'=>'https://prod.example/health','enabled'=>true,'label'=>'Production','class'=>'production'],
    ];
    return $rows[$alias]??null;
}

require_once __DIR__.'/ExpansionKiComDeployTargetResolver.php';

try {
    mustResource(KiComExpansionKiComDeployTargetResolver::available(),'resolver detects KiCom registry');
    $r=KiComExpansionKiComDeployTargetResolver::resolve('sandbox');
    mustResource(is_string($r)&&$r===realpath($sandbox),'sandbox resolves internally');
    mustResource(KiComExpansionKiComDeployTargetResolver::resolve('stage')===realpath($stage),'staging resolves internally');
    mustResource(KiComExpansionKiComDeployTargetResolver::resolve('prod')===null,'production resource rejected by v1 adapter');
    mustResource(KiComExpansionKiComDeployTargetResolver::resolve('../sandbox')===null,'invalid alias rejected');

    $public=KiComExpansionKiComDeployTargetResolver::publicDescriptor('sandbox');
    mustResource(is_array($public)&&($public['alias']??'')==='sandbox','public descriptor available');
    mustResource(($public['class']??'')==='test'&&!empty($public['healthcheck'])&&!empty($public['writable']),'public descriptor state');
    mustResource(!array_key_exists('root',$public),'public descriptor hides server root');

    echo "KiCom Expansion Resource Resolver selftest: PASS\n";
} finally { rmResource($base); }
