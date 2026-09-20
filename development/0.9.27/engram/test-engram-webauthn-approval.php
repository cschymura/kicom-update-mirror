<?php
declare(strict_types=1);
/**
 * ACTUAL crypto: synthetic P-256 signatures verified by original KiCom
 * PasskeyBridge, not a fake fresh_passkey_assertion=true fixture.
 */
if (!class_exists('KiComDevSessionManager',false)) {
    require_once __DIR__.'/../../../source/0.9.26-r3/modules/dev/DevSession.php';
}
require_once __DIR__.'/KiComEngramWebAuthnApprovalController.php';
require_once __DIR__.'/KiComEngramWebAuthnReviewHttpAdapter.php';
require_once __DIR__.'/KiComEngramFirstPartyHostBridge.php';
require_once __DIR__.'/KiComEngramNativeMemoryRoute.php';
require_once __DIR__.'/KiComEngramDevMemoryAdapter.php';

$webAuthnChecks=0;
$checkWebAuthn=static function(bool $condition,string $label)use(&$webAuthnChecks):void{
    if(!$condition)throw new RuntimeException('FAIL WebAuthn review: '.$label);
    ++$webAuthnChecks;echo "PASS verified-webauthn ".$label."\n";
};
$denyWebAuthn=static function(callable $fn,string $label)use($checkWebAuthn):void{
    $denied=false;
    try{$fn();}catch(Throwable $e){$denied=true;}
    $checkWebAuthn($denied,$label);
};
$cleanWebAuthn=static function(string $p)use(&$cleanWebAuthn):void{
    if(is_link($p)||is_file($p)){unlink($p);return;}
    if(!is_dir($p))return;
    foreach(scandir($p)as $name)if($name!=='.'&&$name!=='..')$cleanWebAuthn($p.'/'.$name);
    rmdir($p);
};
$root=sys_get_temp_dir().'/engram-signed-webauthn-'.bin2hex(random_bytes(8));
mkdir($root,0700);
mkdir($root.'/web',0755);
mkdir($root.'/private',0700);
foreach(['consent','drafts','stepup','owners','passkeys']as $d)mkdir($root.'/private/'.$d,0700);
try {
    $rpId='kicom.rurtalbahn.info';
    $origin='https://kicom.rurtalbahn.info';
    $passkeys=new KiComPasskeyBridge($root.'/private/passkeys',$rpId,$origin,'KiCom synthetic');
    $checkWebAuthn(($passkeys->ready()['ok']??false)===true,
        'original KiCom PasskeyBridge initialized with private synthetic credential store');

    $makeCredential=static function():array{
        $key=openssl_pkey_new([
            'private_key_type'=>OPENSSL_KEYTYPE_EC,
            'curve_name'=>'prime256v1'
        ]);
        if($key===false)throw new RuntimeException('Synthetic P-256 test key unavailable');
        $details=openssl_pkey_get_details($key);
        if(!is_array($details) || !is_string($details['ec']['x']??null)
            || !is_string($details['ec']['y']??null)) {
            throw new RuntimeException('Synthetic P-256 public key unavailable');
        }
        $rawId=random_bytes(24);
        return [
            'private'=>$key,'raw_id'=>$rawId,
            'record'=>[
                'credential_id'=>KiComPasskeyBridge::b64uEncode($rawId),
                'x'=>KiComPasskeyBridge::b64uEncode($details['ec']['x']),
                'y'=>KiComPasskeyBridge::b64uEncode($details['ec']['y']),
                'alg'=>-7,'curve'=>'P-256','sign_count'=>0,
                'user_id'=>KiComPasskeyBridge::b64uEncode(random_bytes(16)),
                'label'=>'SYNTHETIC CI'
            ]
        ];
    };
    $a=$makeCredential();$b=$makeCredential();
    $credentials=['schema'=>1,'credentials'=>[$a['record'],$b['record']]];
    $credentialFile=$root.'/private/passkeys/credentials.json';
    file_put_contents($credentialFile,json_encode($credentials,JSON_THROW_ON_ERROR));
    chmod($credentialFile,0600);
    $owners=[];
    $fingerprintA=hash('sha256',$a['record']['credential_id']);
    $fingerprintB=hash('sha256',$b['record']['credential_id']);
    $owners[$fingerprintA]=[
        'enabled'=>true,'credential_fingerprint'=>$fingerprintA,
        'subject'=>'synthetic-a','namespaces'=>['project'],
        'engram_rights'=>['engram.read','engram.write']
    ];
    $owners[$fingerprintB]=[
        'enabled'=>true,'credential_fingerprint'=>$fingerprintB,
        'subject'=>'synthetic-b','namespaces'=>['project'],
        'engram_rights'=>['engram.read','engram.write']
    ];
    $ownerPath=$root.'/private/owners/engram-owners.json';
    file_put_contents($ownerPath,json_encode(['schema'=>1,'owners'=>$owners],JSON_THROW_ON_ERROR));
    chmod($ownerPath,0600);
    $registry=new KiComEngramPrivateOwnerRegistry($ownerPath,$root.'/web');

    // Stand-in for a FIRST-PARTY KICom server session lookup. Client fields
    // such as 'subject' or 'fresh_passkey_assertion' are deliberately ignored.
    $browserSessions=[
        'server-cookie-a'=>[
            'verified'=>true,'first_party_browser'=>true,
            'subject'=>'synthetic-a','browser_session_id'=>bin2hex(random_bytes(32)),
            'namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']
        ],
        'server-cookie-b'=>[
            'verified'=>true,'first_party_browser'=>true,
            'subject'=>'synthetic-b','browser_session_id'=>bin2hex(random_bytes(32)),
            'namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']
        ]
    ];
    $resolveBrowser=static function(array $session)use(&$browserSessions):array{
        return $browserSessions[$session['server_cookie']??'']??['verified'=>false];
    };
    $browserA=['server_cookie'=>'server-cookie-a'];
    $browserB=['server_cookie'=>'server-cookie-b'];
    $controller=new KiComEngramWebAuthnApprovalController(
        $root.'/private/stepup',$root.'/private/drafts',$root.'/private/consent',
        $root.'/web',$passkeys,$registry,$resolveBrowser
    );
    $entry=[
        'namespace'=>'project','kind'=>'technical',
        'body'=>'Synthetic signed passkey confirms blue platform memory.',
        'source_kind'=>'approved_summary','source_ref'=>'summary:actual-webauthn-review',
        'sensitivity'=>'ordinary'
    ];
    $id=$controller->stage($browserA,$entry);
    $html=$controller->render($id,$browserA);
    preg_match('/name="csrf" value="([a-f0-9]{64})"/',$html,$match);
    $csrf=$match[1]??'';
    $checkWebAuthn(strlen($csrf)===64&&str_contains($html,$entry['body']),
        'private owner can display the exact pending record and CSRF');

    $sign=static function(array $start,array $key,int $counter=1)use($rpId,$origin):array{
        $client=json_encode([
            'type'=>'webauthn.get',
            'challenge'=>$start['publicKey']['challenge'],
            'origin'=>$origin
        ],JSON_THROW_ON_ERROR);
        $authenticator=hash('sha256',$rpId,true).chr(0x05).pack('N',$counter);
        if(!openssl_sign($authenticator.hash('sha256',$client,true),
            $sig,$key['private'],OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Synthetic WebAuthn signing failed');
        }
        return [
            'id'=>$key['record']['credential_id'],
            'rawId'=>$key['record']['credential_id'],
            'response'=>[
                'clientDataJSON'=>KiComPasskeyBridge::b64uEncode($client),
                'authenticatorData'=>KiComPasskeyBridge::b64uEncode($authenticator),
                'signature'=>KiComPasskeyBridge::b64uEncode($sig)
            ]
        ];
    };

    $initial=$controller->begin($id,$csrf,$browserA);
    $checkWebAuthn(strlen($initial['challenge_id'])===32
        && ($initial['publicKey']['userVerification']??'')==='required',
        'new signed original KiCom WebAuthn challenge requires user verification');
    $denyWebAuthn(static fn()=> $controller->confirm(
        $id,$csrf,$initial['challenge_id'],$sign($initial,$a),$browserB
    ),'another first-party browser cannot consume owner A review challenge');
    $denyWebAuthn(static fn()=> $controller->confirm(
        $id,str_repeat('0',64),$initial['challenge_id'],$sign($initial,$a),$browserA
    ),'different CSRF cannot substitute for displayed record binding');
    $invalid=$sign($initial,$a);
    $invalid['response']['signature']=KiComPasskeyBridge::b64uEncode(random_bytes(64));
    $denyWebAuthn(static fn()=> $controller->confirm(
        $id,$csrf,$initial['challenge_id'],$invalid,$browserA
    ),'invalid cryptographic WebAuthn signature rejects approval');
    $denyWebAuthn(static fn()=> $controller->confirm(
        $id,$csrf,$initial['challenge_id'],$sign($initial,$a),$browserA
    ),'failed assertion burns challenge and cannot be retried');

    $second=$controller->begin($id,$csrf,$browserA);
    $denyWebAuthn(static fn()=> $controller->confirm(
        $id,$csrf,$second['challenge_id'],$sign($second,$b),$browserA
    ),'real signature of another enrolled passkey cannot approve different owner memory');
    $third=$controller->begin($id,$csrf,$browserA);
    $realAssertion=$sign($third,$a);
    $result=$controller->confirm(
        $id,$csrf,$third['challenge_id'],$realAssertion,$browserA
    );
    $checkWebAuthn(($result['ok']??false)===true
        && ($result['review_id']??null)===$id,
        'original KiCom verifier accepts real synthetic P-256 signature for same registered owner and exact draft');
    $denyWebAuthn(static fn()=> $controller->confirm(
        $id,$csrf,$third['challenge_id'],$realAssertion,$browserA
    ),'same challenge cannot issue a second consent');
    $denyWebAuthn(static fn()=> $controller->begin($id,$csrf,$browserA),
        'approved private draft cannot be re-enrolled without another displayed review');

    $manager=new KiComDevSessionManager($root.'/sessions');
    $sessionA=$manager->issue(['auth_method'=>'passkey','credential_id'=>$a['record']['credential_id']]);
    $identity=static function(array $auth)use($sessionA):array{
        if($auth['session_id']!==$sessionA['session_id'])return ['verified'=>false];
        return ['verified'=>true,'subject'=>'synthetic-a',
            'namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']];
    };
    $adapter=new KiComEngramDevMemoryAdapter(
        $manager,$identity,[$controller->consentLedgerForMemoryAdapter(),'consume'],
        static fn():KiComEngramStore=>new KiComEngramStore($root.'/private',$root.'/web')
    );
    $headers=[
        'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json',
        'HTTP_X_KICOM_DEV_SESSION'=>$sessionA['session_id'],
        'HTTP_X_KICOM_DEV_TOKEN'=>$sessionA['token']
    ];
    $write=json_encode(['operation'=>'ENGRAM_REMEMBER','payload'=>$entry],JSON_THROW_ON_ERROR);
    $read=json_encode(['operation'=>'ENGRAM_RECALL','payload'=>[
        'namespace'=>'project','query'=>'blue platform','limit'=>4
    ]],JSON_THROW_ON_ERROR);
    $saved=$adapter->handle($headers,$write);
    $checkWebAuthn(($saved['body']['code']??null)==='ENGRAM_REMEMBER_OK',
        'verified signed WebAuthn approval is consumed by actual private SQLite memory write');
    $checkWebAuthn($adapter->handle($headers,$write)['http_status']===403,
        'signed approval cannot be replayed for a second memory insertion');
    $secondSession=$manager->issue(['auth_method'=>'passkey','credential_id'=>$a['record']['credential_id']]);
    $secondIdentity=static function(array $auth)use($secondSession):array{
        if($auth['session_id']!==$secondSession['session_id'])return ['verified'=>false];
        return ['verified'=>true,'subject'=>'synthetic-a',
            'namespaces'=>['project'],'engram_rights'=>['engram.read']];
    };
    $newAdapter=new KiComEngramDevMemoryAdapter(
        $manager,$secondIdentity,[$controller->consentLedgerForMemoryAdapter(),'consume'],
        static fn():KiComEngramStore=>new KiComEngramStore($root.'/private',$root.'/web')
    );
    $newHeaders=$headers;
    $newHeaders['HTTP_X_KICOM_DEV_SESSION']=$secondSession['session_id'];
    $newHeaders['HTTP_X_KICOM_DEV_TOKEN']=$secondSession['token'];
    $recalled=$newAdapter->handle($newHeaders,$read);
    $checkWebAuthn(($recalled['body']['records'][0]['body']??null)===$entry['body'],
        'fresh independent KiCom DEV session retrieves previously human-passkey-reviewed memory');

    // First-party transport: same-origin POST + signed NEW challenge.
    $http=new KiComEngramWebAuthnReviewHttpAdapter(
        $controller,
        static function(array $server):?array {
            return match($server['SERVER_TEST_COOKIE']??null) {
                'server-cookie-a'=>['server_cookie'=>'server-cookie-a'],
                'server-cookie-b'=>['server_cookie'=>'server-cookie-b'],
                default=>null
            };
        },
        $origin
    );
    $httpServer=[
        'REQUEST_METHOD'=>'POST','HTTPS'=>'on',
        'HTTP_ORIGIN'=>$origin,'CONTENT_TYPE'=>'application/json',
        'SERVER_TEST_COOKIE'=>'server-cookie-a'
    ];
    $secondEntry=$entry;
    $secondEntry['body']='Synthetic HTTP consent remembers a signed violet train.';
    $secondEntry['source_ref']='summary:actual-http-webauthn-review';
    $review2=$controller->stage($browserA,$secondEntry);
    $html2=$controller->render($review2,$browserA);
    preg_match('/name="csrf" value="([a-f0-9]{64})"/',$html2,$match2);
    $csrf2=$match2[1]??'';
    $beginJson=json_encode(['operation'=>'ENGRAM_REVIEW_BEGIN','payload'=>[
        'review_id'=>$review2,'csrf'=>$csrf2
    ]],JSON_THROW_ON_ERROR);
    $checkWebAuthn($http->handle(['REQUEST_METHOD'=>'GET']+$httpServer,$beginJson)['http_status']===405,
        'first-party JSON route refuses GET');
    $checkWebAuthn($http->handle(['HTTP_ORIGIN'=>'https://evil.example']+$httpServer,$beginJson)['http_status']===403,
        'first-party JSON route refuses foreign Origin');
    $checkWebAuthn($http->handle(['SERVER_TEST_COOKIE'=>'unknown']+$httpServer,$beginJson)['http_status']===401,
        'first-party JSON route denies missing authenticated browser');
    $forged=json_encode(['operation'=>'ENGRAM_REVIEW_BEGIN','payload'=>[
        'review_id'=>$review2,'csrf'=>$csrf2,'subject'=>'synthetic-a'
    ]],JSON_THROW_ON_ERROR);
    $checkWebAuthn($http->handle($httpServer,$forged)['http_status']===400,
        'HTTP-supplied subject cannot become owner authority');
    $httpStarted=$http->handle($httpServer,$beginJson);
    $challenge=$httpStarted['body'];
    $checkWebAuthn($httpStarted['http_status']===200
        && ($challenge['publicKey']['userVerification']??null)==='required'
        && $httpStarted['headers']['Cache-Control']==='no-store, private',
        'authenticated same-origin transport returns signed WebAuthn challenge');
    $assertion2=$sign($challenge,$a,2);
    $confirmJson=json_encode(['operation'=>'ENGRAM_REVIEW_CONFIRM','payload'=>[
        'review_id'=>$review2,'csrf'=>$csrf2,
        'challenge_id'=>$challenge['challenge_id'],'assertion'=>$assertion2
    ]],JSON_THROW_ON_ERROR);
    $checkWebAuthn($http->handle(['SERVER_TEST_COOKIE'=>'server-cookie-b']+$httpServer,$confirmJson)['http_status']===403,
        'foreign browser cannot confirm signed review of another owner');
    $confirmed=$http->handle($httpServer,$confirmJson);
    $checkWebAuthn($confirmed['http_status']===200
        && ($confirmed['body']['code']??null)==='ENGRAM_REVIEW_APPROVED',
        'first-party transport completes real synthetic P-256 signed consent');
    $checkWebAuthn($http->handle($httpServer,$confirmJson)['http_status']===403,
        'signed review confirmation cannot be replayed');
    $write2=json_encode(['operation'=>'ENGRAM_REMEMBER','payload'=>$secondEntry],JSON_THROW_ON_ERROR);
    $checkWebAuthn(($adapter->handle($headers,$write2)['body']['code']??null)==='ENGRAM_REMEMBER_OK',
        'HTTP-confirmed signed consent inserts ONLY its exact second memory');
    $read2=json_encode(['operation'=>'ENGRAM_RECALL','payload'=>[
        'namespace'=>'project','query'=>'violet train','limit'=>4
    ]],JSON_THROW_ON_ERROR);
    $checkWebAuthn(($newAdapter->handle($newHeaders,$read2)['body']['records'][0]['body']??null)===$secondEntry['body'],
        'new authenticated session recovers private HTTP-reviewed memory');

    // Real first-party HOST bridge uses the existing KiCom ADMIN PHP
    // session, not a caller-provided actor or a DEV bearer as reviewer.
    mkdir($root.'/private/data',0700);
    mkdir($root.'/private/backups',0700);
    $adminSession=['admin'=>true,'csrf'=>bin2hex(random_bytes(24))];
    $adminSid=bin2hex(random_bytes(20));
    $host=[
        'enabled'=>true,'operator_approved'=>true,'host_isolation_verified'=>true,
        'review_enabled'=>true,'runtime_source'=>'server-only-reviewed',
        'private_memory_scope'=>'dev-verified-owner',
        'web_root'=>$root.'/web','reviewed_web_roots'=>[$root.'/web'],
        'data_dir'=>$root.'/private/data','backups_dir'=>$root.'/private/backups',
        'owner_registry'=>$ownerPath,'consent_dir'=>$root.'/private/consent',
        'review_dir'=>$root.'/private/drafts','stepup_dir'=>$root.'/private/stepup',
        'passkey_store'=>$root.'/private/passkeys','admin_subject'=>'synthetic-a',
        'expected_origin'=>$origin,'rp_id'=>$rpId,
        'dev_session_root'=>$root.'/sessions',
    ];
    $adminRequest=['REQUEST_METHOD'=>'GET','HTTPS'=>'on'];
    $page=KiComEngramFirstPartyHostBridge::adminPage(
        $host,$adminSession,$adminSid,$adminRequest,[],[]
    );
    $checkWebAuthn($page['status']===200
        && str_contains($page['body'],'name="body"'),
        'real KiCom admin PHP session can open private first-party review form');
    $notAdmin=$adminSession;$notAdmin['admin']=false;
    $checkWebAuthn(KiComEngramFirstPartyHostBridge::adminPage(
        $host,$notAdmin,$adminSid,$adminRequest,[],[])['status']===404,
        'no original KiCom admin session means no first-party memory review');
    $offHost=$host;$offHost['review_enabled']=false;
    $checkWebAuthn(KiComEngramFirstPartyHostBridge::adminPage(
        $offHost,$adminSession,$adminSid,$adminRequest,[],[])['status']===404,
        'unapproved host cannot expose first-party browser review');

    $thirdEntryBody='Synthetic lime engine confirmed by authenticated KiCom admin and real P-256 passkey.';
    $thirdEntrySource='note:admin-synthetic-review';
    $stage=KiComEngramFirstPartyHostBridge::adminPage(
        $host,$adminSession,$adminSid,
        ['REQUEST_METHOD'=>'POST','HTTPS'=>'on'],[],
        ['csrf'=>$adminSession['csrf'],
         'body'=>$thirdEntryBody,'source_ref'=>$thirdEntrySource]
    );
    preg_match('/name="review_id" value="([a-f0-9]{32})"/',$stage['body'],$reviewMatch);
    preg_match('/name="csrf" value="([a-f0-9]{64})"/',$stage['body'],$csrfMatch);
    $reviewId=$reviewMatch[1]??'';$reviewCsrf=$csrfMatch[1]??'';
    $checkWebAuthn($stage['status']===200 && strlen($reviewId)===32
        && strlen($reviewCsrf)===64
        && str_contains($stage['body'],'data-engram-review-api="/api.php?q=ENGRAM_REVIEW"'),
        'original admin session stages one exact record and renders live-route WebAuthn UI');
    $reviewHttp=['REQUEST_METHOD'=>'POST','HTTPS'=>'on','HTTP_ORIGIN'=>$origin,
        'CONTENT_TYPE'=>'application/json'];
    $startJson=json_encode(['operation'=>'ENGRAM_REVIEW_BEGIN','payload'=>[
        'review_id'=>$reviewId,'csrf'=>$reviewCsrf
    ]],JSON_THROW_ON_ERROR);
    $noSession=KiComEngramFirstPartyHostBridge::reviewApi(
        $host,$notAdmin,$adminSid,$reviewHttp,$startJson
    );
    $checkWebAuthn($noSession['http_status']===404,
        'valid review CSRF alone cannot substitute for original KiCom admin session');
    $started=KiComEngramFirstPartyHostBridge::reviewApi(
        $host,$adminSession,$adminSid,$reviewHttp,$startJson
    );
    $checkWebAuthn($started['http_status']===200
        && ($started['body']['publicKey']['userVerification']??null)==='required',
        'first-party admin session obtains ORIGINAL KiCom signed challenge');
    $thirdAssertion=$sign($started['body'],$a,3);
    $confirmJson=json_encode(['operation'=>'ENGRAM_REVIEW_CONFIRM','payload'=>[
        'review_id'=>$reviewId,'csrf'=>$reviewCsrf,
        'challenge_id'=>$started['body']['challenge_id'],
        'assertion'=>$thirdAssertion
    ]],JSON_THROW_ON_ERROR);
    $thirdApproved=KiComEngramFirstPartyHostBridge::reviewApi(
        $host,$adminSession,$adminSid,$reviewHttp,$confirmJson
    );
    $checkWebAuthn($thirdApproved['http_status']===200
        && ($thirdApproved['body']['code']??null)==='ENGRAM_REVIEW_APPROVED',
        'real signed P-256 step-up grants exact memory after existing admin login');
    $checkWebAuthn(KiComEngramFirstPartyHostBridge::reviewApi(
        $host,$adminSession,$adminSid,$reviewHttp,$confirmJson
    )['http_status']===403,
        'admin cannot replay previously signed exact-record browser confirmation');

    $thirdEntry=[
        'namespace'=>'project','kind'=>'technical','body'=>$thirdEntryBody,
        'source_kind'=>'explicit_user','source_ref'=>$thirdEntrySource,
        'sensitivity'=>'ordinary',
    ];
    $nativeWrite=json_encode(['operation'=>'ENGRAM_REMEMBER','payload'=>$thirdEntry],
        JSON_THROW_ON_ERROR);
    $nativeSaved=KiComEngramNativeMemoryRoute::handle(
        ['REQUEST_METHOD'=>'POST','HTTPS'=>'on','CONTENT_TYPE'=>'application/json',
         'HTTP_X_KICOM_DEV_SESSION'=>$sessionA['session_id'],
         'HTTP_X_KICOM_DEV_TOKEN'=>$sessionA['token']],
        $nativeWrite,$host
    );
    $checkWebAuthn($nativeSaved['http_status']===200,
        'browser signed consent is actually consumed by separate native KiCom API write');
    $nativeRead=json_encode(['operation'=>'ENGRAM_RECALL','payload'=>[
        'namespace'=>'project','query'=>'lime engine','limit'=>3
    ]],JSON_THROW_ON_ERROR);
    $nativeRecalled=KiComEngramNativeMemoryRoute::handle(
        ['REQUEST_METHOD'=>'POST','HTTPS'=>'on','CONTENT_TYPE'=>'application/json',
         'HTTP_X_KICOM_DEV_SESSION'=>$secondSession['session_id'],
         'HTTP_X_KICOM_DEV_TOKEN'=>$secondSession['token']],
        $nativeRead,$host
    );
    $checkWebAuthn($nativeRecalled['http_status']===200
        && ($nativeRecalled['body']['records'][0]['body']??null)===$thirdEntryBody,
        'independent original KiCom DEV session recalls admin+signed-passkey-approved record');

    echo "KICOM_ENGRAM_REAL_WEBAUTHN_APPROVAL_TESTS_PASSED=$webAuthnChecks\n";
} finally {
    unset($newAdapter,$adapter,$manager,$controller,$registry,$passkeys);
    $cleanWebAuthn($root);
}
