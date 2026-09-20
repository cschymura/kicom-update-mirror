<?php
declare(strict_types=1);
/**
 * Synthetic full-stack DEV acceptance: original KiCom DEV authenticated session
 * -> protected session record credential -> independently provisioned private
 * owner mapping -> independent exact-record human-review fixture -> private
 * SQLite consent ledger -> HTTP-like memory adapter -> private Engram SQLite
 * -> fresh separately issued KiCom session retrieval. No live installation.
 */
if (!class_exists('KiComDevSessionManager', false)) {
    require_once __DIR__ . '/../../../source/0.9.26-r3/modules/dev/DevSession.php';
}
require_once __DIR__ . '/KiComEngramDevSessionCredentialReader.php';
require_once __DIR__ . '/KiComEngramVerifiedCredentialResolver.php';
require_once __DIR__ . '/KiComEngramPrivateOwnerRegistry.php';
require_once __DIR__ . '/KiComEngramPrivateConsentLedger.php';
require_once __DIR__ . '/KiComEngramDevMemoryAdapter.php';

$completeChecks=0;
$completeCheck=static function(bool $condition,string $description) use (&$completeChecks):void{
    if (!$condition) throw new RuntimeException('FAIL integrated Engram: '.$description);
    ++$completeChecks;
    echo "PASS complete-engram ".$description."\n";
};
$completeClean=static function(string $path) use (&$completeClean):void{
    if (is_link($path)||is_file($path)) { unlink($path);return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $item) if ($item!=='.' && $item!=='..') $completeClean($path.'/'.$item);
    rmdir($path);
};
$root=sys_get_temp_dir().'/engram-complete-dev-'.bin2hex(random_bytes(8));
mkdir($root,0700);
mkdir($root.'/web',0755);
mkdir($root.'/private',0700);
mkdir($root.'/private/owners',0700);
mkdir($root.'/private/consent',0700);
try {
    // Real original R3 session manager for synthetic credentials; do not fake
    // identity through HTTP headers or session display labels.
    $manager=new KiComDevSessionManager($root.'/dev-sessions');
    $credentialA='synthetic_full_stack_passkey_A_0123456';
    $credentialB='synthetic_full_stack_passkey_B_0123456';
    $first=$manager->issue(['auth_method'=>'passkey','credential_id'=>$credentialA]);
    $other=$manager->issue(['auth_method'=>'passkey','credential_id'=>$credentialB]);
    $reader=new KiComEngramDevSessionCredentialReader($root.'/dev-sessions');
    $fingerprintA=hash('sha256',$credentialA);
    $fingerprintB=hash('sha256',$credentialB);
    $recordA=[
        'enabled'=>true,'credential_fingerprint'=>$fingerprintA,
        'subject'=>'synthetic-owner-a','namespaces'=>['project'],
        'engram_rights'=>['engram.read','engram.write']
    ];
    $recordB=[
        'enabled'=>true,'credential_fingerprint'=>$fingerprintB,
        'subject'=>'synthetic-owner-b','namespaces'=>['project'],
        'engram_rights'=>['engram.read']
    ];
    $ownerPath=$root.'/private/owners/engram-owners.json';
    file_put_contents($ownerPath,json_encode([
        'schema'=>1,'owners'=>[$fingerprintA=>$recordA,$fingerprintB=>$recordB]
    ],JSON_THROW_ON_ERROR));
    chmod($ownerPath,0600);
    $registry=new KiComEngramPrivateOwnerRegistry($ownerPath,$root.'/web');
    $resolver=new KiComEngramVerifiedCredentialResolver($reader,$registry);

    // Synthetic fixture representing future independently authenticated
    // first-party "I reviewed and approve THIS exact memory" action.
    // There is deliberately NO live consent issuance endpoint here.
    $entry=[
        'namespace'=>'project','kind'=>'technical',
        'body'=>'Synthetic orange carriage memory persists across fresh verified logins.',
        'source_kind'=>'approved_summary','source_ref'=>'summary:integrated-fullstack',
        'sensitivity'=>'ordinary'
    ];
    $expectedBinding=[
        'subject'=>'synthetic-owner-a','namespace'=>$entry['namespace'],
        'kind'=>$entry['kind'],'body_sha256'=>hash('sha256',$entry['body']),
        'source_kind'=>$entry['source_kind'],
        'source_ref_sha256'=>hash('sha256',$entry['source_ref']),
        'sensitivity'=>$entry['sensitivity']
    ];
    $humanApproved=false;
    $independentReview=static function(array $binding)use(&$humanApproved,$expectedBinding):bool{
        return $humanApproved && $binding===$expectedBinding;
    };
    $ledger=new KiComEngramPrivateConsentLedger($root.'/private/consent',$root.'/web',$independentReview);
    $openPrivateStore=static fn():KiComEngramStore=>
        new KiComEngramStore($root.'/private',$root.'/web');
    $adapter=new KiComEngramDevMemoryAdapter($manager,$resolver,[$ledger,'consume'],$openPrivateStore);
    $requestHeaders=static fn(array $session):array=>[
        'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json',
        'HTTP_X_KICOM_DEV_SESSION'=>$session['session_id'],
        'HTTP_X_KICOM_DEV_TOKEN'=>$session['token']
    ];
    $remember=json_encode(['operation'=>'ENGRAM_REMEMBER','payload'=>$entry],JSON_THROW_ON_ERROR);
    $recall=json_encode(['operation'=>'ENGRAM_RECALL',
        'payload'=>['namespace'=>'project','query'=>'orange carriage','limit'=>3]],JSON_THROW_ON_ERROR);
    $completeCheck($adapter->handle($requestHeaders($first),$remember)['http_status']===403,
        'existing KiCom passkey session cannot write without independent exact-record review');
    $humanApproved=true;
    $receipt=$ledger->issue($expectedBinding,180);
    $completeCheck(strlen($receipt['receipt_id'])===32,
        'synthetic independent human-review fixture generates a bounded private consent receipt');
    $saved=$adapter->handle($requestHeaders($first),$remember);
    $completeCheck($saved['http_status']===200
        && ($saved['body']['revision']??null)===1
        && is_string($saved['body']['id']??null),
        'original KiCom passkey session stores an approved record in private SQLite');
    $completeCheck($adapter->handle($requestHeaders($first),$remember)['http_status']===403,
        'reusing already consumed approval fails without a second explicit review');
    $completeCheck(($adapter->handle($requestHeaders($other),$recall)['body']['count']??-1)===0,
        'another registered passkey and different private owner cannot read first record');
    $completeCheck($adapter->handle($requestHeaders($other),$remember)['http_status']===403,
        'another registered owner cannot consume the first owner exact-record consent');

    // The next memory reader has a distinct KiCom session and a distinct
    // memory adapter + independent SQLite connection, not reused PHP objects.
    $second=$manager->issue(['auth_method'=>'passkey','credential_id'=>$credentialA]);
    $newReader=new KiComEngramDevSessionCredentialReader($root.'/dev-sessions');
    $newRegistry=new KiComEngramPrivateOwnerRegistry($ownerPath,$root.'/web');
    $newResolver=new KiComEngramVerifiedCredentialResolver($newReader,$newRegistry);
    $newLedger=new KiComEngramPrivateConsentLedger(
        $root.'/private/consent',$root.'/web',static fn(array $binding):bool=>false
    );
    $newAdapter=new KiComEngramDevMemoryAdapter(
        $manager,$newResolver,[$newLedger,'consume'],$openPrivateStore
    );
    $recalled=$newAdapter->handle($requestHeaders($second),$recall);
    $completeCheck($recalled['http_status']===200
        && ($recalled['body']['count']??null)===1
        && $recalled['body']['records'][0]['id']===$saved['body']['id']
        && $recalled['body']['records'][0]['body']===$entry['body'],
        'independently instantiated adapter and new authenticated KiCom session retrieve stored memory');
    $completeCheck($newAdapter->handle($requestHeaders($second),$remember)['http_status']===403,
        'fresh session grants NO new write consent by itself');
    $manager->revoke($second['session_id']);
    $completeCheck($newAdapter->handle($requestHeaders($second),$recall)['http_status']===401,
        'revoked fresh session denied even though private memory still exists');
    $completeCheck(($newAdapter->handle($requestHeaders($first),$recall)['body']['count']??null)===1,
        'revocation of second session does not erase or expose stored memory');

    echo "KICOM_ENGRAM_INTEGRATED_PRIVATE_MEMORY_TESTS_PASSED=$completeChecks\n";
} finally {
    unset($newAdapter,$newLedger,$newRegistry,$newReader,$adapter,$ledger,$registry,$manager);
    $completeClean($root);
}
