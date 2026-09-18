<?php
declare(strict_types=1);
require_once __DIR__.'/ExpansionManagedCellUpdater.php';
function chk(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL $m\n");exit(1);}}
function rr(string $d):void{if(!is_dir($d))return;$i=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($i as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($d);}
function writeNode(string $var,string $baseUrl='https://child.example/kicom'):array{
    $node=['schema'=>1,'state'=>'active','cell_id'=>'cell-'.str_repeat('a',24),'root_id'=>'cell-'.str_repeat('b',24),'parent_id'=>'cell-'.str_repeat('b',24),'generation'=>1,'public_key'=>'test-public-key','base_url'=>$baseUrl,'parent_base_url'=>'https://parent.example/kicom','capabilities'=>['federation.tick','status.report'],'created_at'=>gmdate('c'),'activated_at'=>gmdate('c')];
    file_put_contents($var.'/node.json',json_encode($node,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    file_put_contents($var.'/signing.secret',random_bytes(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES));
    return $node;
}
function thinManifest(string $cell,array $files):void{$rows=[];foreach($files as $rel)$rows[]=['path'=>$rel,'bytes'=>filesize($cell.'/'.$rel),'sha256'=>hash_file('sha256',$cell.'/'.$rel)];file_put_contents($cell.'/cell-manifest.json',json_encode(['schema'=>1,'files'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");}

$base=sys_get_temp_dir().'/kicom-managed-updater-'.bin2hex(random_bytes(5));@mkdir($base,0700,true);
try{
    $u=new KiComExpansionManagedCellUpdater();

    // Single-file federation repair remains hash-bound and rollbackable.
    $web=$base.'/repair-web';$cell=$web.'/kicom';$var=$cell.'/var';$src=$base.'/source.php';@mkdir($var,0700,true);
    $status="<?php echo 'status';\n";$old="<?php\ndeclare(strict_types=1);\necho 'old';\n";$new="<?php\ndeclare(strict_types=1);\necho 'new';\n";
    file_put_contents($cell.'/status.php',$status);file_put_contents($cell.'/federation.php',$old);writeNode($var);thinManifest($cell,['status.php','federation.php']);file_put_contents($src,$new);
    $before=hash('sha256',$old);$after=hash('sha256',$new);
    $bad=$u->replaceFederationEndpoint($web,$src,str_repeat('0',64),'https://child.example/kicom');chk(empty($bad['ok'])&&($bad['code']??'')==='EXPANSION_REPAIR_HASH_CONFLICT','hash conflict');
    $r=$u->replaceFederationEndpoint($web,$src,$before,'https://child.example/kicom');chk(!empty($r['ok'])&&hash_file('sha256',$cell.'/federation.php')===$after,'repair update');
    $rb=$u->rollbackFederationEndpoint($web,$r);chk(!empty($rb['ok'])&&hash_file('sha256',$cell.'/federation.php')===$before,'repair rollback');

    // Thin active child -> current Living with PA memory and world model.
    $web2=$base.'/upgrade-web';$cell2=$web2.'/kicom';$var2=$cell2.'/var';@mkdir($var2,0700,true);
    $thinStatus="<?php echo 'thin-status';\n";$thinFederation="<?php echo 'thin-federation';\n";file_put_contents($cell2.'/status.php',$thinStatus);file_put_contents($cell2.'/federation.php',$thinFederation);
    $node=writeNode($var2);$secretBefore=file_get_contents($var2.'/signing.secret');$nodeBefore=file_get_contents($var2.'/node.json');thinManifest($cell2,['status.php','federation.php']);$manifestBefore=file_get_contents($cell2.'/cell-manifest.json');
    $up=$u->upgradeLivingRuntime($web2,__DIR__,'https://child.example/kicom');
    chk(!empty($up['ok'])&&($up['code']??'')==='EXPANSION_LIVING_UPGRADE_APPLIED','thin living upgrade');
    chk(!empty($up['living']['living_ready'])&&!empty($up['living']['perception_action']['ready']),'PA ready');
    chk(!empty($up['world_model_ready'])&&!empty($up['world_model']['ready']),'world model ready');
    chk(is_file($cell2.'/lib/CellLiving.php')&&is_file($cell2.'/lib/CellPerceptionAction.php')&&is_file($cell2.'/lib/CellWorldModel.php'),'living+PA+world runtime copied');
    chk(is_file($var2.'/living/perception/current.json')&&is_file($var2.'/living/perception/history.jsonl')&&is_file($var2.'/living/action/model.json')&&is_file($var2.'/living/action/history.jsonl'),'PA memory materialized');
    chk(is_file($var2.'/living/perception/world.json')&&is_file($var2.'/living/perception/world-history.jsonl')&&is_file($var2.'/living/perception/peer-memory.json')&&is_file($var2.'/living/action/possibilities.json'),'world model materialized');
    chk(file_get_contents($var2.'/signing.secret')===$secretBefore&&file_get_contents($var2.'/node.json')===$nodeBefore,'identity preserved');
    $manifest=json_decode((string)file_get_contents($cell2.'/cell-manifest.json'),true);chk(is_array($manifest)&&($manifest['schema']??0)===4&&($manifest['perception_action_memory']??false)===true&&($manifest['world_model']??false)===true,'manifest v4 world model');

    // Existing Living -> changed managed runtime. Mutate the source fixture in place
    // so require_once continues to resolve the same class path in this PHP process.
    $sourceStatus=__DIR__.'/cell-runtime/status.php';$sourceStatusOriginal=(string)file_get_contents($sourceStatus);
    $genomeBefore=json_decode((string)file_get_contents($var2.'/living/genome/genome.json'),true);chk(is_array($genomeBefore),'genome before rebaseline');
    $pBefore=count(file($var2.'/living/perception/history.jsonl',FILE_IGNORE_NEW_LINES)?:[]);$aBefore=count(file($var2.'/living/action/history.jsonl',FILE_IGNORE_NEW_LINES)?:[]);$wBefore=count(file($var2.'/living/perception/world-history.jsonl',FILE_IGNORE_NEW_LINES)?:[]);
    file_put_contents($sourceStatus,$sourceStatusOriginal."\n// managed world-model upgrade fixture\n");
    try{$second=$u->upgradeLivingRuntime($web2,__DIR__,'https://child.example/kicom');}finally{file_put_contents($sourceStatus,$sourceStatusOriginal);}
    chk(!empty($second['ok'])&&($second['code']??'')==='EXPANSION_LIVING_WORLD_UPGRADE_APPLIED','existing living world upgrade');
    chk(is_string($second['snapshot_id']??null)&&is_dir($var2.'/living_snapshots/'.$second['snapshot_id']),'prior living snapshot retained');
    chk(!empty($second['world_model_ready'])&&!empty($second['world_model']['ready']),'world remains ready after rebaseline');
    $genomeAfter=json_decode((string)file_get_contents($var2.'/living/genome/genome.json'),true);chk(is_array($genomeAfter)&&($genomeAfter['previous_id']??'')===($genomeBefore['id']??''),'genome predecessor linked');
    chk(count(file($var2.'/living/perception/history.jsonl',FILE_IGNORE_NEW_LINES)?:[])>$pBefore,'perception history extended');
    chk(count(file($var2.'/living/action/history.jsonl',FILE_IGNORE_NEW_LINES)?:[])>$aBefore,'action history extended');
    chk(count(file($var2.'/living/perception/world-history.jsonl',FILE_IGNORE_NEW_LINES)?:[])>$wBefore,'world history extended');
    chk(file_get_contents($var2.'/signing.secret')===$secretBefore&&file_get_contents($var2.'/node.json')===$nodeBefore,'identity survives rebaseline');

    $rbSecond=$u->rollbackLivingRuntime($web2,$second);chk(!empty($rbSecond['ok']),'world upgrade rollback');
    chk(is_dir($var2.'/living_snapshots/'.$second['snapshot_id']),'source snapshot retained after rollback');
    chk(is_string($rbSecond['failed_living_archive']??null)&&is_dir((string)$rbSecond['failed_living_archive']),'failed new living archived');
    $restored=json_decode((string)file_get_contents($var2.'/living/genome/genome.json'),true);chk(is_array($restored)&&($restored['id']??'')===($genomeBefore['id']??''),'old living restored');

    // Roll original thin->living transition back; no history is hard-deleted.
    $rb2=$u->rollbackLivingRuntime($web2,$up);chk(!empty($rb2['ok']),'thin living rollback');
    chk(file_get_contents($cell2.'/status.php')===$thinStatus&&file_get_contents($cell2.'/federation.php')===$thinFederation,'thin runtime restored');
    chk(!is_dir($var2.'/living')&&is_string($rb2['failed_living_archive']??null)&&is_dir((string)$rb2['failed_living_archive']),'new living archived off active path');
    chk(file_get_contents($cell2.'/cell-manifest.json')===$manifestBefore,'old manifest restored');

    // Drift and foreign targets remain fail-closed.
    file_put_contents($cell2.'/status.php',"<?php echo 'drift';\n");$drift=$u->upgradeLivingRuntime($web2,__DIR__,'https://child.example/kicom');chk(empty($drift['ok'])&&($drift['code']??'')==='EXPANSION_UPGRADE_MANAGED_DRIFT','managed drift rejected');
    file_put_contents($var.'/node.json',json_encode(['state'=>'active','cell_id'=>'cell-'.str_repeat('a',24),'parent_id'=>'cell-'.str_repeat('b',24),'base_url'=>'https://evil.example/kicom']));$mismatch=$u->replaceFederationEndpoint($web,$src,$before,'https://child.example/kicom');chk(empty($mismatch['ok'])&&($mismatch['code']??'')==='EXPANSION_REPAIR_CELL_BASE_MISMATCH','base mismatch');
    rr($cell);@mkdir($cell,0700,true);file_put_contents($cell.'/federation.php',$old);$foreign=$u->replaceFederationEndpoint($web,$src,$before,'https://child.example/kicom');chk(empty($foreign['ok'])&&($foreign['code']??'')==='EXPANSION_REPAIR_TARGET_UNMANAGED','foreign refused');

    echo "KiCom Managed Cell Updater selftest: PASS\n";
}finally{rr($base);}