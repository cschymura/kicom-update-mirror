<?php
declare(strict_types=1);
require_once __DIR__.'/../../../source/0.9.26-r3/modules/dev/PasskeyBridge.php';
require_once __DIR__.'/KiComEngramSharedHostAdminConfig.php';
require_once __DIR__.'/KiComEngramSignedOwnerActivation.php';
require_once __DIR__.'/KiComEngramStore.php';
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
 $store=new KiComEngramStore($private.'/data',$web);
 $stored=$store->create('mirage-owner','project','technical',
   'SYNTHETIC memory for independent connector test','synthetic_test','dev64');
 unset($store);
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
 $anon=['admin'=>false,'csrf'=>$csrf];
 deny63(function()use(&$anon,$web,$sid,$csrf,$passkeys,$now){
  KiComEngramSharedHostAdminConfig::begin(
   $web,$anon,$sid,$csrf,$csrf,true,$passkeys,$now);
 },'anonymous admin cannot begin shared-host policy attestation');
 deny63(fn()=>KiComEngramSharedHostAdminConfig::begin(
  $web,$session,$sid,$csrf,str_repeat('0',48),true,$passkeys,$now),
  'foreign CSRF cannot begin operator policy attestation');
 deny63(fn()=>KiComEngramSharedHostAdminConfig::begin(
  $web,$session,$sid,$csrf,$csrf,false,$passkeys,$now),
  'unencrypted HTTP cannot initiate original owner policy attestation');
 ok63(hash_file('sha256',$config)===$originalCfg,
  'GET-equivalent challenge preparation never mutates existing protected config');
 $start=KiComEngramSharedHostAdminConfig::begin(
  $web,$session,$sid,$csrf,$csrf,true,$passkeys,$now
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
   $web,$session,$sid,$csrf,$csrf,true,$start['confirmation'],
   $start['challenge_id'],$invalid,$passkeys,$now+1);
 },'invalid P-256 signature denied without changing private config');
 ok63(hash_file('sha256',$config)===$originalCfg,
  'failed original passkey signature preserves original config byte-for-byte');
 deny63(function()use(&$session,$web,$sid,$csrf,$start,$sign,$key,$rawId,$passkeys,$now){
  KiComEngramSharedHostAdminConfig::confirm(
   $web,$session,$sid,$csrf,$csrf,true,$start['confirmation'],
   $start['challenge_id'],$sign($start,$key,$rawId,1),$passkeys,$now+1);
 },'failed signature consumes browser challenge; no replay');
 $start=KiComEngramSharedHostAdminConfig::begin($web,$session,$sid,$csrf,$csrf,true,$passkeys,$now+2);
 deny63(function()use(&$session,$web,$sid,$csrf,$start,$sign,$key,$rawId,$passkeys,$now){
  KiComEngramSharedHostAdminConfig::confirm(
   $web,$session,$sid,$csrf,$csrf,true,'SOMETHING ELSE',
   $start['challenge_id'],$sign($start,$key,$rawId,1),$passkeys,$now+3);
 },'explicit exact operator acceptance cannot be inferred from arbitrary confirmation');
 ok63(hash_file('sha256',$config)===$originalCfg,
  'refusal leaves exact original setup-pending config unchanged');
 $start=KiComEngramSharedHostAdminConfig::begin($web,$session,$sid,$csrf,$csrf,true,$passkeys,$now+4);
 deny63(function()use(&$session,$web,$sid,$csrf,$start,$sign,$key,$rawId,$passkeys,$now){
  KiComEngramSharedHostAdminConfig::confirm(
   $web,$session,$sid,$csrf,$csrf,false,$start['confirmation'],
   $start['challenge_id'],$sign($start,$key,$rawId,1),$passkeys,$now+5);
 },'unencrypted HTTP cannot confirm signed owner policy attestation');
 // Test REAL first-party KiCom admin HTTP facade, not just internal class.
 require_once __DIR__.'/KiComEngramSharedHostAdminHttp.php';
 $get=['HTTPS'=>'on','HTTP_HOST'=>'kicom.rurtalbahn.info','REQUEST_METHOD'=>'GET'];
 $post=['HTTPS'=>'on','HTTP_HOST'=>'kicom.rurtalbahn.info','REQUEST_METHOD'=>'POST',
  'CONTENT_TYPE'=>'application/json','HTTP_ORIGIN'=>'https://kicom.rurtalbahn.info'];
 $call=static function(array $srv,string $raw)use(&$session,$sid,$csrf,$web,$passkeys,$now):array{
  return KiComEngramSharedHostAdminHttp::handle(
    $srv,$raw,$session,$sid,$csrf,$web,$passkeys,$now+4
  );
 };
 $anonymous=['admin'=>false,'csrf'=>$csrf];
 ok63(KiComEngramSharedHostAdminHttp::handle(
    $get,'',$anonymous,$sid,$csrf,$web,$passkeys,$now+4)['http_status']===404,
  'anonymous original admin cannot view signed private-host policy page');
 $insecure=$get;$insecure['HTTPS']='off';
 ok63($call($insecure,'')['http_status']===404,
  'first-party hosting approval unavailable over HTTP');
 $page=$call($get,'');
 ok63($page['http_status']===200
  &&str_contains($page['body'],'id="mirage-host-policy"')
  &&str_contains($page['body'],'assets/mirage-host-policy-client.js')
  &&str_contains($page['body'],'nicht technisch isoliert'),
  'admin page visibly discloses shared-host risk and separate original Passkey consent');
 ok63(hash_file('sha256',$config)===$originalCfg,
  'read-only original admin consent page does not change private host JSON');
 $wire=static fn(array $data):string=>json_encode($data,JSON_THROW_ON_ERROR);
 $beginWire=$wire(['step'=>'begin','csrf'=>$csrf]);
 $foreign=$post;$foreign['HTTP_ORIGIN']='https://attacker.invalid';
 ok63($call($foreign,$beginWire)['http_status']===404,
  'foreign browser origin cannot start owner policy challenge');
 ok63($call($post,$wire(['step'=>'begin','csrf'=>str_repeat('0',48)]))['http_status']===403,
  'first-party signed operator approval requires original KiCom session CSRF');
 $begun=$call($post,$beginWire);
 $start=json_decode($begun['body'],true,16,JSON_THROW_ON_ERROR);
 ok63($begun['http_status']===200&&($start['ok']??null)===true
  &&($start['publicKey']['userVerification']??null)==='required',
  'first-party admin issues fresh original registered-owner Passkey challenge');
 $signed=$sign($start,$key,$rawId,1);
 $confirmation=['step'=>'confirm','csrf'=>$csrf,
  'challenge_id'=>$start['challenge_id'],
  'confirmation'=>$start['confirmation'],'assertion'=>$signed];
 $injected=$confirmation;$injected['owner']='forged-owner';
 ok63($call($post,$wire($injected))['http_status']===403,
  'first-party admin rejects browser-injected owner fields before signature verification');
 $done=$call($post,$wire($confirmation));
 $approved=json_decode($done['body'],true,16,JSON_THROW_ON_ERROR);
 ok63($done['http_status']===200&&($approved['ok']??null)===true,
  'original signed WebAuthn and operator acceptance commit via real admin JSON facade');
 ok63($call($post,$wire($confirmation))['http_status']===403,
  'replayed signed first-party browser approval cannot alter existing host policy');
 $backups=glob($private.'/backups/engram-host-before-shared-*.json')?:[];
 ok63(count($backups)===1
  &&hash_file('sha256',$backups[0])===$originalCfg
  &&(fileperms($backups[0])&0077)===0,
  'atomic policy promotion retains one original private 0600 rollback snapshot');
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
  $web,$session,$sid,$csrf,$csrf,true,$passkeys,$now+6),
  'already reviewed host config is not silently rewritten');
 deny63(function()use(&$session,$web,$sid,$csrf,$start,$signed,$passkeys,$now){
  KiComEngramSharedHostAdminConfig::confirm(
   $web,$session,$sid,$csrf,$csrf,true,$start['confirmation'],
   $start['challenge_id'],$signed,$passkeys,$now+6);
 },'already used browser attestation cannot mutate reviewed host policy');
 function deny64(callable $action,string $label):void{
  try{$action();}catch(RuntimeException $e){
    ok63($e->getMessage()==='MIRAGE_ENGRAM_ACTIVATION_DENIED',$label);
    return;
  }
  throw new RuntimeException('FAIL '.$label);
 }

 // DEV-64 owner-signed production-mode activation is explicitly separate
 // from the already signed and INACTIVE DEV-63 hosting policy.
 $actFile=$private.'/data/mirage-activation.sqlite';
 $oauthFile=$private.'/data/mirage-oauth.sqlite';
 $act=new PDO('sqlite:'.$actFile);
 $act->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 ok63(KiComEngramActivationTransaction::state($act)==='inactive',
   'original private activation schema initialized inactive');
 $oauth=new PDO('sqlite:'.$oauthFile);
 $oauth->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 KiComEngramOAuthTransactions::install($oauth);
 unset($act,$oauth);
 chmod($actFile,0600);chmod($oauthFile,0600);
 $cfgBeforeActivation=hash_file('sha256',$config);
 $memoryBeforeActivation=hash_file('sha256',$legacy);
 deny64(fn()=>KiComEngramSignedOwnerActivation::begin(
    $web,$session,$sid,$csrf,$csrf,false,$passkeys,$now+8),
   'private memory activation refuses unencrypted HTTP');
 $noAdmin=['admin'=>false,'csrf'=>$csrf];
 deny64(fn()=>KiComEngramSignedOwnerActivation::begin(
    $web,$noAdmin,$sid,$csrf,$csrf,true,$passkeys,$now+8),
   'private memory activation refuses anonymous KiCom session');
 // Different denial prefix for this independent signed activation.

 $started=KiComEngramSignedOwnerActivation::begin(
   $web,$session,$sid,$csrf,$csrf,true,$passkeys,$now+8);
 ok63(($started['publicKey']['userVerification']??null)==='required'
   &&$started['will_enable_oauth_and_read_only_mcp']===true
   &&$started['will_store_new_personal_memories']===false,
   'independent signed owner-activation challenge is explicit and read-only for MCP');
 ok63(hash_file('sha256',$config)===$cfgBeforeActivation
   &&hash_file('sha256',$legacy)===$memoryBeforeActivation,
   'activation challenge did not write private host config or memory');
 $invalidProof=$sign($started,$key,$rawId,2);
 $invalidProof['response']['signature']=KiComPasskeyBridge::b64uEncode(random_bytes(64));
 deny64(function()use(&$session,$web,$sid,$csrf,$started,$invalidProof,$passkeys,$now){
   KiComEngramSignedOwnerActivation::confirm(
      $web,$session,$sid,$csrf,$csrf,true,$started['confirmation'],
      $started['challenge_id'],$invalidProof,$passkeys,$now+9);
 },'invalid original P-256 signature cannot activate private Engram');
 $act=new PDO('sqlite:'.$actFile);
 ok63($act->query('SELECT state FROM activation_state WHERE singleton=1')->fetchColumn()==='inactive',
   'failed WebAuthn approval cannot change private activation DB');
 unset($act);
 deny64(function()use(&$session,$web,$sid,$csrf,$started,$sign,$key,$rawId,$passkeys,$now){
   KiComEngramSignedOwnerActivation::confirm(
      $web,$session,$sid,$csrf,$csrf,true,$started['confirmation'],
      $started['challenge_id'],$sign($started,$key,$rawId,2),$passkeys,$now+9);
 },'failed signature consumes one-use browser activation challenge');

 $started=KiComEngramSignedOwnerActivation::begin(
   $web,$session,$sid,$csrf,$csrf,true,$passkeys,$now+10);
 deny64(function()use(&$session,$web,$sid,$csrf,$started,$sign,$key,$rawId,$passkeys,$now){
   KiComEngramSignedOwnerActivation::confirm(
      $web,$session,$sid,$csrf,$csrf,true,'WRONG CONFIRMATION',
      $started['challenge_id'],$sign($started,$key,$rawId,2),$passkeys,$now+11);
 },'hosting acceptance alone cannot substitute for separate explicit memory activation');
 ok63(hash_file('sha256',$config)===$cfgBeforeActivation,
   'refused memory activation retains disabled original host configuration');

 // Prove the signed activation reaches the actual first-party admin JSON
 // handler; not just an isolated class invoked by the test runner.
 require_once __DIR__.'/KiComEngramOwnerActivationHttp.php';
 $get=['HTTPS'=>'on','HTTP_HOST'=>'kicom.rurtalbahn.info','REQUEST_METHOD'=>'GET'];
 $post=['HTTPS'=>'on','HTTP_HOST'=>'kicom.rurtalbahn.info',
   'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json',
   'HTTP_ORIGIN'=>'https://kicom.rurtalbahn.info'];
 $invoke=static function(array $srv,string $wire)use(
     &$session,$sid,$csrf,$web,$passkeys,$now
 ):array{
     return KiComEngramOwnerActivationHttp::handle(
       $srv,$wire,$session,$sid,$csrf,$web,$passkeys,$now+12
     );
 };
 $anonymous=['admin'=>false,'csrf'=>$csrf];
 ok63(KiComEngramOwnerActivationHttp::handle(
   $get,'',$anonymous,$sid,$csrf,$web,$passkeys,$now+12)['http_status']===404,
   'anonymous browser cannot reach live-equivalent owner-activation page');
 $http=$get;$http['HTTPS']='off';
 ok63($invoke($http,'')['http_status']===404,
   'unencrypted HTTP cannot load first-party owner activation');
 $page=$invoke($get,'');
 ok63($page['http_status']===200
   &&str_contains($page['body'],'id="mirage-activation"')
   &&str_contains($page['body'],'mirage-owner-activation-client.js')
   &&str_contains($page['body'],'eigenständige Freigabe'),
   'authenticated original KiCom admin shows separate activation and Passkey consent');
 $json=static fn(array $obj):string=>json_encode($obj,JSON_THROW_ON_ERROR);
 $request=['step'=>'begin','csrf'=>$csrf];
 $foreign=$post;$foreign['HTTP_ORIGIN']='https://attacker.invalid';
 ok63($invoke($foreign,$json($request))['http_status']===404,
   'foreign origin cannot begin private owner activation');
 ok63($invoke($post,$json(['step'=>'begin','csrf'=>str_repeat('0',48)]))['http_status']===403,
   'owner activation requires original KiCom PHP session CSRF');
 $begun=$invoke($post,$json($request));
 $started=json_decode($begun['body'],true,16,JSON_THROW_ON_ERROR);
 ok63($begun['http_status']===200
   &&($started['ok']??null)===true
   &&($started['publicKey']['userVerification']??null)==='required',
   'first-party JSON challenge is a fresh registered original KiCom Passkey request');
 $goodProof=$sign($started,$key,$rawId,2);
 $confirm=['step'=>'confirm','csrf'=>$csrf,
   'confirmation'=>$started['confirmation'],
   'challenge_id'=>$started['challenge_id'],'assertion'=>$goodProof];
 $extra=$confirm;$extra['owner_binding']='forged-browser-identity';
 ok63($invoke($post,$json($extra))['http_status']===403,
   'request-provided owner-binding cannot authorize activation');
 $done=$invoke($post,$json($confirm));
 $result=json_decode($done['body'],true,16,JSON_THROW_ON_ERROR);
 ok63($done['http_status']===200 &&($result['ok']??null)===true,
   'real first-party original KiCom signed owner approval activates private OAuth+MCP');
 ok63($result['ok']===true
    &&$result['code']==='PRIVATE_ENGRAM_OAUTH_MCP_READ_ACTIVATED'
    &&$result['memory_read_enabled']===true
    &&$result['memory_write_enabled']===false,
   'distinct genuine KiCom owner signature activates intended private read-only MCP');
 $activeCfg=json_decode(file_get_contents($config),true,24,JSON_THROW_ON_ERROR);
 unset($activeCfg['schema']);
 ok63(KiComEngramHostingPolicy::permits($activeCfg)
    &&KiComEngramOAuthHttp::available($activeCfg)
    &&$activeCfg['host_isolation_verified']===false,
   'resulting server-owned shared-host policy really enables OAuth after signed approval');
 $act=new PDO('sqlite:'.$actFile);
 $state=$act->query('SELECT state,owner_binding,host_evidence_id
     FROM activation_state WHERE singleton=1')->fetch(PDO::FETCH_ASSOC);
 ok63(($state['state']??null)==='active'
   &&($state['owner_binding']??null)===$binding
   &&($state['host_evidence_id']??null)===$activeCfg['host_evidence_id'],
   'private activation DB is atomically bound to exact original owner and host policy');
 unset($act);
 $backupList=glob($private.'/backups/engram-host-before-activation-*.json')?:[];
 ok63(count($backupList)===1
   &&hash_file('sha256',$backupList[0])===$cfgBeforeActivation
   &&(fileperms($backupList[0])&0077)===0,
   'private 0600 backup preserves previous disabled host configuration for recovery');
 ok63(hash_file('sha256',$legacy)===$memoryBeforeActivation
   &&hash_file('sha256',$registry)===$originalOwner,
   'activating OAuth/MCP never changes existing private memory or owner registry');
 ok63($invoke($post,$json($confirm))['http_status']===403,
   'one-use signed browser activation cannot be replayed through real admin HTTP');
 deny64(fn()=>KiComEngramSignedOwnerActivation::begin(
    $web,$session,$sid,$csrf,$csrf,true,$passkeys,$now+14),
   'already activated host cannot silently reenter activation wizard');
 // DEV-64 final synthetic operational proof with THREE separate authentic
 // original KiCom signings: host policy, memory activation and per-client
 // OAuth consent. The resulting short-lived bearer must retrieve the
 // EXISTING memory created before either server activation.
 require_once __DIR__.'/KiComEngramOAuthPasskeyConsent.php';
 require_once __DIR__.'/KiComEngramOAuthMcpHostBridge.php';
 $oauthDb=new PDO('sqlite:'.$oauthFile,null,null,[
   PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
 ]);
 $trustedClient=$activeCfg['oauth_client'];
 $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
 $codeChallenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
 $params=[
   'client_id'=>$trustedClient['client_id'],
   'redirect_uri'=>$trustedClient['redirect_uri'],
   'response_type'=>'code',
   'scope'=>'engram.read',
   'resource'=>KiComEngramOAuthHttp::RESOURCE,
   'state'=>KiComPasskeyBridge::b64uEncode(random_bytes(24)),
   'code_challenge'=>$codeChallenge,'code_challenge_method'=>'S256'
 ];
 $request=KiComEngramOAuthTransactions::begin(
   $oauthDb,$params,$trustedClient,$sid,$now+15
 );
 ok63(strlen($request['request_id']??'')===43,
   'fresh exact pinned ChatGPT public-client OAuth request waits for separate signed owner consent');
 $owners=new KiComEngramPrivateOwnerRegistry($registry,$web);
 $oauthChallenge=KiComEngramOAuthPasskeyConsent::begin(
   $oauthDb,$request['request_id'],$session,$sid,$csrf,$csrf,
   true,$passkeys,$now+16
 );
 ok63(($oauthChallenge['publicKey']['userVerification']??null)==='required',
   'per-client OAuth memory read consent still requires fresh ORIGINAL KiCom registered Passkey');
 $oauthAssertion=$sign($oauthChallenge,$key,$rawId,3);
 $code=KiComEngramOAuthPasskeyConsent::confirm(
   $oauthDb,$request['request_id'],$session,$sid,$csrf,$csrf,
   true,$oauthChallenge['challenge_id'],$oauthAssertion,true,
   $passkeys,$owners,$now+17
 );
 ok63(strlen($code['code']??'')===43
    &&$code['redirect_uri']===$trustedClient['redirect_uri'],
   'separate signed original owner approves only exact pinned client, scope and callback');
 $exchange=[
   'grant_type'=>'authorization_code','code'=>$code['code'],
   'code_verifier'=>$verifier,
   'redirect_uri'=>$trustedClient['redirect_uri'],
   'client_id'=>$trustedClient['client_id'],
   'resource'=>KiComEngramOAuthHttp::RESOURCE
 ];
 $token=KiComEngramOAuthTransactions::exchange(
   $oauthDb,$exchange,$trustedClient,$now+18
 );
 ok63(strlen($token['access_token']??'')===43
   &&$token['scope']==='engram.read',
   'approved real original KiCom WebAuthn code exchanges into owner-scoped read-only OAuth token');
 $http=[
   'HTTPS'=>'on','HTTP_HOST'=>'kicom.rurtalbahn.info',
   'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json',
   'HTTP_ACCEPT'=>'application/json, text/event-stream',
   'HTTP_MCP_PROTOCOL_VERSION'=>'2025-06-18',
   'HTTP_AUTHORIZATION'=>'Bearer '.$token['access_token']
 ];
 $rpc=static fn(int $id,string $method,array $args=[]):string=>
   json_encode(['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$args],
     JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 $handle=static fn(array $requestHttp,string $wire,array $trustedRuntime):array=>
   KiComEngramOAuthMcpHostBridge::handle(
     $requestHttp,$wire,$trustedRuntime,$web,$now+19
   );
 $search=$rpc(41,'tools/call',[
   'name'=>'engram_search','arguments'=>[
     'query'=>'SYNTHETIC memory for independent connector test','limit'=>2
   ]
 ]);
 $recalled=$handle($http,$search,$activeCfg);
 $mcp=json_decode($recalled['body'],true,24,JSON_THROW_ON_ERROR);
 $returned=json_decode($mcp['result']['content'][0]['text'],true,24,JSON_THROW_ON_ERROR);
 ok63($recalled['http_status']===200
   &&($mcp['result']['isError']??null)===false
   &&count($returned)===1
   &&($returned[0]['body']??null)==='SYNTHETIC memory for independent connector test',
   'FULL SIGNED HOST + SIGNED ACTIVATION + SIGNED OAUTH -> real private MCP SQLite memory recall');
 ok63($returned[0]['source_kind']==='synthetic_test'
    &&!isset($returned[0]['owner_binding'])
    &&!isset($returned[0]['host_evidence_id']),
   'read-only MCP search returns only artificial memory projection, never private configuration');
 $unauthorized=$http;unset($unauthorized['HTTP_AUTHORIZATION']);
 ok63($handle($unauthorized,$search,$activeCfg)['http_status']===401,
   'missing bearer still demands OAuth even after separately signed owner activation');
 $off=$activeCfg;$off['oauth_enabled']=false;
 ok63($handle($http,$search,$off)['http_status']===404,
   'operator can disable OAuth regardless of a previously signed access token');
 KiComEngramOAuthTransactions::revoke($oauthDb,$token['access_token']);
 ok63($handle($http,$search,$activeCfg)['http_status']===401,
   'revoked short-lived OAuth token immediately loses private memory access');
 unset($oauthDb,$owners);
 echo "KICOM_ENGRAM_SIGNED_OWNER_ACTIVATION_TESTS_PASSED=$checks\n";
}finally{
 unset($passkeys,$key);
 clean63($base);
}
