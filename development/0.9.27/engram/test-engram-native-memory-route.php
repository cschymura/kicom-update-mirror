<?php
declare(strict_types=1);
if (!class_exists('KiComDevSessionManager',false)) {
    require_once __DIR__.'/../../../source/0.9.26-r3/modules/dev/DevSession.php';
}
require_once __DIR__.'/KiComEngramNativeMemoryRoute.php';
$nativeCount=0;
$assertNative=static function(bool $valid,string $why)use(&$nativeCount):void {
    if(!$valid) throw new RuntimeException('FAIL native route: '.$why);
    ++$nativeCount;echo "PASS native-route ".$why."\n";
};
$cleanNative=static function(string $path)use(&$cleanNative):void{
    if(is_link($path)||is_file($path)){unlink($path);return;}
    if(!is_dir($path))return;
    foreach(scandir($path)as $name)if($name!=='.'&&$name!=='..')$cleanNative($path.'/'.$name);
    rmdir($path);
};
$root=sys_get_temp_dir().'/engram-native-route-'.bin2hex(random_bytes(8));
mkdir($root,0700);
mkdir($root.'/web',0755);
mkdir($root.'/private',0700);
foreach(['data','backups','owners','consent','dev-sessions'] as $dir)
    mkdir($root.'/private/'.$dir,0700);
