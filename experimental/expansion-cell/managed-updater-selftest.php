<?php
declare(strict_types=1);
require_once __DIR__.'/ExpansionManagedCellUpdater.php';
function chk(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL $m\n");exit(1);}}
function rr(string $d):void{if(!is_dir($d))return;$i=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($i as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($d);}
function writeNode(string $var,string $baseUrl='https://child.example/kicom'): array {
    $node=[
        'schema'=>1,'state'=>'active','cell_id'=>'cell-'.str_repeat('a',24),
        'root_id'=>'cell-'.str_repeat('b',24),'parent_id'=>'cell-'.str_repeat('b',24),'generation'=>1,
        'public_key'=>'test-public-key','base_url'=>$baseUrl,'capabilities'=>['federation.tick','status.report'],
        'created_at'=>gmdate('c'),'activated_at'=>gmdate('c'),
    ];
    file_put_contents($var.'/node.json',json_encode($node,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    file_put_contents($var.'/signing.secret',random_bytes(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES));
    return $node;
}
function thinManifest(string $cell,array $files): void {
    $rows=[];foreach($files as $rel){$rows[]=['path'=>$rel,'bytes'=>filesize($cell.'/'.$rel),'sha256'=>hash_file('sha256',$cell.'/'.$rel)];}
    file_put_contents($cell.'/cell-manifest.json',json_encode(['schema'=>1,'files'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
}

$base=sys_get_temp_dir().'/kicom-managed-updater-'.bin2hex(random_bytes(5));
@mkdir($base,0700,true);
try{
    // Existing single-file federation repair remains hash-bound and rollbackable.
    $web=$base.'/repair-web';$cell=$web.'/kicom';$var=$cell.'/var';$src=$base.'/source.php';
    @mkdir($var,0700,true);
    $status="<?php echo 'status';\n";$old="<?php\ndeclare(strict_types=1);\necho 'old';\n";$new="<?php\ndeclare(strict_types=1);\necho 'new';\n";
    file_put_contents($cell.'/status.php',$status);file_put_contents($cell.'/federation.php',$old);writeNode($var);thinManifest($cell,['status.php','federation.php']);file_put_contents($src,$new);
    $u=new KiComExpansionManagedCellUpdater();$before=hash('sha256',$old);$after=hash('sha256',$new);
    $bad=$u->replaceFederationEndpoint($web,$src,str_repeat('0',64),'https://child.example/kicom');
    chk(empty($bad['ok'])&&($bad['code']??'')==='EXPANSION_REPAIR_HASH_CONFLICT','hash conflict');
    chk(hash_file('sha256',$cell.'/federation.php')===$before,'hash conflict leaves target');
    $r=$u->replaceFederationEndpoint($web,$src,$before,'https://child.example/kicom');
    chk(!empty($r['ok'])&&($r['code']??'')==='EXPANSION_REPAIR_FILE_UPDATED','update');
    chk(($r['after_sha256']??'')===$after&&hash_file('sha256',$cell.'/federation.php')===$after,'new hash');
    $idem=$u->replaceFederationEndpoint($web,$src,$before,'https://child.example/kicom');
    chk(!empty($idem['ok'])&&($idem['code']??'')==='EXPANSION_REPAIR_ALREADY_CURRENT','idempotent');
    $rb=$u->rollbackFederationEndpoint($web,$r);
    chk(!empty($rb['ok'])&&hash_file('sha256',$cell.'/federation.php')===$before,'rollback');

    // Thin active child -> complete Living child, preserving identity/state.
    $web2=$base.'/upgrade-web';$cell2=$web2.'/kicom';$var2=$cell2.'/var';@mkdir($var2,0700,true);
    $thinStatus="<?php echo 'thin-status';\n";$thinFederation="<?php echo 'thin-federation';\n";
    file_put_contents($cell2.'/status.php',$thinStatus);file_put_contents($cell2.'/federation.php',$thinFederation);
    $node=writeNode($var2);$secretBefore=file_get_contents($var2.'/signing.secret');$nodeBefore=file_get_contents($var2.'/node.json');
    thinManifest($cell2,['status.php','federation.php']);$manifestBefore=file_get_contents($cell2.'/cell-manifest.json');

    $up=$u->upgradeLivingRuntime($web2,__DIR__,'https://child.example/kicom');
    chk(!empty($up['ok'])&&($up['code']??'')==='EXPANSION_LIVING_UPGRADE_APPLIED','living upgrade applied');
    chk(!empty($up['living']['living_ready']),'living ready');
    chk(is_file($cell2.'/lib/CellLiving.php')&&is_file($cell2.'/doctor.php')&&is_file($cell2.'/living-schema.json'),'living runtime copied');
    chk(is_dir($var2.'/living/memory')&&is_dir($var2.'/living/workspace')&&is_dir($var2.'/living/genome/lkg')&&is_dir($var2.'/living/evolution/candidates'),'intrinsic tree exists');
    chk(file_get_contents($var2.'/signing.secret')===$secretBefore,'signing identity preserved');
    chk(file_get_contents($var2.'/node.json')===$nodeBefore,'node lineage preserved');
    $newManifest=json_decode((string)file_get_contents($cell2.'/cell-manifest.json'),true);
    chk(is_array($newManifest)&&($newManifest['schema']??0)===2&&($newManifest['mutable_state']??'')==='var-preserved','upgrade manifest');

    $rb2=$u->rollbackLivingRuntime($web2,$up);
    chk(!empty($rb2['ok']),'living rollback');
    chk(file_get_contents($cell2.'/status.php')===$thinStatus&&file_get_contents($cell2.'/federation.php')===$thinFederation,'old runtime restored');
    chk(!is_file($cell2.'/lib/CellLiving.php')&&!is_file($cell2.'/doctor.php')&&!is_dir($var2.'/living'),'new living files/state removed on rollback');
    chk(file_get_contents($cell2.'/cell-manifest.json')===$manifestBefore,'old manifest restored');
    chk(file_get_contents($var2.'/signing.secret')===$secretBefore&&file_get_contents($var2.'/node.json')===$nodeBefore,'identity survives rollback');

    // A managed-file drift blocks the multi-file upgrade before any write.
    file_put_contents($cell2.'/status.php',"<?php echo 'drift';\n");
    $drift=$u->upgradeLivingRuntime($web2,__DIR__,'https://child.example/kicom');
    chk(empty($drift['ok'])&&($drift['code']??'')==='EXPANSION_UPGRADE_MANAGED_DRIFT','managed drift rejected');
    chk(!is_file($cell2.'/lib/CellLiving.php')&&!is_dir($var2.'/living'),'drift rejection writes nothing');

    // Base-url mismatch and foreign targets remain rejected.
    file_put_contents($var.'/node.json',json_encode(['state'=>'active','cell_id'=>'cell-'.str_repeat('a',24),'parent_id'=>'cell-'.str_repeat('b',24),'base_url'=>'https://evil.example/kicom']));
    $mismatch=$u->replaceFederationEndpoint($web,$src,$before,'https://child.example/kicom');
    chk(empty($mismatch['ok'])&&($mismatch['code']??'')==='EXPANSION_REPAIR_CELL_BASE_MISMATCH','base mismatch');
    rr($cell);@mkdir($cell,0700,true);file_put_contents($cell.'/federation.php',$old);
    $foreign=$u->replaceFederationEndpoint($web,$src,$before,'https://child.example/kicom');
    chk(empty($foreign['ok'])&&($foreign['code']??'')==='EXPANSION_REPAIR_TARGET_UNMANAGED','foreign target refused');

    echo "KiCom Managed Cell Updater selftest: PASS\n";
}finally{rr($base);}
