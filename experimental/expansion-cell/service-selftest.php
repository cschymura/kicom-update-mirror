<?php
declare(strict_types=1);
require_once __DIR__.'/ExpansionProtocol.php';
require_once __DIR__.'/ExpansionParentIdentity.php';
require_once __DIR__.'/ExpansionRegistry.php';
require_once __DIR__.'/ExpansionCellPackageBuilder.php';
require_once __DIR__.'/ExpansionFtpDeployer.php';
require_once __DIR__.'/ExpansionLocalFilesystemDeployer.php';
require_once __DIR__.'/ExpansionCronRelay.php';
require_once __DIR__.'/ExpansionHttpTransport.php';
require_once __DIR__.'/ExpansionOrchestrator.php';
require_once __DIR__.'/ExpansionService.php';
function ok2(bool $v,string $m):void{if(!$v){fwrite(STDERR,"FAIL $m\n");exit(1);}}
function rm2(string $d):void{if(!is_dir($d))return;$i=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($i as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($d);}
$base=sys_get_temp_dir().'/kicom-expansion-service-'.bin2hex(random_bytes(5));@mkdir($base,0700,true);
try{
 $identity=new KiComExpansionParentIdentity($base.'/id','https://parent.example/kicom');$a=$identity->ensure();ok2(!empty($a['ok']),'identity create');$b=$identity->ensure();ok2(($a['parent']['cell_id']??'')===($b['parent']['cell_id']??''),'identity stable');ok2(!isset($a['parent']['secret_key']),'secret not public');
 $service=new KiComExpansionService($base.'/service',__DIR__,'https://parent2.example/kicom');$s=$service->status();ok2(!empty($s['ok']),'service status');ok2(isset($s['parent']['cell_id'])&&is_array($s['cells'])&&count($s['cells'])===0,'empty federation status');
 $noResolver=$service->execute(['target_base_url'=>'https://sandbox.example','deploy_mode'=>'resource','deployment_resource'=>'sandbox']);ok2(empty($noResolver['ok'])&&($noResolver['code']??'')==='EXPANSION_DEPLOYMENT_RESOURCE_RESOLVER_UNAVAILABLE','resource resolver required');
 $badName=$service->execute(['target_base_url'=>'https://sandbox.example','deploy_mode'=>'resource','deployment_resource'=>'../sandbox']);ok2(empty($badName['ok'])&&($badName['code']??'')==='EXPANSION_DEPLOYMENT_RESOURCE_INVALID','resource alias validation');
 echo "KiCom Expansion Service selftest: PASS\n";
}finally{rm2($base);}
