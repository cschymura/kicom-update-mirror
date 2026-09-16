<?php
declare(strict_types=1);
require_once __DIR__.'/ExpansionManagedCellUpdater.php';
function chk(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL $m\n");exit(1);}}
function rr(string $d):void{if(!is_dir($d))return;$i=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($i as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($d);}
$base=sys_get_temp_dir().'/kicom-managed-updater-'.bin2hex(random_bytes(5));
$web=$base.'/web';$cell=$web.'/kicom';$var=$cell.'/var';$src=$base.'/source.php';
@mkdir($var,0700,true);
try{
 file_put_contents($cell.'/cell-manifest.json',"{}\n");
 file_put_contents($cell.'/status.php',"<?php echo 'status';\n");
 $old="<?php\ndeclare(strict_types=1);\necho 'old';\n";
 $new="<?php\ndeclare(strict_types=1);\necho 'new';\n";
 file_put_contents($cell.'/federation.php',$old);
 file_put_contents($src,$new);
 file_put_contents($var.'/node.json',json_encode(['state'=>'active','cell_id'=>'cell-'.str_repeat('a',24),'base_url'=>'https://child.example/kicom'],JSON_PRETTY_PRINT));
 $u=new KiComExpansionManagedCellUpdater();
 $before=hash('sha256',$old);$after=hash('sha256',$new);
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
 file_put_contents($var.'/node.json',json_encode(['state'=>'active','cell_id'=>'cell-'.str_repeat('a',24),'base_url'=>'https://evil.example/kicom']));
 $mismatch=$u->replaceFederationEndpoint($web,$src,$before,'https://child.example/kicom');
 chk(empty($mismatch['ok'])&&($mismatch['code']??'')==='EXPANSION_REPAIR_CELL_BASE_MISMATCH','base mismatch');
 rr($cell);@mkdir($cell,0700,true);file_put_contents($cell.'/federation.php',$old);
 $foreign=$u->replaceFederationEndpoint($web,$src,$before,'https://child.example/kicom');
 chk(empty($foreign['ok'])&&($foreign['code']??'')==='EXPANSION_REPAIR_TARGET_UNMANAGED','foreign target refused');
 echo "KiCom Managed Cell Updater selftest: PASS\n";
}finally{rr($base);}
