<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramAdminDbProvisionHttp.php';

$n=0;
function assert60(bool $ok,string $label):void{
 global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);
 $n++;echo "PASS $label\n";
}
function clean60(string $dir):void{
 if(is_link($dir)||is_file($dir)){@unlink($dir);return;}
 if(!is_dir($dir))return;
 foreach(scandir($dir) as $entry)if($entry!=='.'&&$entry!=='..')clean60($dir.'/'.$entry);
 @rmdir($dir);
}
$root=sys_get_temp_dir().'/mirage-admin-db-'.bin2hex(random_bytes(8));
$web=$root.'/web';$p=$root.'/engram-private';$data=$p.'/data';
mkdir($root,0700);mkdir($web,0755);mkdir($p,0700);
foreach(['data','backups','owners']as $name)mkdir($p.'/'.$name,0700);
$host=$p.'/engram-host.json';$owner=$p.'/owners/engram-owners.json';
$memory=$data.'/engrams.sqlite';
try{
 $config=['schema'=>1,'enabled'=>false,'operator_approved'=>false,
   'host_isolation_verified'=>false,'review_enabled'=>false,
   'runtime_source'=>'setup-pending','private_memory_scope'=>'setup-pending',
   'web_root'=>$web,'data_dir'=>$data,'owner_registry'=>$owner];
 file_put_contents($host,json_encode($config,JSON_THROW_ON_ERROR));chmod($host,0600);
 file_put_contents($owner,'{"schema":1,"owners":{}}');chmod($owner,0600);
 file_put_contents($memory,'SYNTHETIC EXISTING ENGRAM: LEAVE INTACT');chmod($memory,0600);
 $hashes=[$host=>hash_file('sha256',$host),$owner=>hash_file('sha256',$owner),
  $memory=>hash_file('sha256',$memory)];
 $sessionId=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(24));
 $session=['admin'=>true,'csrf'=>$csrf];
 $query=['engram_db_setup'=>'1'];
 $get=['REQUEST_METHOD'=>'GET','HTTPS'=>'on','HTTP_HOST'=>'kicom.rurtalbahn.info'];
 $post=$get;$post['REQUEST_METHOD']='POST';
 $post['HTTP_ORIGIN']='https://kicom.rurtalbahn.info';
 $form=['csrf'=>$csrf,'confirmation'=>'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN'];
 $handle=static fn(array $s,array $q,array $f,array $se):array=>
  KiComEngramAdminDbProvisionHttp::handle($s,$q,$f,$se,$sessionId,$csrf,$web);

 $page=$handle($get,$query,[],$session);
 assert60($page['http_status']===200&&str_contains($page['body'],'method="post"')
   &&str_contains($page['body'],'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN'),
   'original KiCom admin GET shows one explicit create-only action');
 assert60(!is_file($data.'/mirage-oauth.sqlite')
   &&!is_file($data.'/mirage-activation.sqlite'),
   'rendering admin page creates no OAuth or activation database');
 $denied=$handle($post,$query,$form,['admin'=>false,'csrf'=>$csrf]);
 assert60($denied['http_status']===404,'nonadmin POST has no provisioning authority');
 $bad=$post;$bad['HTTPS']='off';
 assert60($handle($bad,$query,$form,$session)['http_status']===404,
   'unencrypted provisioning POST is rejected');
 $bad=$post;$bad['HTTP_ORIGIN']='https://foreign.invalid';
 assert60($handle($bad,$query,$form,$session)['http_status']===403,
   'foreign-origin POST is rejected before any database write');
 $badForm=$form;$badForm['csrf']=str_repeat('0',strlen($csrf));
 assert60($handle($post,$query,$badForm,$session)['http_status']===403,
   'actual KiCom admin session CSRF is mandatory');
 $badForm=$form;$badForm['host_isolation_verified']='true';
 assert60($handle($post,$query,$badForm,$session)['http_status']===403,
   'client-supplied runtime flags rejected without side effects');
 $badForm=$form;$badForm['confirmation']='ja';
 assert60($handle($post,$query,$badForm,$session)['http_status']===409,
   'full exact human authorization phrase is required');
 assert60(!is_file($data.'/mirage-oauth.sqlite')
   &&!is_file($data.'/mirage-activation.sqlite'),
   'all unauthorized requests leave private storage unchanged');

 $done=$handle($post,$query,$form,$session);
 assert60($done['http_status']===200
   &&str_contains($done['body'],'Beide privaten Datenbankschemata sind vorbereitet.'),
   'authenticated original admin POST prepares missing schemas only');
 $adb=new PDO('sqlite:'.$data.'/mirage-activation.sqlite');
 $odb=new PDO('sqlite:'.$data.'/mirage-oauth.sqlite');
 assert60($adb->query('SELECT state FROM activation_state WHERE singleton=1')->fetchColumn()==='inactive',
   'new original activation schema remains inactive');
 assert60((int)$odb->query('SELECT COUNT(*) FROM mirage_oauth_tokens')->fetchColumn()===0
   &&(int)$odb->query('SELECT COUNT(*) FROM mirage_oauth_codes')->fetchColumn()===0,
   'OAuth setup issued zero bearer tokens and zero authorization codes');
 unset($adb,$odb);
 assert60(array_reduce(array_keys($hashes),static fn($ok,$file)=>$ok&&hash_file('sha256',$file)===$hashes[$file],true),
   'preexisting synthetic memory, owner binding and private config remain byte-identical');
 $again=$handle($post,$query,$form,$session);
 assert60($again['http_status']===200,'repeat approved request performs safe create-only schema inspection');

 $realConfig=$config;$realConfig['enabled']=true;
 file_put_contents($host,json_encode($realConfig,JSON_THROW_ON_ERROR));chmod($host,0600);
 assert60($handle($post,$query,$form,$session)['http_status']===409,
   'already active actual private host cannot be overwritten by setup');
 assert60(hash_file('sha256',$memory)===$hashes[$memory],
   'failed active-host rerun leaves existing memory byte-identical');
 echo "KICOM_ENGRAM_ADMIN_DB_PROVISION_TESTS_PASSED=$n\n";
}finally{clean60($root);}
