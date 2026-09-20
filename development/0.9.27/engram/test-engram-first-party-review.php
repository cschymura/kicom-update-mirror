<?php
declare(strict_types=1);
if (!class_exists('KiComDevSessionManager',false)) {
    require_once __DIR__.'/../../../source/0.9.26-r3/modules/dev/DevSession.php';
}
require_once __DIR__.'/KiComEngramFirstPartyReview.php';
require_once __DIR__.'/KiComEngramDevMemoryAdapter.php';

$reviewChecks=0;
$reviewCheck=static function(bool $ok,string $label)use(&$reviewChecks):void {
    if (!$ok) throw new RuntimeException('FAIL first-party review: '.$label);
    ++$reviewChecks;echo "PASS human-review ".$label."\n";
};
$reviewDeny=static function(callable $fn,string $label)use($reviewCheck):void {
    $denied=false;
    try{$fn();}catch(RuntimeException|InvalidArgumentException|PDOException $e){$denied=true;}
    $reviewCheck($denied,$label);
};
$reviewClean=static function(string $path)use(&$reviewClean):void{
    if(is_link($path)||is_file($path)){unlink($path);return;}
    if(!is_dir($path))return;
    foreach(scandir($path)as $name)if($name!=='.'&&$name!=='..')$reviewClean($path.'/'.$name);
    rmdir($path);
};
$root=sys_get_temp_dir().'/engram-first-party-review-'.bin2hex(random_bytes(8));
mkdir($root,0700);mkdir($root.'/web',0755);mkdir($root.'/private',0700);
mkdir($root.'/private/consent',0700);mkdir($root.'/private/drafts',0700);
try {
    $entry=[
        'namespace'=>'project','kind'=>'technical',
        'body'=>'Synthetic <script>alert("untrusted")</script> orange train & station.',
        'source_kind'=>'approved_summary',
        'source_ref'=>'summary:first-party-synthetic-review','sensitivity'=>'ordinary',
    ];
    $binding=[
        'subject'=>'synthetic-owner','namespace'=>'project','kind'=>'technical',
        'body_sha256'=>hash('sha256',$entry['body']),
        'source_kind'=>'approved_summary',
        'source_ref_sha256'=>hash('sha256',$entry['source_ref']),
        'sensitivity'=>'ordinary',
    ];
    $reviewAttested=false;
    $trustedReview=static function(array $candidate)use(&$reviewAttested,$binding):bool {
        return $reviewAttested && $candidate===$binding;
    };
    $ledger=new KiComEngramPrivateConsentLedger(
        $root.'/private/consent',$root.'/web',$trustedReview
    );
    $trustedOwner=[
        'verified'=>true,'first_party_browser'=>true,'subject'=>'synthetic-owner',
        'namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']
    ];
    $humanSessions=[
        'browser-a'=>['verified'=>true,'first_party_browser'=>true,
            'fresh_passkey_assertion'=>false,'subject'=>'synthetic-owner'],
        'browser-foreign'=>['verified'=>true,'first_party_browser'=>true,
            'fresh_passkey_assertion'=>true,'subject'=>'synthetic-foreign'],
    ];
    $verifyBrowser=static function(array $ctx)use(&$humanSessions):array {
        return $humanSessions[$ctx['browser_session']??'']??['verified'=>false];
    };
    $flow=new KiComEngramFirstPartyReview(
        $root.'/private/drafts',$root.'/web',$ledger,$verifyBrowser
    );
    $id=$flow->stage($trustedOwner,$entry);
    $reviewCheck(strlen($id)===32,'trusted owner staged exactly one synthetic record');
    $reviewDeny(static fn()=> $flow->render($id,['browser_session'=>'browser-foreign']),
        'other first-party authenticated owner cannot view pending private text');
    $reviewDeny(static fn()=> $flow->render($id,['browser_session'=>'unverified']),
        'unauthenticated client cannot view pending review');
    $html=$flow->render($id,['browser_session'=>'browser-a']);
    $reviewCheck(str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>')
        && str_contains($html,'&amp; station'),
        'first-party review renders escaped literal private text, not executable HTML');
    $reviewCheck(str_contains($html,'<form method="post"')
        && str_contains($html,'value="approve_one"')
        && !str_contains($html,'name="body"'),
        'single-record consent form contains no client-editable memory body');
    preg_match('/name="csrf" value="([a-f0-9]{64})"/',$html,$m);
    $csrf=$m[1]??'';
    $reviewCheck(strlen($csrf)===64,'render creates distinct unpredictable CSRF token');
    $post=['review_id'=>$id,'csrf'=>$csrf,'decision'=>'approve_one'];
    $reviewDeny(static fn()=> $flow->confirm($post,['browser_session'=>'browser-a']),
        'ordinary logged-in browser alone cannot mint consent without fresh passkey assertion');
    $humanSessions['browser-a']['fresh_passkey_assertion']=true;
    $reviewDeny(static fn()=> $flow->confirm($post,['browser_session'=>'browser-foreign']),
        'fresh foreign passkey assertion cannot approve another owner memory');
    $reviewDeny(static fn()=> $flow->confirm($post+['approved'=>true],['browser_session'=>'browser-a']),
        'client-submitted approved flag forbidden');
    $wrong=$post;$wrong['csrf']=str_repeat('0',64);
    $reviewDeny(static fn()=> $flow->confirm($wrong,['browser_session'=>'browser-a']),
        'CSRF mismatch cannot approve a memory');
    $reviewDeny(static fn()=> $flow->confirm($post,['browser_session'=>'browser-a']),
        'fresh authentication cannot override absent independent consent-review trust');
    $reviewAttested=true;
    $approved=$flow->confirm($post,['browser_session'=>'browser-a']);
    $reviewCheck(($approved['ok']??false)===true
        && ($approved['review_id']??null)===$id,
        'fresh independently attested same-owner browser action issues exact-record consent');
    $reviewDeny(static fn()=> $flow->confirm($post,['browser_session'=>'browser-a']),
        'same rendered form cannot issue a second approval');
    $reviewDeny(static fn()=> $flow->render($id,['browser_session'=>'browser-a']),
        'approved draft no longer renders private text');

    $manager=new KiComDevSessionManager($root.'/sessions');
    $a=$manager->issue(['auth_method'=>'passkey','credential_id'=>'synthetic-reviewed-credential']);
    $ownerSessions=[
        $a['session_id']=>[
            'verified'=>true,'subject'=>'synthetic-owner',
            'namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']
        ]
    ];
    $identity=static function(array $auth)use(&$ownerSessions):array{
        return $ownerSessions[$auth['session_id']]??['verified'=>false];
    };
    $memory=new KiComEngramDevMemoryAdapter(
        $manager,$identity,[$ledger,'consume'],
        static fn():KiComEngramStore=>new KiComEngramStore($root.'/private',$root.'/web')
    );
    $headers=static fn(array $s):array=>[
        'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json',
        'HTTP_X_KICOM_DEV_SESSION'=>$s['session_id'],
        'HTTP_X_KICOM_DEV_TOKEN'=>$s['token']
    ];
    $write=json_encode(['operation'=>'ENGRAM_REMEMBER','payload'=>$entry],JSON_THROW_ON_ERROR);
    $read=json_encode(['operation'=>'ENGRAM_RECALL','payload'=>[
        'namespace'=>'project','query'=>'orange train','limit'=>3
    ]],JSON_THROW_ON_ERROR);
    $saved=$memory->handle($headers($a),$write);
    $reviewCheck(($saved['body']['code']??null)==='ENGRAM_REMEMBER_OK',
        'first-party browser approval is actually consumed by private memory write');
    $reviewCheck($memory->handle($headers($a),$write)['http_status']===403,
        'consumed human review cannot be replayed for another write');
    $a2=$manager->issue(['auth_method'=>'passkey','credential_id'=>'synthetic-reviewed-credential']);
    $ownerSessions[$a2['session_id']]=$ownerSessions[$a['session_id']];
    $recalled=(new KiComEngramDevMemoryAdapter(
        $manager,$identity,[$ledger,'consume'],
        static fn():KiComEngramStore=>new KiComEngramStore($root.'/private',$root.'/web')
    ))->handle($headers($a2),$read);
    $reviewCheck(($recalled['body']['records'][0]['body']??null)===$entry['body'],
        'fresh session retrieves exactly the human-reviewed synthetic memory');

    $reviewDeny(static fn()=> $flow->stage($trustedOwner+['override'=>'anything'],
        $entry+['subject'=>'synthetic-foreign']),
        'client-supplied subject cannot enter staged memory');
    $readOnly=$trustedOwner;$readOnly['engram_rights']=['engram.read'];
    $reviewDeny(static fn()=> $flow->stage($readOnly,$entry),
        'read-only actor cannot stage a write for approval');
    echo "KICOM_ENGRAM_FIRST_PARTY_REVIEW_TESTS_PASSED=$reviewChecks\n";
} finally {
    unset($memory,$manager,$flow,$ledger);
    $reviewClean($root);
}
