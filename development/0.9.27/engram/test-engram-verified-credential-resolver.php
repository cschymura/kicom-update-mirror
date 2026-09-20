<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramVerifiedCredentialResolver.php';
require_once __DIR__ . '/KiComEngramDevMemoryAdapter.php';

$ownerChecks = 0;
$ownerCheck = static function (bool $ok, string $label) use (&$ownerChecks): void {
    if (!$ok) throw new RuntimeException('FAIL verified credential resolver: '.$label);
    $ownerChecks++;
    echo "PASS owner resolver ".$label."\n";
};
$ownerClean = static function (string $path) use (&$ownerClean): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') $ownerClean($path.'/'.$entry);
    }
    rmdir($path);
};
$root = sys_get_temp_dir().'/engram-credential-identity-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root.'/web', 0755);
mkdir($root.'/private', 0700);
try {
    $manager = new KiComDevSessionManager($root.'/sessions');
    $credentialA = 'synpasskey_credential_AA_0123456789';
    $credentialB = 'synpasskey_credential_BB_0123456789';
    $sA = $manager->issue(['auth_method'=>'passkey', 'credential_id'=>$credentialA]);
    $sB = $manager->issue(['auth_method'=>'passkey', 'credential_id'=>$credentialB]);
    $sOther = $manager->issue(['auth_method'=>'passkey', 'credential_id'=>'synpasskey_other_0123456789']);
    $sNoPasskey = $manager->issue(['auth_method'=>'other', 'credential_id'=>$credentialA]);
    $authA = $manager->authenticate($sA['session_id'], $sA['token']);
    $authB = $manager->authenticate($sB['session_id'], $sB['token']);
    $authOther = $manager->authenticate($sOther['session_id'], $sOther['token']);
    $authNoPasskey = $manager->authenticate($sNoPasskey['session_id'], $sNoPasskey['token']);

    // Synthetic stand-in for a FUTURE server-only reader of KiCom's protected,
    // already-authenticated session record. The HTTP client cannot populate it.
    $sessionRecords = [
        $sA['session_id'] => ['session_id'=>$sA['session_id'], 'auth_method'=>'passkey', 'credential_id'=>$credentialA],
        $sB['session_id'] => ['session_id'=>$sB['session_id'], 'auth_method'=>'passkey', 'credential_id'=>$credentialB],
        $sOther['session_id'] => ['session_id'=>$sOther['session_id'], 'auth_method'=>'passkey', 'credential_id'=>'synpasskey_other_0123456789'],
        $sNoPasskey['session_id'] => ['session_id'=>$sNoPasskey['session_id'], 'auth_method'=>'other', 'credential_id'=>$credentialA]
    ];
    $recordReads = 0;
    $readServerSession = static function(array $auth) use (&$sessionRecords, &$recordReads): ?array {
        $recordReads++;
        return $sessionRecords[$auth['session_id']] ?? null;
    };
    $fingerprintA = hash('sha256', $credentialA);
    $fingerprintB = hash('sha256', $credentialB);
    $owners = [
        $fingerprintA => ['enabled'=>true, 'credential_fingerprint'=>$fingerprintA,
            'subject'=>'synthetic-owner-a', 'namespaces'=>['project'], 'engram_rights'=>['engram.read','engram.write']],
        $fingerprintB => ['enabled'=>true, 'credential_fingerprint'=>$fingerprintB,
            'subject'=>'synthetic-owner-a', 'namespaces'=>['project'], 'engram_rights'=>['engram.read']]
    ];
    $lookupOwner = static fn(string $fingerprint): ?array => $owners[$fingerprint] ?? null;
    $resolver = new KiComEngramVerifiedCredentialResolver($readServerSession, $lookupOwner);
    $a = $resolver($authA);
    $ownerCheck(($a['verified'] ?? false) === true
        && $a['subject'] === 'synthetic-owner-a'
        && !array_key_exists('credential_id', $a)
        && !array_key_exists('credential_fingerprint', $a),
        'server-side passkey fingerprint resolves a provisioned owner without credential disclosure');
    $b = $resolver($authB);
    $ownerCheck($b['subject'] === $a['subject']
        && $b['engram_rights'] === ['engram.read'],
        'second passkey maps to same approved subject with independently restricted rights');
    $ownerCheck(($resolver($authOther)['verified'] ?? true) === false,
        'unknown credential does not become a private-memory owner');
    $ownerCheck(($resolver($authNoPasskey)['verified'] ?? true) === false,
        'non-passkey issued session denied even when credential text matches');
    $ownerCheck(($resolver(['ok'=>false, 'scope'=>'dev', 'session_id'=>$sA['session_id']])['verified'] ?? true) === false,
        'unverified session denied before protected record callback');
    $ownerCheck(($resolver(['ok'=>true,'scope'=>'prod','session_id'=>$sA['session_id']])['verified'] ?? true) === false,
        'incorrect session scope denied');
    $ownerCheck(($resolver($authA + ['subject'=>'synthetic-other', 'engram_rights'=>['engram.write']]))['subject'] === $a['subject'],
        'caller-supplied subject and rights cannot override protected registry');
    $old = $sessionRecords[$sA['session_id']];
    $sessionRecords[$sA['session_id']]['session_id'] = $sB['session_id'];
    $ownerCheck(($resolver($authA)['verified'] ?? true) === false,
        'mismatched protected session ID denied');
    $sessionRecords[$sA['session_id']] = $old;
    $owners[$fingerprintA]['enabled'] = false;
    // New callback captures the updated immutable synthetic registry value.
    $disabled = new KiComEngramVerifiedCredentialResolver($readServerSession,
        static fn(string $fp): ?array => $owners[$fp] ?? null);
    $ownerCheck(($disabled($authA)['verified'] ?? true) === false,
        'disabled owner mapping denies previously valid credential');
    $owners[$fingerprintA]['enabled'] = true;
    $owners[$fingerprintA]['engram_rights'] = ['engram.read','production.install'];
    $invalidRights = new KiComEngramVerifiedCredentialResolver($readServerSession,
        static fn(string $fp): ?array => $owners[$fp] ?? null);
    $ownerCheck(($invalidRights($authA)['verified'] ?? true) === false,
        'unlisted privilege cannot be derived from credential mapping');
    $owners[$fingerprintA]['engram_rights'] = ['engram.read','engram.write'];

    // Drive real DEV-22 request adapter with resolver from server-only
    // synthetic credential+owner tables, NOT a caller-provided subject callback.
    $resolver = new KiComEngramVerifiedCredentialResolver($readServerSession,
        static fn(string $fp): ?array => $owners[$fp] ?? null);
    $entry = [
        'namespace'=>'project', 'kind'=>'technical',
        'body'=>'Synthetic golden carriage persists across passkey sessions.',
        'source_kind'=>'approved_summary', 'source_ref'=>'summary:verified-owner-roundtrip',
        'sensitivity'=>'ordinary'
    ];
    $consumed = false;
    $approval = static function(array $binding) use (&$consumed, $entry): bool {
        if ($consumed || ($binding['subject'] ?? '') !== 'synthetic-owner-a'
            || ($binding['body_sha256'] ?? '') !== hash('sha256', $entry['body'])) return false;
        $consumed = true;
        return true;
    };
    $memoryAdapter = new KiComEngramDevMemoryAdapter($manager, $resolver, $approval,
        static fn(): KiComEngramStore => new KiComEngramStore($root.'/private', $root.'/web'));
    $httpA = [
        'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json',
        'HTTP_X_KICOM_DEV_SESSION'=>$sA['session_id'],
        'HTTP_X_KICOM_DEV_TOKEN'=>$sA['token']
    ];
    $httpB = $httpA;
    $httpB['HTTP_X_KICOM_DEV_SESSION'] = $sB['session_id'];
    $httpB['HTTP_X_KICOM_DEV_TOKEN'] = $sB['token'];
    $write = json_encode(['operation'=>'ENGRAM_REMEMBER','payload'=>$entry], JSON_THROW_ON_ERROR);
    $read = json_encode(['operation'=>'ENGRAM_RECALL',
        'payload'=>['namespace'=>'project','query'=>'golden carriage','limit'=>5]], JSON_THROW_ON_ERROR);
    $resultWrite = $memoryAdapter->handle($httpA, $write);
    $ownerCheck($resultWrite['http_status'] === 200 && $consumed,
        'credential-bound session A stores one approved synthetic memory');
    $resultRead = $memoryAdapter->handle($httpB, $read);
    $ownerCheck($resultRead['http_status'] === 200
        && $resultRead['body']['count'] === 1
        && $resultRead['body']['records'][0]['body'] === $entry['body'],
        'independent credential B with same trusted owner retrieves persisted memory');
    $ownerCheck($memoryAdapter->handle($httpB, $write)['http_status'] === 403,
        'read-only second credential cannot store memory');
    $httpOther = $httpA;
    $httpOther['HTTP_X_KICOM_DEV_SESSION'] = $sOther['session_id'];
    $httpOther['HTTP_X_KICOM_DEV_TOKEN'] = $sOther['token'];
    $ownerCheck($memoryAdapter->handle($httpOther, $read)['http_status'] === 403,
        'valid DEV session with unknown owner cannot read memory');
    $manager->revoke($sA['session_id']);
    $ownerCheck($memoryAdapter->handle($httpA, $read)['http_status'] === 401,
        'revoked credential-bound session cannot use memory adapter');
    echo "KICOM_ENGRAM_VERIFIED_CREDENTIAL_RESOLVER_TESTS_PASSED=$ownerChecks\n";
} finally {
    unset($memoryAdapter, $manager);
    $ownerClean($root);
}
