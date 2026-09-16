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
 $id=KiComExpansionProtocol::createIdentity('cell-'.str_repeat('a',24));$parent=['cell_id'=>$id['cell_id'],'root_id'=>$id['cell_id'],'generation'=>0,'public_key'=>$id['public_key'],'base_url'=>'https://parent.example/kicom'];
 $reg=new KiComExpansionRegistry($base.'/parent',$parent);$builder=new KiComExpansionCellPackageBuilder(__DIR__);$ftp=new KiComExpansionFtpDeployer();$o=new KiComExpansionOrchestrator($reg,$builder,$ftp,$parent,$id['secret_key'],$base.'/work');
 $x=$o->preparePackage('https://child.example','/htdocs',3600);chk(!empty($x['ok']),'prepare package');$prep=(array)$x['prepared'];$pkg=(array)$x['package'];chk(is_file($pkg['directory'].'/bootstrap.config.php'),'config exists');chk(is_file($pkg['directory'].'/federation.php'),'endpoint exists');
 $seed=require $pkg['directory'].'/bootstrap.config.php';$child=new KiComExpansionCellNode($base.'/child');$init=$child->initialize($seed);chk(!empty($init['ok']),'child init');
 $done=$o->activatePrepared($prep,new FakeTransport($child));chk(!empty($done['ok'])&&($done['code']??'')==='EXPANSION_ACTIVE','activation+tick');$s=$child->status();chk(($s['state']??'')==='active','child active');chk(count($reg->cells())===1,'registry cell');
 $builder->destroy((string)$pkg['directory']);chk(!is_dir((string)$pkg['directory']),'package destroy'); echo "KiCom Expansion Orchestrator selftest: PASS\n";
}finally{rr($base);}
