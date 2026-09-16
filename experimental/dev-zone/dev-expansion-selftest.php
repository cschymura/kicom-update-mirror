<?php
declare(strict_types=1);

function devExpansionMust(bool $ok,string $message): void { if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);} }
function devExpansionRm(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);} @rmdir($dir); }

$base=sys_get_temp_dir().'/kicom-dev-expansion-'.bin2hex(random_bytes(5));
$var=$base.'/var'; $sandbox=$base.'/sandbox';
@mkdir($var,0700,true); @mkdir($sandbox,0700,true);

function kicomVarDir(): string { global $var; return $var; }
function kicomDeployTarget(string $alias,bool $requireEnabled=true): ?array {
    global $sandbox;
    if($alias!=='sandbox') return null;
    return [
        'alias'=>'sandbox',
        'root'=>$sandbox,
        'health_url'=>'https://sandbox.rurtalbahn.info/',
        'enabled'=>true,
        'label'=>'sandbox',
        'class'=>'test',
    ];
}

require_once __DIR__.'/DevExpansionBindings.php';

try {
    $binding=new KiComDevExpansionBindings(dirname(__DIR__).'/expansion-cell','https://kicom.rurtalbahn.info');
    $status=$binding->resourceStatus();
    devExpansionMust(!empty($status['ok'])&&($status['code']??'')==='EXPANSION_DEPLOYMENT_RESOURCE_READY','sandbox resource ready');
    devExpansionMust(($status['target_base_url']??'')==='https://sandbox.rurtalbahn.info','sandbox target fixed');
    $resource=(array)($status['resource']??[]);
    devExpansionMust(($resource['alias']??'')==='sandbox'&&($resource['class']??'')==='test','sandbox alias/class fixed');
    devExpansionMust(!array_key_exists('root',$resource),'server root hidden');
    echo "KiCom DEV Expansion binding selftest: PASS\n";
} finally { devExpansionRm($base); }
