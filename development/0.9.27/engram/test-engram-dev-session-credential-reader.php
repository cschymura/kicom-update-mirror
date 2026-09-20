<?php
declare(strict_types=1);
if (!class_exists('KiComDevSessionManager', false)) {
    require_once __DIR__ . '/../../../source/0.9.26-r3/modules/dev/DevSession.php';
}
require_once __DIR__ . '/KiComEngramDevSessionCredentialReader.php';
require_once __DIR__ . '/KiComEngramVerifiedCredentialResolver.php';
require_once __DIR__ . '/KiComEngramDevMemoryAdapter.php';

$sessionCredentialChecks = 0;
$assertSession = static function(bool $okay, string $label) use (&$sessionCredentialChecks): void {
    if (!$okay) throw new RuntimeException('FAIL session credential reader: '.$label);
    ++$sessionCredentialChecks;
    echo "PASS real-session-reader ".$label."\n";
};
$removeSessionTest = static function(string $path) use (&$removeSessionTest): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') $removeSessionTest($path.'/'.$entry);
    }
    rmdir($path);
};
$root = sys_get_temp_dir().'/engram-real-session-reader-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root.'/web', 0755);
mkdir($root.'/private', 0700);
try {
    $sessionDir = $root.'/dev-sessions';
    $manager = new KiComDevSessionManager($sessionDir);
    $credentialA = 'synthetic_pk_credential_A_0011223344556677';
    $credentialB = 'synthetic_pk_credential_B_8899aabbccddeeff';
    $issuedA = $manager->issue(['auth_method'=>'passkey','credential_id'=>$credentialA, 'label'=>'Display name does not determine identity']);
    $issuedA2 = $manager->issue(['auth_method'=>'passkey','credential_id'=>$credentialA, 'label'=>'Another display label']);
    $issuedB = $manager->issue(['auth_method'=>'passkey','credential_id'=>$credentialB]);
    $issuedNotPasskey = $manager->issue(['auth_method'=>'test','credential_id'=>$credentialA]);
    $reader = new KiComEngramDevSessionCredentialReader($sessionDir);
    $authA = $manager->authenticate($issuedA['session_id'], $issuedA['token']);
    $authA2 = $manager->authenticate($issuedA2['session_id'], $issuedA2['token']);
    $authB = $manager->authenticate($issuedB['session_id'], $issuedB['token']);
    $authNotPasskey = $manager->authenticate($issuedNotPasskey['session_id'], $issuedNotPasskey['token']);

    $readA = $reader($authA);
    $assertSession(($readA['session_id'] ?? null) === $issuedA['session_id']
        && ($readA['credential_id'] ?? null) === $credentialA
        && ($readA['auth_method'] ?? null) === 'passkey',
        'reads credential from an authenticated real KiCom DEV session JSON record');
    $assertSession(($reader($authA2)['credential_id'] ?? null) === $credentialA,
        'separate session issued for same credential resolves identical credential ID');
    $assertSession($reader($authNotPasskey) === [],
        'legacy or non-passkey issued session cannot authenticate as Engram owner');
    $assertSession($reader(['ok'=>false,'scope'=>'dev','session_id'=>$issuedA['session_id']]) === [],
        'unverified callback denied');
    $assertSession($reader(['ok'=>true,'scope'=>'prod','session_id'=>$issuedA['session_id']]) === [],
        'wrong session scope denied');
    $assertSession($reader(['ok'=>true,'scope'=>'dev','session_id'=>'../path']) === [],
        'traversal-like session ID denied');
    $assertSession($reader(['ok'=>true,'scope'=>'dev','session_id'=>$issuedA['session_id']] + ['credential_id'=>$credentialB])['credential_id'] === $credentialA,
        'caller supplied credential field cannot override trusted session record');

    $ownerA = hash('sha256', $credentialA);
    $ownerB = hash('sha256', $credentialB);
    $registered = [
        $ownerA=>['enabled'=>true,'credential_fingerprint'=>$ownerA,
            'subject'=>'synthetic-person-a','namespaces'=>['project'],
            'engram_rights'=>['engram.read','engram.write']],
        $ownerB=>['enabled'=>true,'credential_fingerprint'=>$ownerB,
            'subject'=>'synthetic-person-b','namespaces'=>['project'],
            'engram_rights'=>['engram.read']],
    ];
    $lookup = static fn(string $fp): ?array => $registered[$fp] ?? null;
    $resolver = new KiComEngramVerifiedCredentialResolver($reader, $lookup);
    $assertSession($resolver($authA)['subject'] === $resolver($authA2)['subject'],
        'actual separate KiCom session records resolve same provisioned owner');
    $assertSession($resolver($authB)['subject'] !== $resolver($authA)['subject'],
        'distinct registered credential resolves distinct private-memory owner');

    $entry=['namespace'=>'project','kind'=>'technical',
        'body'=>'Synthetic teal tram persisted through private KiCom session reader.',
        'source_kind'=>'approved_summary','source_ref'=>'summary:actual-session-reader',
        'sensitivity'=>'ordinary'];
    $approved=false;
    $consent=static function(array $binding) use (&$approved,$entry): bool {
        if ($approved || ($binding['subject'] ?? null) !== 'synthetic-person-a'
            || ($binding['body_sha256'] ?? null) !== hash('sha256',$entry['body'])) return false;
        $approved=true;
        return true;
    };
    $memory = new KiComEngramDevMemoryAdapter($manager, $resolver, $consent,
        static fn(): KiComEngramStore => new KiComEngramStore($root.'/private',$root.'/web'));
    $headers = static fn(array $issued): array => [
        'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json',
        'HTTP_X_KICOM_DEV_SESSION'=>$issued['session_id'],
        'HTTP_X_KICOM_DEV_TOKEN'=>$issued['token'],
    ];
    $write=json_encode(['operation'=>'ENGRAM_REMEMBER','payload'=>$entry],JSON_THROW_ON_ERROR);
    $read=json_encode(['operation'=>'ENGRAM_RECALL',
        'payload'=>['namespace'=>'project','query'=>'teal tram','limit'=>5]],JSON_THROW_ON_ERROR);
    $assertSession($memory->handle($headers($issuedA),$write)['http_status']===200 && $approved,
        'actual session-backed owner stores approved synthetic Engram');
    $retrieved=$memory->handle($headers($issuedA2),$read);
    $assertSession($retrieved['http_status']===200
        && ($retrieved['body']['count'] ?? -1)===1
        && $retrieved['body']['records'][0]['body']===$entry['body'],
        'new independent KiCom DEV session uses credential binding to retrieve persisted Engram');
    $assertSession(($memory->handle($headers($issuedB),$read)['body']['count'] ?? -1)===0,
        'other verified passkey session cannot read first owner memory');
    $assertSession($memory->handle($headers($issuedNotPasskey),$read)['http_status']===403,
        'non-passkey DEV session denied from Engram despite valid bearer');

    $privateJson = $sessionDir.'/sessions/'.$issuedA2['session_id'].'.json';
    $oldMode = fileperms($privateJson)&0777;
    chmod($privateJson,0644);
    $assertSession($reader($authA2)===[],'overpermissive protected session record rejected');
    chmod($privateJson,$oldMode);
    $assertSession($reader($authA2)['credential_id']===$credentialA,
        'private record becomes readable again after permissions corrected');
    $manager->revoke($issuedA2['session_id']);
    $assertSession($reader($authA2)===[],
        'reader denies revoked session record even when holding older auth result');
    $assertSession($memory->handle($headers($issuedA2),$read)['http_status']===401,
        'revoked real DEV session blocked before private-memory retrieval');
    $assertSession($memory->handle($headers($issuedA),$read)['body']['count']===1,
        'active original owner session retains committed synthetic Engram');

    echo "KICOM_ENGRAM_DEV_SESSION_CREDENTIAL_READER_TESTS_PASSED=$sessionCredentialChecks\n";
} finally {
    unset($memory,$manager);
    $removeSessionTest($root);
}
