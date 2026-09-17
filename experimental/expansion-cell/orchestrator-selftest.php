<?php
declare(strict_types=1);
require_once __DIR__.'/ExpansionProtocol.php';
require_once __DIR__.'/ExpansionRegistry.php';
require_once __DIR__.'/ExpansionFtpDeployer.php';
require_once __DIR__.'/ExpansionCronRelay.php';
require_once __DIR__.'/ExpansionCellPackageBuilder.php';
require_once __DIR__.'/ExpansionHttpTransport.php';
require_once __DIR__.'/ExpansionOrchestrator.php';
require_once __DIR__.'/CellNode.php';
function chk(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL $m\n");exit(1);}}
function rr(string $d):void{if(!is_dir($d))return;$i=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($i as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($d);}
final class FakeTransport implements KiComExpansionTransport{
    public KiComExpansionCellNode $node; public function __construct(KiComExpansionCellNode $n){$this->node=$n;}
    public function getJson(string $url):array{return $this->node->enrollmentHello();}
    public function postJson(string $url,array $body):array{ $s=$this->node->status(); if(($s['state']??'')==='enrolling')return $this->node->activate($body); return $this->node->handleParentMessage($body); }
}
$base=sys_get_temp_dir().'/kicom-orchestrator-'.bin2hex(random_bytes(5));@mkdir($base,0700,true);
try{
 $src=$base.'/source';@mkdir($src.'/cell-runtime',0700,true);
 foreach(['ExpansionProtocol.php','CellNode.php','CellLiving.php','CellPerceptionAction.php','CellWorldModel.php'] as $f) chk(copy(__DIR__.'/'.$f,$src.'/'.$f),'copy source '.$f);
 foreach(['common.php','bootstrap.php','federation.php','status.php','doctor.php','living-schema.json'] as $f) chk(copy(__DIR__.'/cell-runtime/'.$f,$src.'/cell-runtime/'.$f),'copy runtime '.$f);
 chk(!is_file($src.'/cell-runtime/.htaccess'),'source fixture intentionally has no dotfile');

 $id=KiComExpansionProtocol::createIdentity('cell-'.str_repeat('a',24));$parent=['cell_id'=>$id['cell_id'],'root_id'=>$id['cell_id'],'generation'=>0,'public_key'=>$id['public_key'],'base_url'=>'https://parent.example/kicom'];
 $reg=new KiComExpansionRegistry($base.'/parent',$parent);$builder=new KiComExpansionCellPackageBuilder($src);$ftp=new KiComExpansionFtpDeployer();$o=new KiComExpansionOrchestrator($reg,$builder,$ftp,$parent,$id['secret_key'],$base.'/work');
 $x=$o->preparePackage('https://child.example','/htdocs',3600);chk(!empty($x['ok']),'prepare package');$prep=(array)$x['prepared'];$pkg=(array)$x['package'];chk(!empty($pkg['intrinsic_complete']),'package intrinsic complete');chk(is_file($pkg['directory'].'/bootstrap.config.php'),'config exists');chk(is_file($pkg['directory'].'/federation.php'),'endpoint exists');chk(is_file($pkg['directory'].'/doctor.php'),'doctor exists');chk(is_file($pkg['directory'].'/lib/CellLiving.php'),'living runtime exists');chk(is_file($pkg['directory'].'/lib/CellPerceptionAction.php'),'perception/action runtime exists');chk(is_file($pkg['directory'].'/lib/CellWorldModel.php'),'world-model runtime exists');chk(is_file($pkg['directory'].'/living-schema.json'),'living schema exists');chk(is_file($pkg['directory'].'/.htaccess'),'child htaccess generated');chk(str_contains((string)file_get_contents($pkg['directory'].'/.htaccess'),'bootstrap\\.config\\.php'),'generated htaccess protects bootstrap config');
 $seed=require $pkg['directory'].'/bootstrap.config.php';chk(in_array('perception.query',$seed['capabilities']??[],true),'perception query capability packaged');$child=new KiComExpansionCellNode($pkg['directory'].'/var');$init=$child->initialize($seed);chk(!empty($init['ok']),'child init');chk(!empty($init['living_ready']),'living ready at birth');chk(!empty($init['perception_action_ready']),'perception/action ready at birth');chk(!empty($init['world_model_ready']),'world model ready at birth');$born=$child->status();chk(!empty($born['living_ready'])&&!empty($born['perception_action_ready'])&&!empty($born['world_model_ready']),'status proves complete birth before enrollment');
 $done=$o->activatePrepared($prep,new FakeTransport($child));chk(!empty($done['ok'])&&($done['code']??'')==='EXPANSION_ACTIVE','activation+tick');$s=$child->status();chk(($s['state']??'')==='active','child active');chk(!empty($s['living_ready'])&&!empty($s['perception_action_ready'])&&!empty($s['world_model_ready']),'child still living+PA+world ready');chk(count($reg->cells())===1,'registry cell');
 $builder->destroy((string)$pkg['directory']);chk(!is_dir((string)$pkg['directory']),'package destroy'); echo "KiCom Expansion Orchestrator selftest: PASS\n";
}finally{rr($base);}