<?php
declare(strict_types=1);
require_once __DIR__.'/../../../source/0.9.26-r3/modules/dev/PasskeyBridge.php';
require_once __DIR__.'/KiComEngramSharedHostAdminConfig.php';
$checks=0;
function ok63(bool $ok,string $name):void{
 global $checks;
 if(!$ok)throw new RuntimeException('FAIL '.$name);
 ++$checks; echo "PASS ".$name."\n";
}
function deny63(callable $f,string $name):void{
 try{$f();}catch(RuntimeException $e){
  ok63($e->getMessage()==='MIRAGE_HOST_POLICY_DENIED',$name);return;
 }
 throw new RuntimeException('FAIL '.$name);
}
function clean63(string $p):void{
 if(is_link($p)||is_file($p)){@unlink($p);return;}
 if(!is_dir($p))return;
 foreach(scandir($p) as $n)if($n!=='.'&&$n!=='..')clean63($p.'/'.$n);
 @rmdir($p);
}
$base=sys_get_temp_dir().'/mirage-host-policy-'.bin2hex(random_bytes(8));
$web=$base.'/kicom';$private=$base.'/engram-private';
$registry=$private.'/owners/engram-owners.json';
$config=$private.'/engram-host.json';
$legacy=$private.'/data/engrams.sqlite';
mkdir($base,0700);mkdir($web,0755);mkdir($private,0700);
foreach(['data','backups','owners']as $dir)mkdir($private.'/'.$dir,0700);
mkdir($web.'/var',0700);mkdir($web.'/var/dev_zone',0700);
mkdir($web.'/var/dev_zone/passkeys',0700);
file_put_contents($web.'/lib.php','<?php');file_put_contents($web.'/admin.php','<?php');
try{
 $now=time();$sid=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(24));
 $session=['admin'=>true,'csrf'=>$csrf];
 $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
 $ec=openssl_pkey_get_details($key);
 $rawId=random_bytes(24);$credentialId=KiComPasskeyBridge::b64uEncode($rawId);
 $fp=hash('sha256',$credentialId);
 $binding=hash('sha256',"mirage-owner\0".$fp);
 $cred=[
  'credential_id'=>$credentialId,
  'x'=>KiComPasskeyBridge::b64uEncode($ec['ec']['x']),
  'y'=>KiComPasskeyBridge::b64uEncode($ec['ec']['y']),
  'alg'=>-7,'curve'=>'P-256','sign_count'=>0,
  'user_id'=>KiComPasskeyBridge::b64uEncode(random_bytes(16)),
  'label'=>'CI SYNTHETIC'
 ];
 $passkeyFile=$web.'/var/dev_zone/passkeys/credentials.json';
 file_put_contents($passkeyFile,json_encode([
  'schema'=>1,'credentials'=>[$cred]
 ],JSON_THROW_ON_ERROR));chmod($passkeyFile,0600);
 $owner=['enabled'=>true,'credential_fingerprint'=>$fp,
  'subject'=>'mirage-owner','namespaces'=>['project'],
  'engram_rights'=>['engram.read']];
 file_put_contents($registry,json_encode([
  'schema'=>1,'owners'=>[$fp=>$owner]
 ],JSON_THROW_ON_ERROR));chmod($registry,0600);
 $initial=[
  'schema'=>1,'enabled'=>false,'operator_approved'=>false,
  'review_enabled'=>false,'host_isolation_verified'=>false,
  'runtime_source'=>'setup-pending','private_memory_scope'=>'setup-pending',
  'web_root'=>$web,'reviewed_web_roots'=>[],
  'data_dir'=>$private.'/data','backups_dir'=>$private.'/backups',
  'owner_registry'=>$registry,'consent_dir'=>$private.'/consent',
  'review_dir'=>$private.'/review','stepup_dir'=>$private.'/stepup',
  'dev_session_root'=>$web.'/var/dev_zone/sessions',
  'passkey_store'=>$web.'/var/dev_zone/passkeys',
  'admin_subject'=>'','rp_id'=>'kicom.rurtalbahn.info',
  'expected_origin'=>'https://kicom.rurtalbahn.info'
 ];
 file_put_contents($config,json_encode($initial,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
 chmod($config,0600);
 file_put_contents($legacy,'SYNTHETIC PREEXISTING USER MEMORY. NEVER MODIFY.');
 chmod($legacy,0600);
 $originalCfg=hash_file('sha256',$config);
 $originalOwner=hash_file('sha256',$registry);
 $originalMemory=hash_file('sha256',$legacy);
 $passkeys=new KiComPasskeyBridge(
  $web.'/var/dev_zone/passkeys',
  'kicom.rurtalbahn.info','https://kicom.rurtalbahn.info','KiCom'
 );
 ok63(($passkeys->ready()['ok']??null)===true,
  'original KiCom WebAuthn P-256 PasskeyBridge ready');
 deny63(fn()=>KiComEngramSharedHostAdminConfig::begin(
  $web,['admin'=>false,'csrf'=>$csrf],$sid,$csrf,$csrf,$passkeys,$now),
  'anonymous admin cannot begin shared-host policy attestation');
 deny63(fn()=>KiComEngramSharedHostAdminConfig::begin(
  $web,$session,$sid,$csrf,str_repeat('0',48),$passkeys,$now),
  'foreign CSRF cannot begin operator policy attestation');
 ok63(hash_file('sha256',$config)===$originalCfg,
  'GET-equivalent challenge preparation never mutates existing protected config');
 $start=KiComEngramSharedHostAdminConfig::begin(
  $web,$session,$sid,$csrf,$csrf,$passkeys,$now
 );
 ok63(($start['publicKey']['userVerification']??null)==='required'
  &&!empty($start['publicKey']['allowCredentials'])
  &&$start['will_activate_memory']===false,
  'original registered passkey challenge is required and starts disabled');
 $sign=static function(array $challenge,OpenSSLAsymmetricKey $key,string $id,int $counter):array{
  $data=json_encode(['type'=>'webauthn.get',
   'challenge'=>$challenge['publicKey']['challenge'],
   'origin'=>'https://kicom.rurtalbahn.info'],JSON_THROW_ON_ERROR);
  $auth=hash('sha256','kicom.rurtalbahn.info',true).chr(0x05).pack('N',$counter);
  if(!openssl_sign($auth.hash('sha256',$data,true),$sig,$key,OPENSSL_ALGO_SHA256))
   throw new RuntimeException('SIGN_FAILED');
  $id64=KiComPasskeyBridge::b64uEncode($id);
  return ['id'=>$id64,'rawId'=>$id64,'response'=>[
    'clientDataJSON'=>KiComPasskeyBridge::b64uEncode($data),
    'authenticatorData'=>KiComPasskeyBridge::b64uEncode($auth),
    'signature'=>KiComPasskeyBridge::b64uEncode($sig)
  ]];
 };
 $invalid=$sign($start,$key,$rawId,1);
 $invalid['response']['signature']=KiComPasskeyBridge::b64uEncode(random_bytes(64));
 deny63(function()use(&$session,$web,$sid,$csrf,$start,$invalid,$passkeys,$now){
  KiComEngramSharedHostAdminConfig::confirm(
   $web,$session,$sid,$csrf,$csrf,$start['confirmation'],
   $start['challenge_id'],$invalid,$passkeys,$now+1);
 },'invalid P-256 signature denied without changing private config');
 ok63(hash_file('sha256',$config)===$originalCfg,
  'failed original passkey signature preserves original config byte-for-byte');
 deny63(function()use(&$session,$web,$sid,$csrf,$start,$sign,$key,$rawId,$passkeys,$now){
  KiComEngramSharedHostAdminConfig::confirm(
   $web,$session,$sid,$csrf,$csrf,$start['confirmation'],
   $start['challenge_id'],$sign($start,$key,$rawId,1),$passkeys,$now+1);
 },'failed signature consumes browser challenge; no replay');
 $start=KiComEngramSharedHostAdminConfig::begin($web,$session,$sid,$csrf,$csrf,$passkeys,$now+2);
 deny63(function()use(&$session,$web,$sid,$csrf,$start,$sign,$key,$rawId,$passkeys,$now){
  KiComEngramSharedHostAdminConfig::confirm(
   $web,$session,$sid,$csrf,$csrf,'SOMETHING ELSE',
   $start['challenge_id'],$sign($start,$key,$rawId,1),$passkeys,$now+3);
 },'explicit exact operator acceptance cannot be inferred from arbitrary confirmation');
 ok63(hash_file('sha256',$config)===$originalCfg,
  'refusal leaves exact original setup-pending config unchanged');
 $start=KiComEngramSharedHostAdminConfig::begin($web,$session,$sid,$csrf,$csrf,$passkeys,$now+4);
 $signed=$sign($start,$key,$rawId,1);
 $approved=KiComEngramSharedHostAdminConfig::confirm(
  $web,$session,$sid,$csrf,$csrf,$start['confirmation'],
  $start['challenge_id'],$signed,$passkeys,$now+5
 );
 $new=json_decode(file_get_contents($config),true,32,JSON_THROW_ON_ERROR);
 ok63($approved['ok']===true
  &&$approved['code']==='SHARED_HOST_POLICY_PREPARED_INACTIVE',
  'original KiCom cryptographic WebAuthn signature commits only approved private policy');
 ok63($new['runtime_source']==='server-only-reviewed'
  &&$new['host_isolation_verified']===false
  &&$new['operator_accepts_shared_host_risk']===true
  &&$new['hosting_policy_known_limitation']==='shared-php-uid-not-verified',
  'explicit operator shared-host model is documented without false isolation');
 unset($new['schema']);
 ok63(KiComEngramHostingPolicy::permits($new)
  &&KiComEngramHostingPolicy::mode($new)==='shared_host_risk_explicitly_accepted_not_isolated',
  'new private runtime conforms to DEV-61 shared-host policy gate');
 ok63($new['enabled']===false&&$new['operator_approved']===false
  &&$new['oauth_enabled']===false&&$new['mcp_connector_enabled']===false
  &&$new['review_enabled']===false
  &&!KiComEngramOAuthHttp::available($new),
  'policy staging does not expose OAuth/MCP or activate private Engram');
 ok63($new['owner_binding']===$binding
  &&$new['oauth_client']['host_evidence_id']===$new['host_evidence_id']
  &&$new['oauth_client']['redirect_uri']===
    KiComEngramOAuthHttp::STABLE_CHATGPT_CALLBACK
  &&$new['oauth_client']['connector_id']===$new['mcp_connector_id'],
  'signed original current owner, server-derived host snapshot and stable callback are consistently pinned');
 ok63(hash_file('sha256',$registry)===$originalOwner
  &&hash_file('sha256',$legacy)===$originalMemory
  &&hash_file('sha256',$passkeyFile)!=='',
  'operator policy transition preserves prior owner registry and memory');
 deny63(fn()=>KiComEngramSharedHostAdminConfig::begin(
  $web,$session,$sid,$csrf,$csrf,$passkeys,$now+6),
  'already reviewed host config is not silently rewritten');
 deny63(function()use(&$session,$web,$sid,$csrf,$start,$signed,$passkeys,$now){
  KiComEngramSharedHostAdminConfig::confirm(
   $web,$session,$sid,$csrf,$csrf,$start['confirmation'],
   $start['challenge_id'],$signed,$passkeys,$now+6);
 },'already used browser attestation cannot mutate reviewed host policy');
 echo "KICOM_ENGRAM_SIGNED_SHARED_HOST_CONFIG_TESTS_PASSED=$checks\n";
}finally{
 unset($passkeys,$key);
 clean63($base);
}
