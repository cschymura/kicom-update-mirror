<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramHostAudit.php';
$n=0;
function check45(bool $condition,string $label):void{global $n;if(!$condition)throw new RuntimeException('FAIL '.$label);$n++;echo "PASS $label\n";}
function walk45(string $p):void{if(is_link($p)||is_file($p)){unlink($p);return;}if(!is_dir($p))return;foreach(scandir($p) as $f)if($f!=='.'&&$f!=='..')walk45($p.'/'.$f);rmdir($p);}
$root=sys_get_temp_dir().'/kicom-host-audit-'.bin2hex(random_bytes(7));mkdir($root,0700);$web=$root.'/kiweb';mkdir($web,0755);$private=$root.'/engram-private';mkdir($private,0700);
try{
 $missing=KiComEngramHostAudit::inspect($web);
 check45($missing['code']==='HOST_EVIDENCE_INCOMPLETE_API_INACTIVE'&&$missing['private_root']==='restricted','incomplete status remains inactive even when root is protected');
 check45($missing['private_subdirs']==='missing_or_unprotected'&&$missing['sqlite_file']==='missing','missing scaffold reported without creating files');
 foreach(['data','backups','owners','consent','review','stepup'] as $name)mkdir($private.'/'.$name,0700);
 file_put_contents($private.'/engram-host.json','{}');chmod($private.'/engram-host.json',0600);
 file_put_contents($private.'/owners/engram-owners.json','{"schema":1}');chmod($private.'/owners/engram-owners.json',0600);
 file_put_contents($private.'/data/engrams.sqlite',"SQLite format 3\0SYNTHETIC");chmod($private.'/data/engrams.sqlite',0600);
 $before=scandir($private);$a=KiComEngramHostAudit::inspect($web);$after=scandir($private);
 check45($before===$after,'audit never creates or deletes files');
 check45($a['private_root']==='restricted'&&$a['private_subdirs']==='restricted','private directory modes checked');
 check45($a['host_config']==='restricted'&&$a['owner_registry']==='restricted','private config and owner registry modes checked without reading content');
 check45($a['sqlite_file']==='restricted','SQLite candidate file mode checked (not SQLite content integrity)');
 check45($a['personal_memory']==='not_assessed'&&$a['cross_app_isolation']==='not_verified'&&$a['backup_restore']==='not_verified','no false claim of activation, cross-app isolation or restore');
 check45(!str_contains(json_encode($a),$root)&&!str_contains(json_encode($a),'schema'),'report excludes private paths and config contents');
 $sibling=$root.'/other-app';mkdir($sibling,0755);
 check45(KiComEngramHostAudit::inspect($web)['sibling_application_access']==='readable_sibling_directory_detected','potential sibling application visibility flagged without exposing its name');
 chmod($private.'/owners/engram-owners.json',0644);
 check45(KiComEngramHostAudit::inspect($web)['owner_registry']==='missing_or_unprotected','world-readable owner registry rejected');
 chmod($private.'/owners/engram-owners.json',0600);
 chmod($private.'/data',0755);
 check45(KiComEngramHostAudit::inspect($web)['private_subdirs']==='missing_or_unprotected','group-readable private data directory rejected');
 chmod($private.'/data',0700);
 chmod($private.'/engram-host.json',0644);
 check45(KiComEngramHostAudit::inspect($web)['host_config']==='missing_or_unprotected','world-readable host config rejected');
 chmod($private.'/engram-host.json',0600);
 rename($private.'/data/engrams.sqlite',$private.'/data/real.sqlite');symlink($private.'/data/real.sqlite',$private.'/data/engrams.sqlite');
 check45(KiComEngramHostAudit::inspect($web)['sqlite_file']==='unprotected_or_unusable','symbolic SQLite link rejected');
 unlink($private.'/data/engrams.sqlite');rename($private.'/data/real.sqlite',$private.'/data/engrams.sqlite');
 chmod($private,0755);
 check45(KiComEngramHostAudit::inspect($web)['private_root']==='missing_or_unprotected','publicly readable private root rejected');
 chmod($private,0700);
 check45(KiComEngramHostAudit::inspect($root.'/absent')['webroot']==='unknown','unrecognized webroot never trusted');
 check45($a['vhost_alias_inventory']==='not_verified'&&$a['php_uid_separation']==='not_verified','alias and UID inventories not fabricated');
 echo "KICOM_ENGRAM_HOST_AUDIT_TESTS_PASSED=$n\n";
}finally{walk45($root);}
