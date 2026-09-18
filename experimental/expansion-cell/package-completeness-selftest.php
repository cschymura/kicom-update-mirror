<?php
declare(strict_types=1);
require_once __DIR__.'/ExpansionProtocol.php';
require_once __DIR__.'/ExpansionCellPackageBuilder.php';
function pc(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL $m\n");exit(1);}}
function pcRm(string $d):void{if(!is_dir($d))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($d);}
function pcSeed(string $src):void{
    @mkdir($src.'/cell-runtime',0700,true);
    foreach(['ExpansionProtocol.php','CellNode.php','CellLiving.php','CellPerceptionAction.php','CellWorldModel.php','CellEvolutionReadiness.php','CellEvolutionReadinessAdapter.php'] as $f)pc(copy(__DIR__.'/'.$f,$src.'/'.$f),'copy '.$f);
    foreach(['common.php','bootstrap.php','federation.php','status.php','doctor.php','living-schema.json'] as $f)pc(copy(__DIR__.'/cell-runtime/'.$f,$src.'/cell-runtime/'.$f),'copy runtime '.$f);
}
$base=sys_get_temp_dir().'/kicom-package-complete-'.bin2hex(random_bytes(5));@mkdir($base,0700,true);
try{
    $src=$base.'/source';pcSeed($src);
    $id=KiComExpansionProtocol::createIdentity('cell-'.str_repeat('a',24));
    $parent=['cell_id'=>$id['cell_id'],'public_key'=>$id['public_key'],'base_url'=>'https://parent.example/kicom'];
    $prepared=['expansion_id'=>'exp-'.str_repeat('b',24),'enrollment_token'=>KiComExpansionProtocol::enrollmentToken(),'child_base_url'=>'https://child.example/kicom'];
    $builder=new KiComExpansionCellPackageBuilder($src);

    $ok=$builder->build($base.'/pkg-ok',$prepared,$parent);
    pc(!empty($ok['ok'])&&!empty($ok['intrinsic_complete']),'complete package builds');
    $manifest=json_decode((string)file_get_contents($base.'/pkg-ok/cell-manifest.json'),true);
    pc(is_array($manifest)&&($manifest['intrinsic_complete']??false)===true,'manifest proves intrinsic completeness');
    $paths=[];foreach($manifest['files']??[] as $r)if(is_array($r))$paths[]=(string)($r['path']??'');
    foreach(['lib/CellLiving.php','lib/CellPerceptionAction.php','lib/CellWorldModel.php','lib/CellEvolutionReadiness.php','lib/CellEvolutionReadinessAdapter.php','lib/.htaccess','living-schema.json','doctor.php'] as $p)pc(in_array($p,$paths,true),'manifest contains '.$p);
    pc(($manifest['private_diagnostics']['evolution_readiness']['http_exposed']??null)===false,'readiness remains private');
    pc(($manifest['private_diagnostics']['evolution_readiness']['promotion_authority']??null)===false,'readiness has no promotion authority');
    pc(strpos((string)file_get_contents($base.'/pkg-ok/lib/.htaccess'),'Require all denied')!==false,'private lib denied over HTTP');
    foreach(['readiness.php','evolution-readiness.php','cell-evolution-readiness-status.php'] as $p)pc(!is_file($base.'/pkg-ok/'.$p),'no public readiness endpoint '.$p);
    $cfg=require $base.'/pkg-ok/bootstrap.config.php';pc(in_array('perception.query',$cfg['capabilities']??[],true),'new daughter advertises perception query capability');
    $builder->destroy($base.'/pkg-ok');

    @unlink($src.'/CellEvolutionReadinessAdapter.php');
    $missingReadiness=$builder->build($base.'/pkg-missing-readiness',$prepared,$parent);
    pc(empty($missingReadiness['ok'])&&($missingReadiness['code']??'')==='EXPANSION_PACKAGE_SOURCE_MISSING'&&($missingReadiness['path']??'')==='CellEvolutionReadinessAdapter.php','missing readiness adapter fails build');
    pc(copy(__DIR__.'/CellEvolutionReadinessAdapter.php',$src.'/CellEvolutionReadinessAdapter.php'),'restore readiness adapter fixture');

    @unlink($src.'/CellWorldModel.php');
    $missingWorld=$builder->build($base.'/pkg-missing-world',$prepared,$parent);
    pc(empty($missingWorld['ok'])&&($missingWorld['code']??'')==='EXPANSION_PACKAGE_SOURCE_MISSING'&&($missingWorld['path']??'')==='CellWorldModel.php','missing world-model runtime fails build');
    pc(copy(__DIR__.'/CellWorldModel.php',$src.'/CellWorldModel.php'),'restore CellWorldModel fixture');

    @unlink($src.'/CellPerceptionAction.php');
    $missingPa=$builder->build($base.'/pkg-missing-pa',$prepared,$parent);
    pc(empty($missingPa['ok'])&&($missingPa['code']??'')==='EXPANSION_PACKAGE_SOURCE_MISSING'&&($missingPa['path']??'')==='CellPerceptionAction.php','missing perception/action runtime fails build');
    pc(copy(__DIR__.'/CellPerceptionAction.php',$src.'/CellPerceptionAction.php'),'restore CellPerceptionAction fixture');

    @unlink($src.'/CellLiving.php');
    $missingFile=$builder->build($base.'/pkg-missing-file',$prepared,$parent);
    pc(empty($missingFile['ok'])&&($missingFile['code']??'')==='EXPANSION_PACKAGE_SOURCE_MISSING'&&($missingFile['path']??'')==='CellLiving.php','missing intrinsic runtime fails build');
    pc(copy(__DIR__.'/CellLiving.php',$src.'/CellLiving.php'),'restore CellLiving fixture');

    $schemaPath=$src.'/cell-runtime/living-schema.json';
    $schema=json_decode((string)file_get_contents($schemaPath),true);pc(is_array($schema),'schema fixture');
    $schema['required_subsystems']=array_values(array_filter($schema['required_subsystems']??[],static fn($v):bool=>$v!=='perception'));
    file_put_contents($schemaPath,json_encode($schema,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    $missingSubsystem=$builder->build($base.'/pkg-missing-subsystem',$prepared,$parent);
    pc(empty($missingSubsystem['ok'])&&($missingSubsystem['code']??'')==='EXPANSION_PACKAGE_INTRINSIC_SUBSYSTEM_MISSING'&&($missingSubsystem['subsystem']??'')==='perception','missing intrinsic subsystem fails build');
    pc(!is_dir($base.'/pkg-missing-subsystem'),'failed package is removed');

    copy(__DIR__.'/cell-runtime/living-schema.json',$schemaPath);
    $schema=json_decode((string)file_get_contents($schemaPath),true);pc(is_array($schema),'world schema fixture');
    unset($schema['world_model']);
    file_put_contents($schemaPath,json_encode($schema,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    $missingWorldSchema=$builder->build($base.'/pkg-missing-world-schema',$prepared,$parent);
    pc(empty($missingWorldSchema['ok'])&&($missingWorldSchema['code']??'')==='EXPANSION_PACKAGE_WORLD_MODEL_MISSING','missing world-model declaration fails build');

    echo "KiCom Expansion package completeness selftest: PASS\n";
}finally{pcRm($base);}
