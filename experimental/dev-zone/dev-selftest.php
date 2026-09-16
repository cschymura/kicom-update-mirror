<?php
declare(strict_types=1);
require_once __DIR__.'/DevSession.php';
require_once __DIR__.'/DevAuthAdapter.php';
require_once __DIR__.'/DevAuthFlow.php';

function dfail(string $m): never { fwrite(STDERR,"FAIL: $m\n"); exit(1); }
function dok(bool $v,string $m): void { if(!$v) dfail($m); echo "OK: $m\n"; }
function db(string $s): string { return KiComPasskeyBridge::b64uEncode($s); }
function dlen(int $major,int $len): string {
    if($len<24)return chr(($major<<5)|$len);
    if($len<256)return chr(($major<<5)|24).chr($len);
    if($len<65536)return chr(($major<<5)|25).pack('n',$len);
    return chr(($major<<5)|26).pack('N',$len);
}
function dt(string $s): string { return dlen(3,strlen($s)).$s; }
function dbytes(string $s): string { return dlen(2,strlen($s)).$s; }
function dint(int $n): string { return $n>=0?dlen(0,$n):dlen(1,-1-$n); }

$root=sys_get_temp_dir().'/kicom-dev-zone-'.bin2hex(random_bytes(4));
$sessions=new KiComDevSessionManager($root.'/sessions-store');
$r=$sessions->ready();
dok(($r['ok']??false)===true,'DEV session manager ready');
dok(($r['token_rotation']??true)===false,'DEV tokens do not rotate');
dok(($r['ttl']??0)===604800,'default absolute TTL is 7 days');
dok(($r['idle_ttl']??0)===86400,'default idle TTL is 24 hours');
dok(KiComDevSessionManager::capabilityDefined('workspace.write'),'workspace write allowed');
dok(!KiComDevSessionManager::capabilityDefined('deploy.production'),'production deploy forbidden');
dok(!KiComDevSessionManager::capabilityDefined('self_update.install'),'self-update forbidden');
dok(!KiComDevSessionManager::capabilityDefined('kernel.write'),'kernel forbidden');

$issued=$sessions->issue(['auth_method'=>'selftest','label'=>'Standalone DEV']);
dok(($issued['ok']??false)===true,'DEV session issued');
$sid=(string)$issued['session_id'];$tok=(string)$issued['token'];
$a1=$sessions->authenticate($sid,$tok,'workspace.write');
dok(($a1['ok']??false)===true,'DEV session authenticates for allowed capability');
dok(($a1['token_rotates']??true)===false,'successful request does not rotate token');
$a2=$sessions->authenticate($sid,$tok,'build.test');
dok(($a2['ok']??false)===true,'same token reusable for another DEV request');
$deny=$sessions->authenticate($sid,$tok,'deploy.production');
dok(($deny['ok']??true)===false&&($deny['code']??'')==='DEV_CAPABILITY_FORBIDDEN','production capability denied before token use');
$bad=$sessions->authenticate($sid,str_repeat('0',64),'workspace.read');
dok(($bad['ok']??true)===false&&($bad['code']??'')==='DEV_SESSION_TOKEN_REJECTED','wrong DEV token rejected');
$rev=$sessions->revoke($sid,'selftest');dok(($rev['ok']??false)===true,'DEV session revoked');
$after=$sessions->authenticate($sid,$tok,'workspace.read');dok(($after['ok']??true)===false,'revoked DEV session cannot authenticate');

$passkeys=new KiComPasskeyBridge($root.'/passkeys');
dok(($passkeys->ready()['ok']??false)===true,'passkey bridge ready');
$pkey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
dok($pkey!==false,'P-256 key generated');
$details=openssl_pkey_get_details($pkey);$x=$details['ec']['x']??'';$y=$details['ec']['y']??'';
dok(is_string($x)&&strlen($x)===32&&is_string($y)&&strlen($y)===32,'P-256 coordinates available');
$credId=random_bytes(32);
$cose=chr(0xA5).dint(1).dint(2).dint(3).dint(-7).dint(-1).dint(1).dint(-2).dbytes($x).dint(-3).dbytes($y);
$en=$passkeys->createEnrollmentTicket('DEV selftest');dok(($en['ok']??false)===true,'passkey enrollment created');
$opts=$passkeys->registrationOptions((string)$en['enrollment_id']);$regChallenge=(string)$opts['publicKey']['challenge'];
$authReg=hash('sha256','kicom.rurtalbahn.info',true).chr(0x45).pack('N',0).str_repeat("\0",16).pack('n',strlen($credId)).$credId.$cose;
$att=chr(0xA3).dt('fmt').dt('none').dt('authData').dbytes($authReg).dt('attStmt').chr(0xA0);
$clientReg=json_encode(['type'=>'webauthn.create','challenge'=>$regChallenge,'origin'=>'https://kicom.rurtalbahn.info'],JSON_UNESCAPED_SLASHES);
$reg=['id'=>db($credId),'rawId'=>db($credId),'type'=>'public-key','response'=>['clientDataJSON'=>db((string)$clientReg),'attestationObject'=>db($att)]];
$rr=$passkeys->completeRegistration((string)$en['enrollment_id'],$reg,'DEV key');dok(($rr['ok']??false)===true,'passkey enrolled');

$adapter=new KiComDevAuthAdapter($passkeys,$sessions,$root.'/locks');
$flow=new KiComDevAuthFlow($passkeys,$adapter);
$challenge=$flow->begin();dok(($challenge['ok']??false)===true&&($challenge['flow']??'')==='same-origin-dev','same-origin passkey DEV challenge created');
$cid=(string)$challenge['challenge_id'];
$ao=$flow->options($cid);$webChallenge=(string)$ao['publicKey']['challenge'];
$clientGet=json_encode(['type'=>'webauthn.get','challenge'=>$webChallenge,'origin'=>'https://kicom.rurtalbahn.info'],JSON_UNESCAPED_SLASHES);
$authData=hash('sha256','kicom.rurtalbahn.info',true).chr(0x05).pack('N',1);
$signed=$authData.hash('sha256',(string)$clientGet,true);$sig='';dok(openssl_sign($signed,$sig,$pkey,OPENSSL_ALGO_SHA256)===true,'assertion signed');
$assertion=['id'=>db($credId),'rawId'=>db($credId),'type'=>'public-key','response'=>['clientDataJSON'=>db((string)$clientGet),'authenticatorData'=>db($authData),'signature'=>db($sig),'userHandle'=>null]];
$dev=$flow->complete($cid,$assertion,'Passkey DEV');
dok(($dev['ok']??false)===true&&($dev['scope']??'')==='dev','passkey issues DEV-scoped session');
dok(($dev['token_rotates']??true)===false,'passkey DEV session is non-rotating');
$devAuth=$sessions->authenticate((string)$dev['session_id'],(string)$dev['token'],'workspace.write');
dok(($devAuth['ok']??false)===true,'passkey DEV credential usable for workspace development');
$replay=$flow->complete($cid,$assertion,'Replay');
dok(($replay['ok']??true)===false,'same passkey challenge cannot mint a second DEV session');

echo "DEV ZONE SELFTEST PASS\n";