try {
    $manager=new KiComDevSessionManager($root.'/private/dev-sessions');
    $keyA='synthetic_native_key_A_001122334455';
    $keyB='synthetic_native_key_B_001122334455';
    $sessionA=$manager->issue(['auth_method'=>'passkey','credential_id'=>$keyA]);
    $sessionB=$manager->issue(['auth_method'=>'passkey','credential_id'=>$keyB]);
    $ownerA=hash('sha256',$keyA);
    $ownerB=hash('sha256',$keyB);
    $registry=$root.'/private/owners/engram-owners.json';
    file_put_contents($registry,json_encode(['schema'=>1,'owners'=>[
        $ownerA=>['enabled'=>true,'credential_fingerprint'=>$ownerA,
            'subject'=>'synthetic-native-a','namespaces'=>['project'],
            'engram_rights'=>['engram.read','engram.write']],
        $ownerB=>['enabled'=>true,'credential_fingerprint'=>$ownerB,
            'subject'=>'synthetic-native-b','namespaces'=>['project'],
            'engram_rights'=>['engram.read']]
    ]],JSON_THROW_ON_ERROR));
    chmod($registry,0600);
    $trusted=[
        'enabled'=>true,'operator_approved'=>true,'host_isolation_verified'=>true,
        'runtime_source'=>'server-only-reviewed','private_memory_scope'=>'dev-verified-owner',
        'web_root'=>$root.'/web','reviewed_web_roots'=>[$root.'/web'],
        'data_dir'=>$root.'/private/data','backups_dir'=>$root.'/private/backups',
        'dev_session_root'=>$root.'/private/dev-sessions',
        'owner_registry'=>$registry,'consent_dir'=>$root.'/private/consent'
    ];
    $headers=static fn(array $s):array=>[
        'HTTPS'=>'on','REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json',
        'HTTP_X_KICOM_DEV_SESSION'=>$s['session_id'],
        'HTTP_X_KICOM_DEV_TOKEN'=>$s['token']
    ];
    $entry=[
        'namespace'=>'project','kind'=>'technical',
        'body'=>'Synthetic marine carriage persists via native KiCom API route.',
        'source_kind'=>'approved_summary','source_ref'=>'summary:native-route',
        'sensitivity'=>'ordinary'
    ];
    $write=json_encode(['operation'=>'ENGRAM_REMEMBER','payload'=>$entry],JSON_THROW_ON_ERROR);
    $read=json_encode(['operation'=>'ENGRAM_RECALL','payload'=>[
        'namespace'=>'project','query'=>'marine carriage','limit'=>5
    ]],JSON_THROW_ON_ERROR);
    $disabled=$trusted;$disabled['enabled']=false;
    $assertNative(KiComEngramNativeMemoryRoute::handle($headers($sessionA),$write,$disabled)['http_status']===404
        && !file_exists($root.'/private/data/engrams.sqlite'),
        'default-disabled route refuses write before private store creation');
    $unapproved=$trusted;$unapproved['operator_approved']=false;
    $assertNative(KiComEngramNativeMemoryRoute::handle($headers($sessionA),$read,$unapproved)['http_status']===404,
        'no operator approval denies memory without discovering content');
    $unverified=$trusted;$unverified['host_isolation_verified']=false;
    $assertNative(KiComEngramNativeMemoryRoute::handle($headers($sessionA),$read,$unverified)['http_status']===404,
        'unreviewed host isolation denies route');
    $assertNative(KiComEngramNativeMemoryRoute::handle(
        ['HTTPS'=>'off']+$headers($sessionA),$read,$trusted)['http_status']===403,
        'HTTPS required even for synthetically enabled native route');
    $assertNative(KiComEngramNativeMemoryRoute::handle(
        ['REQUEST_METHOD'=>'GET']+$headers($sessionA),$read,$trusted)['http_status']===403,
        'GET request cannot retrieve private Engram');
    $missing=$trusted;unset($missing['owner_registry']);
    $assertNative(KiComEngramNativeMemoryRoute::handle($headers($sessionA),$read,$missing)['http_status']===404,
        'missing trusted registry path fails closed');
    $wrongRoots=$trusted;$wrongRoots['reviewed_web_roots']=[$root.'/private'];
    $assertNative(KiComEngramNativeMemoryRoute::handle($headers($sessionA),$read,$wrongRoots)['http_status']===404,
        'unreviewed webroot inventory cannot authorize private path');
    $noAuth=$headers($sessionA);unset($noAuth['HTTP_X_KICOM_DEV_TOKEN']);
    $assertNative(KiComEngramNativeMemoryRoute::handle($noAuth,$read,$trusted)['http_status']===401,
        'missing bearer cannot read even after private host preflight');
    $assertNative(KiComEngramNativeMemoryRoute::handle($headers($sessionA),$write,$trusted)['http_status']===403,
        'valid passkey DEV session does not create its own memory consent');

    $binding=[
        'subject'=>'synthetic-native-a','namespace'=>'project','kind'=>'technical',
        'body_sha256'=>hash('sha256',$entry['body']),
        'source_kind'=>'approved_summary',
        'source_ref_sha256'=>hash('sha256',$entry['source_ref']),
        'sensitivity'=>'ordinary'
    ];
    // Synthetic stand-in for ALREADY completed independent WebAuthn approval;
    // native route itself cannot issue() a consent.
    $ledger=new KiComEngramPrivateConsentLedger(
        $trusted['consent_dir'],$trusted['web_root'],
        static fn(array $candidate):bool=>$candidate===$binding
    );
    $ledger->issue($binding);
    $result=KiComEngramNativeMemoryRoute::handle($headers($sessionA),$write,$trusted);
    $assertNative($result['http_status']===200
        && ($result['body']['code']??'')==='ENGRAM_REMEMBER_OK',
        'already reviewed exact-record approval permits authenticated native write');
    $assertNative(KiComEngramNativeMemoryRoute::handle($headers($sessionA),$write,$trusted)['http_status']===403,
        'native route cannot replay consumed one-time approval');
    $sessionA2=$manager->issue(['auth_method'=>'passkey','credential_id'=>$keyA]);
    $after=KiComEngramNativeMemoryRoute::handle($headers($sessionA2),$read,$trusted);
    $assertNative($after['http_status']===200
        && ($after['body']['records'][0]['body']??null)===$entry['body'],
        'independently issued original KiCom session reads private SQLite memory through native route');
    $assertNative((KiComEngramNativeMemoryRoute::handle($headers($sessionB),$read,$trusted)['body']['count']??-1)===0,
        'separate registered passkey owner cannot read another owner memory');
    $bad=json_encode(['operation'=>'ENGRAM_RECALL','payload'=>[
        'namespace'=>'project','query'=>'marine carriage','limit'=>5,
        'subject'=>'synthetic-native-a','enabled'=>true
    ]],JSON_THROW_ON_ERROR);
    $assertNative(KiComEngramNativeMemoryRoute::handle($headers($sessionB),$bad,$trusted)['http_status']===403,
        'caller-injected subject and feature flag cannot override owner isolation');
    $manager->revoke($sessionA2['session_id']);
    $assertNative(KiComEngramNativeMemoryRoute::handle($headers($sessionA2),$read,$trusted)['http_status']===401,
        'revoked original KiCom DEV session cannot read native-route private memory');
    $assertNative((KiComEngramNativeMemoryRoute::handle($headers($sessionA),$read,$trusted)['body']['count']??-1)===1,
        'denials and revoked session do not alter committed memory');
    echo "KICOM_ENGRAM_NATIVE_MEMORY_ROUTE_TESTS_PASSED=$nativeCount\n";
} finally {
    unset($ledger,$manager);
    $cleanNative($root);
}
