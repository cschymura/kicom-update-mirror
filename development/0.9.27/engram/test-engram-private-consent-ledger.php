<?php
declare(strict_types=1);
if (!class_exists('KiComDevSessionManager', false)) {
    require_once __DIR__ . '/../../../source/0.9.26-r3/modules/dev/DevSession.php';
}
require_once __DIR__ . '/KiComEngramPrivateConsentLedger.php';
require_once __DIR__ . '/KiComEngramDevMemoryAdapter.php';

$consentChecks = 0;
$consentCheck = static function(bool $ok,string $label) use (&$consentChecks): void {
    if (!$ok) throw new RuntimeException('FAIL private consent ledger: '.$label);
    ++$consentChecks;
    echo "PASS consent ".$label."\n";
};
$consentReject = static function(callable $fn,string $label) use ($consentCheck): void {
    $rejected=false;
    try { $fn(); } catch (RuntimeException|InvalidArgumentException|PDOException $e) { $rejected=true; }
    $consentCheck($rejected,$label);
};
$consentClean = static function(string $path) use (&$consentClean): void {
    if (is_link($path)||is_file($path)) { unlink($path); return; }
    if(!is_dir($path)) return;
    foreach(scandir($path) as $name) if ($name!=='.' && $name!=='..') $consentClean($path.'/'.$name);
    rmdir($path);
};
$root=sys_get_temp_dir().'/engram-consent-ledger-'.bin2hex(random_bytes(8));
mkdir($root,0700); mkdir($root.'/web',0755);mkdir($root.'/private',0700);mkdir($root.'/private/consent',0700);
try {
    $entry=[
        'namespace'=>'project','kind'=>'technical',
        'body'=>'Synthetic amber station platform retains an authorized memory.',
        'source_kind'=>'approved_summary','source_ref'=>'summary:consent-ledger-test',
        'sensitivity'=>'ordinary',
    ];
    $binding=[
        'subject'=>'synthetic-subject','namespace'=>'project','kind'=>'technical',
        'body_sha256'=>hash('sha256',$entry['body']),
        'source_kind'=>'approved_summary',
        'source_ref_sha256'=>hash('sha256',$entry['source_ref']),
        'sensitivity'=>'ordinary',
    ];
    $reviewed=false;
    $trustedReview=static function(array $candidate) use (&$reviewed,$binding): bool {
        return $reviewed && $candidate===$binding;
    };
    $ledger=new KiComEngramPrivateConsentLedger($root.'/private/consent',$root.'/web',$trustedReview);
    $consentReject(static fn()=> $ledger->issue($binding), 'issue denied without independent human-reviewed callback');
    $consentCheck(!$ledger->consume($binding), 'unapproved memory has no usable consent');
    $reviewed=true;
    $receipt=$ledger->issue($binding,300);
    $consentCheck(strlen($receipt['receipt_id'])===32 && $receipt['expires_at']>time(),
        'trusted review creates a bounded private single-use receipt');
    $consentReject(static fn()=> $ledger->issue($binding),
        'cannot stage two concurrent unconsumed approvals for one exact record');
    $other=$binding;
    $other['body_sha256']=hash('sha256','different synthetic record');
    $consentCheck(!$ledger->consume($other),'different body cannot consume approved receipt');
    $other=$binding;$other['subject']='synthetic-other';
    $consentCheck(!$ledger->consume($other),'different identity cannot consume receipt');
    $other=$binding;$other['namespace']='another';
    $consentCheck(!$ledger->consume($other),'different namespace cannot consume receipt');
    $other=$binding;$other['source_ref_sha256']=hash('sha256','different source');
    $consentCheck(!$ledger->consume($other),'different provenance cannot consume receipt');
    $consentCheck($ledger->consume($binding),'approved exact record atomically consumes receipt');
    $consentCheck(!$ledger->consume($binding),'replay cannot use consumed approval');
    $consentReject(static fn()=> $ledger->issue($binding,10),'too-short consent validity rejected');
    $consentReject(static fn()=> $ledger->issue($binding+['client_approved'=>true]),
        'client-supplied approval field rejected');
    $consentReject(static fn()=> $ledger->issue(array_diff_key($binding,['subject'=>true])),
        'incomplete record binding rejected');

    $reviewed=false;
    $ledger2=new KiComEngramPrivateConsentLedger(
        $root.'/private/consent',$root.'/web',
        static fn(array $candidate):bool => false
    );
    $consentCheck(!$ledger2->consume($binding),'separate process-style instance cannot resurrect approval');
    $reviewed=true;
    $ledger->issue($binding);
    $consentCheck($ledger2->consume($binding),'fresh independent ledger instance can consume a valid receipt');
    $consentCheck(!$ledger->consume($binding),'consumption by second instance prevents first instance replay');

    // Integrate the actual consent ledger with the prior DEV-22 memory route.
    $sessions=new KiComDevSessionManager($root.'/sessions');
    $a=$sessions->issue(['auth_method'=>'passkey','credential_id'=>'synthetic-consent-key-a']);
    $b=$sessions->issue(['auth_method'=>'passkey','credential_id'=>'synthetic-consent-key-b']);
    $actors=[
        $a['session_id']=>['verified'=>true,'subject'=>'synthetic-subject',
            'namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']],
        $b['session_id']=>['verified'=>true,'subject'=>'synthetic-other',
            'namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']],
    ];
    $identity=static function(array $auth)use (&$actors):array { return $actors[$auth['session_id']]??['verified'=>false]; };
    $memory=new KiComEngramDevMemoryAdapter(
        $sessions,$identity,[$ledger,'consume'],
        static fn():KiComEngramStore=>new KiComEngramStore($root.'/private',$root.'/web')
    );
    $headers=static fn(array $issued):array=>[
        'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json',
        'HTTP_X_KICOM_DEV_SESSION'=>$issued['session_id'],'HTTP_X_KICOM_DEV_TOKEN'=>$issued['token']
    ];
    $write=json_encode(['operation'=>'ENGRAM_REMEMBER','payload'=>$entry],JSON_THROW_ON_ERROR);
    $read=json_encode(['operation'=>'ENGRAM_RECALL',
        'payload'=>['namespace'=>'project','query'=>'amber station','limit'=>3]],JSON_THROW_ON_ERROR);
    $consentCheck($memory->handle($headers($a),$write)['http_status']===403,
        'memory write without a current reviewed exact-record receipt denied');
    $reviewed=true;
    $ledger->issue($binding);
    $saved=$memory->handle($headers($a),$write);
    $consentCheck($saved['http_status']===200 && ($saved['body']['revision']??null)===1,
        'ledger-approved exact record is persistently written by original memory route');
    $consentCheck($memory->handle($headers($a),$write)['http_status']===403,
        'duplicate memory write without new review denied');
    $consentCheck($memory->handle($headers($b),$read)['body']['count']===0,
        'different subject cannot retrieve ledger-approved private memory');
    $newA=$sessions->issue(['auth_method'=>'passkey','credential_id'=>'synthetic-consent-key-a']);
    $actors[$newA['session_id']]=$actors[$a['session_id']];
    $fetched=$memory->handle($headers($newA),$read);
    $consentCheck($fetched['http_status']===200
        && ($fetched['body']['records'][0]['body']??null)===$entry['body'],
        'independent authenticated new session retrieves approved persistent memory');

    chmod($root.'/private/consent',0755);
    $consentReject(static fn()=> $ledger->consume($binding),
        'consent ledger refuses directory permission downgrade');
    chmod($root.'/private/consent',0700);
    $consentCheck($memory->handle($headers($newA),$read)['body']['count']===1,
        'store survives separate consent-directory permission rejection');

    $symlink=$root.'/private/alias';
    symlink($root.'/private/consent',$symlink);
    $consentReject(static fn()=>new KiComEngramPrivateConsentLedger($symlink,$root.'/web',$trustedReview),
        'symlinked consent directory forbidden');
    $consentReject(static fn()=>new KiComEngramPrivateConsentLedger($root.'/web',$root.'/web',$trustedReview),
        'public webroot consent directory forbidden');
    echo "KICOM_ENGRAM_PRIVATE_CONSENT_LEDGER_TESTS_PASSED=$consentChecks\n";
} finally {
    unset($memory,$sessions,$ledger,$ledger2);
    $consentClean($root);
}
