<?php
declare(strict_types=1);
require_once __DIR__.'/../../../source/0.9.26-r3/modules/dev/PasskeyBridge.php';
require_once __DIR__.'/KiComEngramPrivateOwnerRegistry.php';
require_once __DIR__.'/KiComEngramOAuthPasskeyConsent.php';

$checks=0;
function ok52(bool $condition,string $name):void{
  global $checks;
  if(!$condition)throw new RuntimeException('FAIL '.$name);
  ++$checks;echo "PASS $name\n";
}
function reject52(callable $f,string $name):void{
  try{$f();}catch(RuntimeException $e){
    ok52($e->getMessage()==='MIRAGE_OAUTH_CONSENT_DENIED',$name);return;
  }
  throw new RuntimeException('FAIL '.$name);
}
function purge52(string $p):void{
  if(is_link($p)||is_file($p)){@unlink($p);return;}
  if(!is_dir($p))return;
  foreach(scandir($p) as $entry)if($entry!=='.'&&$entry!=='..')purge52($p.'/'.$entry);
  @rmdir($p);
}
$root=sys_get_temp_dir().'/mirage-oauth-webauthn-'.bin2hex(random_bytes(8));
mkdir($root,0700);mkdir($root.'/web',0755);
mkdir($root.'/private',0700);mkdir($root.'/private/passkeys',0700);
mkdir($root.'/private/owners',0700);
try{
  $now=time();
  $db=new PDO('sqlite::memory:');
  KiComEngramOAuthTransactions::install($db);
  $privateKey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
  $details=openssl_pkey_get_details($privateKey);
  $rawId=random_bytes(24);
  $id=KiComPasskeyBridge::b64uEncode($rawId);
  $cred=['credential_id'=>$id,'x'=>KiComPasskeyBridge::b64uEncode($details['ec']['x']),
    'y'=>KiComPasskeyBridge::b64uEncode($details['ec']['y']),
    'alg'=>-7,'curve'=>'P-256','sign_count'=>0,
    'user_id'=>KiComPasskeyBridge::b64uEncode(random_bytes(16)),'label'=>'SYNTHETIC CI'];
  $passkeyFile=$root.'/private/passkeys/credentials.json';
  file_put_contents($passkeyFile,json_encode(['schema'=>1,'credentials'=>[$cred]],JSON_THROW_ON_ERROR));
  chmod($passkeyFile,0600);
  $fingerprint=hash('sha256',$id);
  $owner=[
    'enabled'=>true,'credential_fingerprint'=>$fingerprint,'subject'=>'mirage-owner',
    'namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']];
  $registryFile=$root.'/private/owners/engram-owners.json';
  file_put_contents($registryFile,json_encode([
    'schema'=>1,'owners'=>[$fingerprint=>$owner]
  ],JSON_THROW_ON_ERROR));chmod($registryFile,0600);
  $registry=new KiComEngramPrivateOwnerRegistry($registryFile,$root.'/web');
  $bridge=new KiComPasskeyBridge(
    $root.'/private/passkeys','kicom.rurtalbahn.info','https://kicom.rurtalbahn.info','KiCom'
  );
  ok52(($bridge->ready()['ok']??null)===true,'exact original 0.9.31 KiCom PasskeyBridge ready for real signature checks');
  $sid=bin2hex(random_bytes(32));
  $csrf=bin2hex(random_bytes(24));
  $session=['admin'=>true,'csrf'=>$csrf];
  $client=['client_id'=>'https://chatgpt.com/oauth/client.json',
    'redirect_uri'=>'https://chatgpt.com/connector/oauth/callback',
    'connector_id'=>'mirage-oauth-dev52',
    'host_evidence_id'=>hash('sha256','synthetic-host')];
  $verifier=KiComPasskeyBridge::b64uEncode(random_bytes(48));
  $challenge=KiComPasskeyBridge::b64uEncode(hash('sha256',$verifier,true));
  $request=[
    'client_id'=>$client['client_id'],'redirect_uri'=>$client['redirect_uri'],
    'response_type'=>'code','scope'=>'engram.read',
    'resource'=>'https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP',
    'state'=>KiComPasskeyBridge::b64uEncode(random_bytes(24)),
    'code_challenge'=>$challenge,'code_challenge_method'=>'S256'
  ];
  $created=KiComEngramOAuthTransactions::begin($db,$request,$client,$sid,$now);
  $requestId=$created['request_id'];
  $review=KiComEngramOAuthPasskeyConsent::review(
    $db,$requestId,$session,$sid,$csrf,$csrf,true,$now
  );
  ok52($review['client_id']===$client['client_id']&&$review['scope']==='engram.read',
    'read-only client/scope review derives from server-stored OAuth request');
  ok52(!array_key_exists('code',$review)&&!array_key_exists('pkce_challenge',$review),
    'review exposes no authorization code or PKCE secret');
  reject52(fn()=>KiComEngramOAuthPasskeyConsent::review(
    $db,$requestId,$session,str_repeat('x',64),$csrf,$csrf,true,$now),
    'foreign admin session cannot inspect OAuth review');
  reject52(fn()=>KiComEngramOAuthPasskeyConsent::review(
    $db,$requestId,$session,$sid,$csrf,str_repeat('0',48),true,$now),
    'CSRF mismatch cannot inspect OAuth review');
  reject52(fn()=>KiComEngramOAuthPasskeyConsent::review(
    $db,$requestId,['admin'=>false,'csrf'=>$csrf],$sid,$csrf,$csrf,true,$now),
    'anonymous browser cannot inspect OAuth review');
  reject52(fn()=>KiComEngramOAuthPasskeyConsent::review(
    $db,$requestId,$session,$sid,$csrf,$csrf,false,$now),
    'HTTP consent cannot inspect the private OAuth request');
  $sign=static function(array $start,OpenSSLAsymmetricKey $key,string $raw,int $counter=1):array{
    $client=json_encode(['type'=>'webauthn.get',
      'challenge'=>$start['publicKey']['challenge'],
      'origin'=>'https://kicom.rurtalbahn.info'],JSON_THROW_ON_ERROR);
    $auth=hash('sha256','kicom.rurtalbahn.info',true).chr(0x05).pack('N',$counter);
    if(!openssl_sign($auth.hash('sha256',$client,true),$sig,$key,OPENSSL_ALGO_SHA256))
      throw new RuntimeException('synthetic P-256 signing failed');
    $credId=KiComPasskeyBridge::b64uEncode($raw);
    return ['id'=>$credId,'rawId'=>$credId,'response'=>[
      'clientDataJSON'=>KiComPasskeyBridge::b64uEncode($client),
      'authenticatorData'=>KiComPasskeyBridge::b64uEncode($auth),
      'signature'=>KiComPasskeyBridge::b64uEncode($sig)]];
  };
  $started=KiComEngramOAuthPasskeyConsent::begin(
    $db,$requestId,$session,$sid,$csrf,$csrf,true,$bridge,$now
  );
  ok52(($started['publicKey']['userVerification']??null)==='required'
    &&!empty($started['publicKey']['allowCredentials']),
    'original KiCom issues fresh user-verified registered-credential assertion options');
  $forged=$sign($started,$privateKey,$rawId);
  $forged['response']['signature']=KiComPasskeyBridge::b64uEncode(random_bytes(64));
  reject52(fn()=>KiComEngramOAuthPasskeyConsent::confirm(
    $db,$requestId,$session,$sid,$csrf,$csrf,true,$started['challenge_id'],
    $forged,true,$bridge,$registry,$now+1),
    'invalid P-256 signature cannot authorize OAuth client');
  reject52(fn()=>KiComEngramOAuthPasskeyConsent::confirm(
    $db,$requestId,$session,$sid,$csrf,$csrf,true,$started['challenge_id'],
    $sign($started,$privateKey,$rawId),true,$bridge,$registry,$now+1),
    'failed original signature burns the browser OAuth challenge');
  reject52(fn()=>KiComEngramOAuthTransactions::issueApprovedCode(
    $db,$requestId,$sid,$fingerprint,$now+1),
    'invalid signature never creates OAuth authorization code');
  $second=KiComEngramOAuthPasskeyConsent::begin(
    $db,$requestId,$session,$sid,$csrf,$csrf,true,$bridge,$now+2
  );
  reject52(fn()=>KiComEngramOAuthPasskeyConsent::confirm(
    $db,$requestId,$session,$sid,$csrf,$csrf,true,$second['challenge_id'],
    $sign($second,$privateKey,$rawId),false,$bridge,$registry,$now+3),
    'explicit refusal of read permission denies OAuth code');
  $third=KiComEngramOAuthPasskeyConsent::begin(
    $db,$requestId,$session,$sid,$csrf,$csrf,true,$bridge,$now+4
  );
  reject52(fn()=>KiComEngramOAuthPasskeyConsent::confirm(
    $db,$requestId,$session,str_repeat('q',64),$csrf,$csrf,true,$third['challenge_id'],
    $sign($third,$privateKey,$rawId),true,$bridge,$registry,$now+5),
    'other session cannot approve current OAuth browser challenge');
  // The foreign-session check burns the pending browser challenge as intended.
  $fourth=KiComEngramOAuthPasskeyConsent::begin(
    $db,$requestId,$session,$sid,$csrf,$csrf,true,$bridge,$now+6
  );
  $verified=KiComEngramOAuthPasskeyConsent::confirm(
    $db,$requestId,$session,$sid,$csrf,$csrf,true,$fourth['challenge_id'],
    $sign($fourth,$privateKey,$rawId),true,$bridge,$registry,$now+7
  );
  ok52(strlen($verified['code'])===43
    &&$verified['state']===$request['state']
    &&$verified['redirect_uri']===$client['redirect_uri'],
    'real original KiCom P-256 signature plus exact owner issues one pinned OAuth code');
  reject52(fn()=>KiComEngramOAuthPasskeyConsent::confirm(
    $db,$requestId,$session,$sid,$csrf,$csrf,true,$fourth['challenge_id'],
    $sign($fourth,$privateKey,$rawId),true,$bridge,$registry,$now+8),
    'same signed challenge cannot issue another code');
  reject52(fn()=>KiComEngramOAuthTransactions::issueApprovedCode(
    $db,$requestId,$sid,$fingerprint,$now+8),
    'original OAuth code cannot be emitted twice');
  $exchange=KiComEngramOAuthTransactions::exchange($db,[
    'grant_type'=>'authorization_code','code'=>$verified['code'],
    'code_verifier'=>$verifier,'redirect_uri'=>$client['redirect_uri'],
    'client_id'=>$client['client_id'],'resource'=>$request['resource']
  ],$client,$now+9);
  $identity=KiComEngramOAuthTransactions::verify($db,$exchange['access_token'],$now+10);
  ok52(($identity['authenticated']??null)===true
    &&$identity['credential_fingerprint']===$fingerprint
    &&$identity['owner_binding']===hash('sha256',"mirage-owner\0".$fingerprint)
    &&$identity['host_evidence_id']===$client['host_evidence_id'],
    'OAuth PKCE token resolves only original verified KiCom owner and host evidence');
  KiComEngramOAuthTransactions::revoke($db,$exchange['access_token']);
  ok52(KiComEngramOAuthTransactions::verify($db,$exchange['access_token'],$now+11)===null,
    'original verified-owner OAuth token revokes immediately');
  echo "KICOM_ENGRAM_OAUTH_SIGNED_PASSKEY_TESTS_PASSED=$checks\n";
}finally{
  unset($bridge,$registry,$db);
  purge52($root);
}
