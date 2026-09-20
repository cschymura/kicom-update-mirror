<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramAnchorVerifier.php';

/* Synthetic ephemeral Ed25519 signer exists ONLY inside isolated CI process. */
$anchorTests = 0;
function anchorCheck(bool $okay, string $label): void
{
    global $anchorTests;
    if (!$okay) throw new RuntimeException('FAIL '.$label);
    $anchorTests++;
    echo 'PASS '.$label."\n";
}
function anchorReject(callable $fn, string $label): void
{
    $refused=false;
    try { $fn(); } catch (RuntimeException|InvalidArgumentException $e) { $refused=true; }
    anchorCheck($refused,$label);
}
function anchorClear(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $name) {
        if ($name!=='.' && $name!=='..') anchorClear($path.'/'.$name);
    }
    @rmdir($path);
}
if (!function_exists('sodium_crypto_sign_keypair')) {
    throw new RuntimeException('Synthetic Ed25519 test requires libsodium');
}
$root=sys_get_temp_dir().'/engram-anchor-synthetic-'.bin2hex(random_bytes(12));
mkdir($root,0700);
foreach (['web','live','a','b','manifests'] as $dir) {
    mkdir($root.'/'.$dir,$dir==='web' ? 0755 : 0700);
}
$keyPair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keyPair);
$trustedPublic=bin2hex(sodium_crypto_sign_publickey($keyPair));
$issueSynthetic=static function (array $payload) use (&$secret): string {
    $message=json_encode($payload,
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    return json_encode([
        'payload'=>$payload,
        'signature'=>bin2hex(sodium_crypto_sign_detached($message,$secret))
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
};
$now=1800000000;
$storeId='synthetic-store-1';
try {
    $source=new KiComEngramStore($root.'/live',$root.'/web');
    $source->create('subject-a','project','technical',
        'SYNTHETIC SIGNED ANCHOR MEMORY','synthetic_test','fixture://anchor');
    $mirror=new KiComEngramMirrorSet(
        $root.'/a',$root.'/b',$root.'/manifests',$root.'/web'
    );
    $first=$mirror->capture($source);
    $payload=[
        'format'=>'engram-anchor-v1',
        'algorithm'=>'Ed25519',
        'context'=>'kicom-engram-private-mirror',
        'store_id'=>$storeId,
        'sequence'=>10,
        'manifest'=>$first['manifest'],
        'manifest_sha256'=>$first['manifest_sha256'],
        'parent_manifest_sha256'=>null,
        'issued_at'=>$now-60,
        'expires_at'=>null,
    ];
    $receipt=$issueSynthetic($payload);
    $signed=KiComEngramAnchorVerifier::inspectSignedMirror(
        $mirror,$receipt,$trustedPublic,$storeId,10,$now
    );
    anchorCheck($signed['signature_verified']
        && $signed['state']==='mirrored'
        && $signed['verified_mirrors']===2
        && $signed['sequence']===10,
        'ephemeral synthetic Ed25519 external anchor verifies immutable v1 mirror');
    anchorCheck(!str_contains(json_encode($signed,JSON_THROW_ON_ERROR),$root)
        && !array_key_exists('body',$signed)
        && !array_key_exists('signature',$signed),
        'verified result exposes no private path, memory body or raw signature');
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        $receipt,$trustedPublic,'different-store',10,$now
    ),'correctly signed receipt cannot cross a different trusted store scope');
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        $receipt,$trustedPublic,$storeId,11,$now
    ),'independently protected minimum sequence denies older receipt rollback');
    $other=sodium_crypto_sign_keypair();
    $wrongPublic=bin2hex(sodium_crypto_sign_publickey($other));
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        $receipt,$wrongPublic,$storeId,10,$now
    ),'wrong public key cannot verify original manifest anchor');
    $changed=$payload;
    $changed['manifest_sha256']=str_repeat('f',64);
    $modified=json_decode($receipt,true,8,JSON_THROW_ON_ERROR);
    $modified['payload']=$changed;
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        json_encode($modified,JSON_THROW_ON_ERROR),$trustedPublic,$storeId,10,$now
    ),'payload tampering without new external signature rejected');
    $changed=$payload;
    $changed['context']='foreign-project';
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        $issueSynthetic($changed),$trustedPublic,$storeId,10,$now
    ),'signed receipt for different purpose cannot authorize Engram');
    $changed=$payload;
    $changed['algorithm']='none';
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        $issueSynthetic($changed),$trustedPublic,$storeId,10,$now
    ),'signed algorithm downgrade rejected');
    $changed=$payload;
    $changed['expires_at']=$now;
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        $issueSynthetic($changed),$trustedPublic,$storeId,10,$now
    ),'signed expired receipt denied by trusted server clock');
    $changed=$payload;
    $changed['issued_at']=$now+61;
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        $issueSynthetic($changed),$trustedPublic,$storeId,10,$now
    ),'signed receipt issued in the future denied');
    $changed=$payload;
    $changed['issued_at']=$now-61;
    $changed['expires_at']=$now+10;
    anchorCheck(KiComEngramAnchorVerifier::verify(
        $issueSynthetic($changed),$trustedPublic,$storeId,10,$now
    )['verified'],'unexpired bounded synthetic receipt remains valid');
    $changed=$payload;
    unset($changed['context']);
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        $issueSynthetic($changed),$trustedPublic,$storeId,10,$now
    ),'missing signed context field rejected');
    $changed=$payload;
    $changed['unknown']='unexpected';
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        $issueSynthetic($changed),$trustedPublic,$storeId,10,$now
    ),'unknown signed receipt field rejected');
    $changed=$payload;
    $changed['parent_manifest_sha256']=str_repeat('0',64);
    anchorReject(static fn()=>KiComEngramAnchorVerifier::inspectSignedMirror(
        $mirror,$issueSynthetic($changed),$trustedPublic,$storeId,10,$now
    ),'v1 manifest must not claim an external parent anchor');
    $badReceipt=json_decode($receipt,true,8,JSON_THROW_ON_ERROR);
    $badReceipt['signature']=str_repeat('0',128);
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        json_encode($badReceipt,JSON_THROW_ON_ERROR),$trustedPublic,$storeId,10,$now
    ),'invalid Ed25519 signature rejected');
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        '{"payload":true}', $trustedPublic,$storeId,10,$now
    ),'malformed signed envelope rejected');
    $saved=json_decode(
        (string)file_get_contents($root.'/manifests/'.$first['manifest']),
        true,8,JSON_THROW_ON_ERROR
    );
    file_put_contents($root.'/a/'.$saved['snapshot'],'SYNTHETIC DAMAGE',FILE_APPEND);
    $repaired=$mirror->rebuildNewGeneration($first['manifest'],$first['manifest_sha256']);
    $v2=json_decode(
        (string)file_get_contents($root.'/manifests/'.$repaired['manifest']),
        true,8,JSON_THROW_ON_ERROR
    );
    $v2Payload=$payload;
    $v2Payload['sequence']=11;
    $v2Payload['manifest']=$repaired['manifest'];
    $v2Payload['manifest_sha256']=$repaired['manifest_sha256'];
    $v2Payload['parent_manifest_sha256']=$v2['parent_manifest_sha256'];
    $v2Receipt=$issueSynthetic($v2Payload);
    $verifiedV2=KiComEngramAnchorVerifier::inspectSignedMirror(
        $mirror,$v2Receipt,$trustedPublic,$storeId,11,$now
    );
    anchorCheck($verifiedV2['signature_verified']
        && $verifiedV2['state']==='mirrored'
        && $verifiedV2['sequence']===11
        && $verifiedV2['manifest']===$repaired['manifest'],
        'fresh externally signed v2 anchor verifies manifest and exact parent digest metadata');
    $badParent=$v2Payload;
    $badParent['parent_manifest_sha256']=str_repeat('a',64);
    anchorReject(static fn()=>KiComEngramAnchorVerifier::inspectSignedMirror(
        $mirror,$issueSynthetic($badParent),$trustedPublic,$storeId,11,$now
    ),'new valid signature cannot lie about current manifest parent digest');
    anchorReject(static fn()=>KiComEngramAnchorVerifier::verify(
        $receipt,$trustedPublic,$storeId,11,$now
    ),'after independent sequence floor advances old intact generation no longer current');
    $wrongManifest=$v2Payload;
    $wrongManifest['manifest']='mirror-'.bin2hex(random_bytes(16)).'.json';
    anchorReject(static fn()=>KiComEngramAnchorVerifier::inspectSignedMirror(
        $mirror,$issueSynthetic($wrongManifest),$trustedPublic,$storeId,11,$now
    ),'signed digest for a nonexistent manifest does not create a valid generation');
    file_put_contents($root.'/manifests/'.$repaired['manifest'],'SYNTHETIC MANIFEST ALTERATION',FILE_APPEND);
    anchorReject(static fn()=>KiComEngramAnchorVerifier::inspectSignedMirror(
        $mirror,$v2Receipt,$trustedPublic,$storeId,11,$now
    ),'signed receipt refuses locally tampered manifest content');
    anchorCheck($source->auditHistory()['revision_count']===1,
        'synthetic signature verification never writes active memory or host state');
    echo "KICOM_ENGRAM_ANCHOR_TESTS_PASSED=$anchorTests\n";
} finally {
    if (is_string($secret)) sodium_memzero($secret);
    unset($source,$mirror,$keyPair,$other);
    anchorClear($root);
}
