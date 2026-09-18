<?php
declare(strict_types=1);
require_once __DIR__.'/CellEvolutionReadinessAdapter.php';

$rc=new ReflectionClass(KiComCellEvolutionReadinessAdapter::class);
$public=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter($rc->getMethods(ReflectionMethod::IS_PUBLIC),static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===KiComCellEvolutionReadinessAdapter::class));
sort($public);
if($public!==['__construct','diagnose']){fwrite(STDERR,"unexpected public API\n");exit(1);}
$src=file_get_contents(__DIR__.'/CellEvolutionReadinessAdapter.php');
if(!is_string($src)){exit(2);}
foreach(['curl_','file_put_contents','unlink(','rename(','copy(','chmod(','mkdir(','header(','setcookie','session_start'] as $forbidden){
    if(stripos($src,$forbidden)!==false){fwrite(STDERR,"forbidden mutating/network primitive: $forbidden\n");exit(3);}
}
if(stripos($src,"promotion_authority'=>false")===false||stripos($src,"promotion_performed'=>false")===false||stripos($src,"authority_changed'=>false")===false){fwrite(STDERR,"fail-closed authority flags missing\n");exit(4);}
echo "READINESS_ADAPTER_SELFTEST_OK\n";
