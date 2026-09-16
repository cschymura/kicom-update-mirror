<?php
declare(strict_types=1);
require_once __DIR__.'/PasskeyBridge.php';
require_once __DIR__.'/RuntimeAdapter.php';

function fail(string $m): never { fwrite(STDERR,"FAIL: $m\n"); exit(1); }
function ok(bool $v,string $m): void { if(!$v) fail($m); echo "OK: $m\n"; }
function b(string $s): string { return KiComPasskeyBridge::b64uEncode($s); }
function cborLen(int $major,int $len): string {
    if($len<24)return chr(($major<<5)|$len);
    if($len<256)return chr(($major<<5)|24).chr($len);
    if($len<65536)return chr(($major<<5)|25).pack('n',$len);
    return chr(($major<<5)|26).pack('N',$len);
}
function cborText(string $s): string { return cborLen(3,strlen($s)).$s; }
function cborBytes(string $s): string { return cborLen(2,strlen($s)).$s; }
function cborInt(int $n): string {
    if($n>=0)return cborLen(0,$n);
    return cborLen(1,-1-$n);
}

$dir=sys_get_temp_dir().'/kicom-passkey-selftest-'.bin2hex(random_bytes(4));
$bridge=new KiComPasskeyBridge($dir);
$totpCalls=0;$mintCalls=0;
$adapter=new KiComPasskeyRuntimeAdapter(
    $bridge,
    function(string $code,string $purpose) use (&$totpCalls): array {
        $totpCalls++;
        if($code==='654321'&&$purpose==='passkey_enrollment')return ['ok'=>true,'code'=>'OK'];
        return ['ok'=>false,'code'=>'TOTP_CODE_REJECTED'];
    },
    function(array $context) use (&$mintCalls): array {
        $mintCalls++;
        if(($context['auth_method']??'')!=='passkey'||($context['scope']??'')!=='autonomy')return ['ok'=>false,'code'=>'CONTEXT_INVALID'];
        return [
            'ok'=>true,
            'session_id'=>str_repeat('a',24),
            'token'=>str_repeat('b',64),
            'expires_in'=>28800,
            'idle_expires_in'=>1800,
        ];
    }
);
ok(($adapter->ready()['ok']??false)===true,'bridge ready');

$denied=$adapter->beginEnrollment('000000','Christoph');
ok(($denied['ok']??true)===false&&($denied['code']??'')==='TOTP_CODE_REJECTED','enrollment rejects untrusted TOTP gate');
$en=$adapter->beginEnrollment('654321','Christoph');
ok(($en['ok']??false)===true,'enrollment ticket created only after trusted gate');
ok($totpCalls===2,'enrollment gate invoked exactly once per request');

$pkey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
ok($pkey!==false,'P-256 key generated');
$details=openssl_pkey_get_details($pkey);
$x=$details['ec']['x']??'';$y=$details['ec']['y']??'';
ok(is_string($x)&&strlen($x)===32&&is_string($y)&&strlen($y)===32,'P-256 coordinates available');
$credId=random_bytes(32);
$cose=chr(0xA5)
    .cborInt(1).cborInt(2)
    .cborInt(3).cborInt(-7)
    .cborInt(-1).cborInt(1)
    .cborInt(-2).cborBytes($x)
    .cborInt(-3).cborBytes($y);
$opts=$adapter->registrationOptions((string)$en['enrollment_id']);
ok(($opts['ok']??false)===true,'registration options generated');
$regChallenge=(string)$opts['publicKey']['challenge'];
$authDataReg=hash('sha256','kicom.rurtalbahn.info',true).chr(0x45).pack('N',0).str_repeat("\0",16).pack('n',strlen($credId)).$credId.$cose;
$attObj=chr(0xA3)
    .cborText('fmt').cborText('none')
    .cborText('authData').cborBytes($authDataReg)
    .cborText('attStmt').chr(0xA0);
$clientReg=json_encode(['type'=>'webauthn.create','challenge'=>$regChallenge,'origin'=>'https://kicom.rurtalbahn.info'],JSON_UNESCAPED_SLASHES);
$reg=[
    'id'=>b($credId),'rawId'=>b($credId),'type'=>'public-key',
    'response'=>['clientDataJSON'=>b($clientReg),'attestationObject'=>b($attObj)]
];
$rr=$adapter->completeRegistration((string)$en['enrollment_id'],$reg,'Synthetic selftest');
ok(($rr['ok']??false)===true,'synthetic passkey registration verified');
ok($bridge->credentialCount()===1,'credential persisted');

$kp=sodium_crypto_box_keypair();
$pub=sodium_crypto_box_publickey($kp);
$created=$adapter->createChallenge(b($pub));
ok(($created['ok']??false)===true,'challenge created without minting a session');
ok($mintCalls===0,'no session before passkey assertion');
$id=(string)$created['challenge_id'];
$status=$adapter->status($id);
ok(($status['state']??'')==='pending','challenge pending');
$ao=$adapter->assertionOptions($id);
ok(($ao['ok']??false)===true,'assertion options generated');
$challenge=(string)$ao['publicKey']['challenge'];
$clientGet=json_encode(['type'=>'webauthn.get','challenge'=>$challenge,'origin'=>'https://kicom.rurtalbahn.info'],JSON_UNESCAPED_SLASHES);
$authDataGet=hash('sha256','kicom.rurtalbahn.info',true).chr(0x05).pack('N',1);
$signed=$authDataGet.hash('sha256',$clientGet,true);
$sig='';ok(openssl_sign($signed,$sig,$pkey,OPENSSL_ALGO_SHA256)===true,'assertion signed');
$assertion=[
    'id'=>b($credId),'rawId'=>b($credId),'type'=>'public-key',
    'response'=>[
        'clientDataJSON'=>b($clientGet),
        'authenticatorData'=>b($authDataGet),
        'signature'=>b($sig),
        'userHandle'=>null,
    ],
];
$vr=$adapter->verifyAndMint($id,$assertion);
ok(($vr['ok']??false)===true&&($vr['code']??'')==='PASSKEY_SESSION_APPROVED','assertion mints and seals bounded session');
ok($mintCalls===1,'session minter invoked exactly once after verification');

$expected=[
    'session_id'=>str_repeat('a',24),
    'token'=>str_repeat('b',64),
    'scope'=>'autonomy',
    'auth_method'=>'passkey',
    'expires_in'=>28800,
    'idle_expires_in'=>1800,
];
$status=$adapter->status($id);
ok(($status['state']??'')==='approved' && isset($status['ciphertext']),'ciphertext published');
ok(!isset($status['token'])&&!isset($status['session_id']),'status never exposes plaintext session credentials');
$cipher=KiComPasskeyBridge::b64uDecode((string)$status['ciphertext']);
ok(is_string($cipher),'ciphertext decodes');
$plain=sodium_crypto_box_seal_open($cipher,$kp);
ok(is_string($plain),'ciphertext decrypts');
ok(json_decode($plain,true)===$expected,'sealed session payload roundtrip exact');

$r=random_bytes(73);ok(KiComPasskeyBridge::b64uDecode(KiComPasskeyBridge::b64uEncode($r))===$r,'base64url roundtrip');

echo "SELFTEST PASS\n";
