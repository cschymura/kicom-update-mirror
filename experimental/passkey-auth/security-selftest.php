<?php
declare(strict_types=1);
require_once __DIR__.'/PasskeyBridge.php';

function sfail(string $m): never { fwrite(STDERR,"FAIL: $m\n"); exit(1); }
function sok(bool $v,string $m): void { if(!$v) sfail($m); echo "OK: $m\n"; }
function sb(string $s): string { return KiComPasskeyBridge::b64uEncode($s); }
function scborLen(int $major,int $len): string {
    if($len<24)return chr(($major<<5)|$len);
    if($len<256)return chr(($major<<5)|24).chr($len);
    if($len<65536)return chr(($major<<5)|25).pack('n',$len);
    return chr(($major<<5)|26).pack('N',$len);
}
function scborText(string $s): string { return scborLen(3,strlen($s)).$s; }
function scborBytes(string $s): string { return scborLen(2,strlen($s)).$s; }
function scborInt(int $n): string { return $n>=0?scborLen(0,$n):scborLen(1,-1-$n); }
function assertionFor(string $challenge,string $origin,string $authData,$pkey,string $credId,bool $corrupt=false): array {
    $client=json_encode(['type'=>'webauthn.get','challenge'=>$challenge,'origin'=>$origin],JSON_UNESCAPED_SLASHES);
    if(!is_string($client))sfail('client data json');
    $signed=$authData.hash('sha256',$client,true);$sig='';
    if(!openssl_sign($signed,$sig,$pkey,OPENSSL_ALGO_SHA256))sfail('sign assertion');
    if($corrupt&&$sig!=='')$sig[0]=chr(ord($sig[0])^1);
    return [
        'id'=>sb($credId),'rawId'=>sb($credId),'type'=>'public-key',
        'response'=>[
            'clientDataJSON'=>sb($client),
            'authenticatorData'=>sb($authData),
            'signature'=>sb($sig),
            'userHandle'=>null,
        ],
    ];
}

$dir=sys_get_temp_dir().'/kicom-passkey-security-'.bin2hex(random_bytes(4));
$bridge=new KiComPasskeyBridge($dir);
sok(($bridge->ready()['ok']??false)===true,'bridge ready');
$pkey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
sok($pkey!==false,'P-256 key generated');
$d=openssl_pkey_get_details($pkey);$x=$d['ec']['x']??'';$y=$d['ec']['y']??'';
sok(is_string($x)&&strlen($x)===32&&is_string($y)&&strlen($y)===32,'P-256 coordinates available');
$credId=random_bytes(32);
$cose=chr(0xA5)
    .scborInt(1).scborInt(2)
    .scborInt(3).scborInt(-7)
    .scborInt(-1).scborInt(1)
    .scborInt(-2).scborBytes($x)
    .scborInt(-3).scborBytes($y);
$en=$bridge->createEnrollmentTicket('Security selftest');
sok(($en['ok']??false)===true,'enrollment created');
$opts=$bridge->registrationOptions((string)$en['enrollment_id']);$regChallenge=(string)$opts['publicKey']['challenge'];
$authReg=hash('sha256','kicom.rurtalbahn.info',true).chr(0x45).pack('N',0).str_repeat("\0",16).pack('n',strlen($credId)).$credId.$cose;
$att=chr(0xA3).scborText('fmt').scborText('none').scborText('authData').scborBytes($authReg).scborText('attStmt').chr(0xA0);
$badClient=json_encode(['type'=>'webauthn.create','challenge'=>$regChallenge,'origin'=>'https://evil.example'],JSON_UNESCAPED_SLASHES);
$badReg=['id'=>sb($credId),'rawId'=>sb($credId),'type'=>'public-key','response'=>['clientDataJSON'=>sb((string)$badClient),'attestationObject'=>sb($att)]];
$r=$bridge->completeRegistration((string)$en['enrollment_id'],$badReg,'bad');
sok(($r['ok']??true)===false&&($r['code']??'')==='ORIGIN_MISMATCH','registration rejects wrong origin');
$goodClient=json_encode(['type'=>'webauthn.create','challenge'=>$regChallenge,'origin'=>'https://kicom.rurtalbahn.info'],JSON_UNESCAPED_SLASHES);
$goodReg=['id'=>sb($credId),'rawId'=>sb($credId),'type'=>'public-key','response'=>['clientDataJSON'=>sb((string)$goodClient),'attestationObject'=>sb($att)]];
$r=$bridge->completeRegistration((string)$en['enrollment_id'],$goodReg,'Security key');
sok(($r['ok']??false)===true,'valid registration accepted');

$kp=sodium_crypto_box_keypair();$clientPub=sodium_crypto_box_publickey($kp);
$nextChallenge=function()use($bridge,$clientPub): array {
    $c=$bridge->createAuthChallenge(sb($clientPub));
    if(empty($c['ok']))sfail('challenge create');
    $o=$bridge->assertionOptions((string)$c['challenge_id']);
    if(empty($o['ok']))sfail('assertion options');
    return [(string)$c['challenge_id'],(string)$o['publicKey']['challenge']];
};

[$id,$challenge]=$nextChallenge();
$ad=hash('sha256','kicom.rurtalbahn.info',true).chr(0x05).pack('N',1);
$r=$bridge->verifyAssertion($id,assertionFor($challenge,'https://evil.example',$ad,$pkey,$credId));
sok(($r['ok']??true)===false&&($r['code']??'')==='ORIGIN_MISMATCH','assertion rejects wrong origin');

[$id,$challenge]=$nextChallenge();
$ad=hash('sha256','wrong.example',true).chr(0x05).pack('N',1);
$r=$bridge->verifyAssertion($id,assertionFor($challenge,'https://kicom.rurtalbahn.info',$ad,$pkey,$credId));
sok(($r['ok']??true)===false&&($r['code']??'')==='RP_ID_HASH_MISMATCH','assertion rejects wrong RP hash');

[$id,$challenge]=$nextChallenge();
$ad=hash('sha256','kicom.rurtalbahn.info',true).chr(0x01).pack('N',1);
$r=$bridge->verifyAssertion($id,assertionFor($challenge,'https://kicom.rurtalbahn.info',$ad,$pkey,$credId));
sok(($r['ok']??true)===false&&($r['code']??'')==='USER_VERIFICATION_REQUIRED','assertion requires user verification');

[$id,$challenge]=$nextChallenge();
$ad=hash('sha256','kicom.rurtalbahn.info',true).chr(0x05).pack('N',1);
$r=$bridge->verifyAssertion($id,assertionFor($challenge,'https://kicom.rurtalbahn.info',$ad,$pkey,$credId,true));
sok(($r['ok']??true)===false&&($r['code']??'')==='ASSERTION_SIGNATURE_INVALID','assertion rejects invalid signature');

[$id,$challenge]=$nextChallenge();
$ad=hash('sha256','kicom.rurtalbahn.info',true).chr(0x05).pack('N',1);
$r=$bridge->verifyAssertion($id,assertionFor($challenge,'https://kicom.rurtalbahn.info',$ad,$pkey,$credId));
sok(($r['ok']??false)===true,'valid counter 1 accepted');

[$id,$challenge]=$nextChallenge();
$ad=hash('sha256','kicom.rurtalbahn.info',true).chr(0x05).pack('N',1);
$r=$bridge->verifyAssertion($id,assertionFor($challenge,'https://kicom.rurtalbahn.info',$ad,$pkey,$credId));
sok(($r['ok']??true)===false&&($r['code']??'')==='SIGN_COUNTER_REPLAY','sign counter replay rejected');

echo "SECURITY SELFTEST PASS\n";
