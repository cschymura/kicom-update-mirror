<?php
declare(strict_types=1);
require_once __DIR__.'/DevObserver.php';
function mustObs(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL $m\n");exit(1);}}
function rrObs(string $d):void{if(!is_dir($d))return;$i=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($i as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($d);}
$base=sys_get_temp_dir().'/kicom-observer-'.bin2hex(random_bytes(4));@mkdir($base.'/dev/expansion/cell-runtime',0700,true);
try{
 foreach(['dev-api.php','dev-auth.php','dev-expansion.php','dev-artifact.php','DevSession.php','DevExpansionBindings.php','DevSandboxPerception.php','DevArtifactImporter.php','expansion/ExpansionService.php','expansion/ExpansionOrchestrator.php','expansion/ExpansionCellPackageBuilder.php','expansion/ExpansionLocalFilesystemDeployer.php','expansion/ExpansionKiComDeployTargetResolver.php','expansion/cell-runtime/common.php','expansion/cell-runtime/bootstrap.php','expansion/cell-runtime/federation.php','expansion/cell-runtime/status.php'] as $f){$p=$base.'/dev/'.$f;@mkdir(dirname($p),0700,true);file_put_contents($p,'x');}
 $o=new KiComDevObserver($base.'/obs');$id=$o->begin('execute_sandbox',['token'=>'SECRET','phase'=>'prepare']);$o->stage($id,'package_build','EXPANSION_PACKAGE_SOURCE_MISSING',['path'=>'/secret/path','file'=>'cell-runtime/.htaccess']);$o->finish($id,false,'EXPANSION_PACKAGE_SOURCE_MISSING',['password'=>'nope']);
 $recent=$o->recent(10);mustObs(count($recent)===3,'three trace rows');$flat=json_encode($recent);mustObs(is_string($flat)&&!str_contains($flat,'SECRET')&&!str_contains($flat,'/secret/path')&&!str_contains($flat,'nope'),'secrets and paths redacted');
 $d=$o->doctor($base.'/dev');mustObs(!empty($d['ok'])&&($d['code']??'')==='DEV_DOCTOR_OK','doctor ok');mustObs(($d['source']['missing']??[])===[],'no missing files');
 @unlink($base.'/dev/dev-api.php');$d2=$o->doctor($base.'/dev');mustObs(empty($d2['ok'])&&in_array('dev-api.php',$d2['source']['missing']??[],true),'missing endpoint detected');
 echo "KiCom DEV Observer selftest: PASS\n";
}finally{rrObs($base);}
