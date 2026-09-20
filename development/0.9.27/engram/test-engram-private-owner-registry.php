<?php
declare(strict_types=1);
if (!class_exists('KiComDevSessionManager', false)) {
    require_once __DIR__ . '/../../../source/0.9.26-r3/modules/dev/DevSession.php';
}
require_once __DIR__ . '/KiComEngramPrivateOwnerRegistry.php';
require_once __DIR__ . '/KiComEngramDevSessionCredentialReader.php';
require_once __DIR__ . '/KiComEngramVerifiedCredentialResolver.php';
require_once __DIR__ . '/KiComEngramDevMemoryAdapter.php';

$ownerRegistryChecks=0;
$ownerRegistryCheck=static function(bool $ok,string $label)use(&$ownerRegistryChecks):void{
    if (!$ok) throw new RuntimeException('FAIL owner registry: '.$label);
    ++$ownerRegistryChecks;
    echo "PASS private-owner-registry ".$label."\n";
};
$ownerRegistryReject=static function(callable $attempt,string $label)use($ownerRegistryCheck):void{
    $denied=false;
    try{$attempt();}catch(RuntimeException|InvalidArgumentException $e){$denied=true;}
    $ownerRegistryCheck($denied,$label);
};
$ownerRegistryClean=static function(string $path)use(&$ownerRegistryClean):void{
    if(is_link($path)||is_file($path)){unlink($path);return;}
    if(!is_dir($path))return;
    foreach(scandir($path)as $name)if($name!=='.'&&$name!=='..')$ownerRegistryClean($path.'/'.$name);
    rmdir($path);
};
$root=sys_get_temp_dir().'/engram-owner-registry-'.bin2hex(random_bytes(8));
mkdir($root,0700);
mkdir($root.'/web',0755);
mkdir($root.'/private',0700);
mkdir($root.'/private/owners',0700);
try{
    $credential='synthetic_registry_passkey_001122334455';
    $fingerprint=hash('sha256',$credential);
    $owner=['enabled'=>true,'credential_fingerprint'=>$fingerprint,
        'subject'=>'synthetic-owner','namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']];
    $registryPath=$root.'/private/owners/engram-owners.json';
    $registryDoc=json_encode(['schema'=>1,'owners'=>[$fingerprint=>$owner]],JSON_THROW_ON_ERROR);
    file_put_contents($registryPath,$registryDoc);
    chmod($registryPath,0600);
    $registry=new KiComEngramPrivateOwnerRegistry($registryPath,$root.'/web');
    $ownerRegistryCheck($registry($fingerprint)===$owner,'exact provisioned owner read from non-web private file');
    $ownerRegistryCheck($registry(str_repeat('0',64))===null,'unregistered fingerprint is not a private-memory owner');
    $ownerRegistryCheck($registry('../other')===null,'invalid fingerprint cannot alter registry path');

    $manager=new KiComDevSessionManager($root.'/dev-sessions');
    $first=$manager->issue(['auth_method'=>'passkey','credential_id'=>$credential]);
    $second=$manager->issue(['auth_method'=>'passkey','credential_id'=>$credential]);
    $other=$manager->issue(['auth_method'=>'passkey','credential_id'=>'synthetic_unknown_other_11223344']);
    $reader=new KiComEngramDevSessionCredentialReader($root.'/dev-sessions');
    $resolver=new KiComEngramVerifiedCredentialResolver($reader,$registry);
    $approved=false;
    $entry=['namespace'=>'project','kind'=>'technical',
        'body'=>'Synthetic silver signal remembered across authorized sessions.',
        'source_kind'=>'approved_summary','source_ref'=>'summary:private-registry-roundtrip',
        'sensitivity'=>'ordinary'];
    $approval=static function(array $binding)use(&$approved,$entry):bool{
        if($approved||($binding['subject']??null)!=='synthetic-owner'
            ||($binding['body_sha256']??null)!==hash('sha256',$entry['body']))return false;
        $approved=true;return true;
    };
    $memory=new KiComEngramDevMemoryAdapter($manager,$resolver,$approval,
        static fn():KiComEngramStore=>new KiComEngramStore($root.'/private',$root.'/web'));
    $headers=static fn(array $s):array=>[
        'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json',
        'HTTP_X_KICOM_DEV_SESSION'=>$s['session_id'],'HTTP_X_KICOM_DEV_TOKEN'=>$s['token']];
    $write=json_encode(['operation'=>'ENGRAM_REMEMBER','payload'=>$entry],JSON_THROW_ON_ERROR);
    $read=json_encode(['operation'=>'ENGRAM_RECALL',
        'payload'=>['namespace'=>'project','query'=>'silver signal','limit'=>3]],JSON_THROW_ON_ERROR);
    $saved=$memory->handle($headers($first),$write);
    $ownerRegistryCheck($saved['http_status']===200&&$approved,'actual KiCom session plus on-disk private owner registry stores memory');
    $recovered=$memory->handle($headers($second),$read);
    $ownerRegistryCheck($recovered['http_status']===200
        &&($recovered['body']['count']??null)===1
        &&($recovered['body']['records'][0]['body']??null)===$entry['body'],
        'fresh KiCom session independently recovers memory using same registered passkey');
    $ownerRegistryCheck($memory->handle($headers($other),$read)['http_status']===403,
        'unknown valid passkey session cannot read the registered owner memory');
    $ownerRegistryCheck($memory->handle($headers($second),$write)['http_status']===403,
        'replayed consent does not add duplicate memory');

    chmod($registryPath,0644);
    $ownerRegistryReject(static fn()=> $registry($fingerprint),
        'permission downgrade blocks owner lookup even for previous registry instance');
    $ownerRegistryCheck($memory->handle($headers($second),$read)['http_status']===403,
        'unsafe registry fails closed inside memory transport');
    chmod($registryPath,0600);
    $ownerRegistryCheck($memory->handle($headers($second),$read)['body']['count']===1,
        'valid registry restoration leaves original private memory readable');

    $webRegistry=$root.'/web/engram-owners.json';
    file_put_contents($webRegistry,$registryDoc);chmod($webRegistry,0600);
    $ownerRegistryReject(static fn()=>new KiComEngramPrivateOwnerRegistry($webRegistry,$root.'/web'),
        'owner mapping inside webroot denied even with mode 0600');
    $link=$root.'/private/owners-link.json';
    symlink($registryPath,$link);
    $ownerRegistryReject(static fn()=>new KiComEngramPrivateOwnerRegistry($link,$root.'/web'),
        'symlinked owner registry denied');
    $wrongName=$root.'/private/owners/other.json';
    file_put_contents($wrongName,$registryDoc);chmod($wrongName,0600);
    $ownerRegistryReject(static fn()=>new KiComEngramPrivateOwnerRegistry($wrongName,$root.'/web'),
        'unexpected registry filename denied');
    $ownerRegistryCheck($memory->handle($headers($second),$read)['body']['count']===1,
        'rejected registry attacks did not alter stored Engram');
    echo "KICOM_ENGRAM_PRIVATE_OWNER_REGISTRY_TESTS_PASSED=$ownerRegistryChecks\n";
}finally{
    unset($memory,$manager,$registry);
    $ownerRegistryClean($root);
}
