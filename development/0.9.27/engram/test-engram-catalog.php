<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramSignedCatalog.php';

$catalogTests=0;
function catalogCheck(bool $okay,string $label): void
{
    global $catalogTests;
    if (!$okay) throw new RuntimeException('FAIL '.$label);
    $catalogTests++;
    echo 'PASS '.$label."\n";
}
function catalogReject(callable $callback,string $label): void
{
    $denied=false;
    try { $callback(); } catch (RuntimeException|InvalidArgumentException $e) { $denied=true; }
    catalogCheck($denied,$label);
}
function catalogClear(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $file) {
        if ($file!=='.' && $file!=='..') catalogClear($path.'/'.$file);
    }
    @rmdir($path);
}
if (!function_exists('sodium_crypto_sign_keypair')) {
    throw new RuntimeException('Signed catalog tests require libsodium');
}
$root=sys_get_temp_dir().'/engram-catalog-synthetic-'.bin2hex(random_bytes(12));
mkdir($root,0700);
foreach (['web','live','a','b','manifests'] as $dir) {
    mkdir($root.'/'.$dir,$dir==='web' ? 0755 : 0700);
}
$pair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($pair);
$pk=bin2hex(sodium_crypto_sign_publickey($pair));
$now=1800000000;
$storeId='synthetic-catalog';
$issue=static function (
    array $generation,int $sequence,?string $parentDigest
) use (&$secret,$now,$storeId): string {
    $payload=[
        'format'=>'engram-anchor-v1',
        'algorithm'=>'Ed25519',
        'context'=>'kicom-engram-private-mirror',
        'store_id'=>$storeId,
        'sequence'=>$sequence,
        'manifest'=>$generation['manifest'],
        'manifest_sha256'=>$generation['manifest_sha256'],
        'parent_manifest_sha256'=>$parentDigest,
        'issued_at'=>$now-30,
        'expires_at'=>null,
    ];
    $canonical=json_encode($payload,
        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    return json_encode([
        'payload'=>$payload,
        'signature'=>bin2hex(sodium_crypto_sign_detached($canonical,$secret))
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
};
try {
    $store=new KiComEngramStore($root.'/live',$root.'/web');
    $store->create('subject-a','project','technical',
        'SYNTHETIC SIGNED LINEAGE','synthetic_test','fixture://lineage');
    $mirrors=new KiComEngramMirrorSet(
        $root.'/a',$root.'/b',$root.'/manifests',$root.'/web'
    );
    $rootGen=$mirrors->capture($store);
    $rootMeta=json_decode(
        (string)file_get_contents($root.'/manifests/'.$rootGen['manifest']),
        true,8,JSON_THROW_ON_ERROR
    );
    file_put_contents($root.'/a/'.$rootMeta['snapshot'],'SYNTHETIC DAMAGE',FILE_APPEND);
    $child=$mirrors->rebuildNewGeneration(
        $rootGen['manifest'],$rootGen['manifest_sha256']
    );
    $childMeta=json_decode(
        (string)file_get_contents($root.'/manifests/'.$child['manifest']),
        true,8,JSON_THROW_ON_ERROR
    );
    file_put_contents($root.'/a/'.$childMeta['snapshot'],'SYNTHETIC DAMAGE',FILE_APPEND);
    $head=$mirrors->rebuildNewGeneration(
        $child['manifest'],$child['manifest_sha256']
    );
    $r1=$issue($rootGen,20,null);
    $r2=$issue($child,21,$rootGen['manifest_sha256']);
    $r3=$issue($head,22,$child['manifest_sha256']);
    $all=[$r1,$r2,$r3];
    $status=KiComEngramSignedCatalog::inspect(
        $mirrors,$all,$pk,$storeId,22,$head['manifest'],$now
    );
    catalogCheck($status['signed_lineage_verified']
        && $status['verified_generations']===3
        && $status['current_sequence']===22
        && $status['current_state']==='mirrored'
        && $status['current_verified_mirrors']===2,
        'three independently signed generations link to exact trusted current head');
    catalogCheck($status['degraded_generations']===2
        && $status['unrecoverable_generations']===0
        && $status['operator_review_required']
        && !$status['auto_recovery_permitted']
        && !$status['complete_replica_inventory_proven'],
        'signed history audit reports degraded ancestors without promising complete inventory');
    catalogCheck(!str_contains(json_encode($status,JSON_THROW_ON_ERROR),$root)
        && !str_contains(json_encode($status,JSON_THROW_ON_ERROR),'SYNTHETIC SIGNED LINEAGE'),
        'catalog never returns private paths or memory bodies');
    catalogReject(static fn()=>KiComEngramSignedCatalog::inspect(
        $mirrors,[$r2,$r3],$pk,$storeId,22,$head['manifest'],$now
    ),'missing signed v1 ancestor blocks dependent v2 lineage');
    catalogReject(static fn()=>KiComEngramSignedCatalog::inspect(
        $mirrors,[$r1,$r3],$pk,$storeId,22,$head['manifest'],$now
    ),'missing intermediate v2 signed parent blocks current head');
    catalogReject(static fn()=>KiComEngramSignedCatalog::inspect(
        $mirrors,[$r2,$r1,$r3],$pk,$storeId,22,$head['manifest'],$now
    ),'out-of-order signatures cannot silently promote a later generation');
    catalogReject(static fn()=>KiComEngramSignedCatalog::inspect(
        $mirrors,[$r1,$r2,$r2,$r3],$pk,$storeId,22,$head['manifest'],$now
    ),'duplicate receipt sequence and generation are rejected');
    catalogReject(static fn()=>KiComEngramSignedCatalog::inspect(
        $mirrors,[$r1,$r2,$r3],$pk,$storeId,23,$head['manifest'],$now
    ),'external minimum sequence blocks old current head');
    catalogReject(static fn()=>KiComEngramSignedCatalog::inspect(
        $mirrors,$all,$pk,$storeId,22,$child['manifest'],$now
    ),'supplied signed catalog cannot choose a head different from operator-pinned head');
    catalogReject(static fn()=>KiComEngramSignedCatalog::inspect(
        $mirrors,$all,$pk,'foreign-synthetic-store',22,$head['manifest'],$now
    ),'cross-store signed receipts cannot be merged');
    $wrong=$issue($child,21,str_repeat('a',64));
    catalogReject(static fn()=>KiComEngramSignedCatalog::inspect(
        $mirrors,[$r1,$wrong,$r3],$pk,$storeId,22,$head['manifest'],$now
    ),'valid signature over incorrect v2 parent digest does not prove lineage');
    $fakeParentGen=['manifest'=>$head['manifest'],
        'manifest_sha256'=>$head['manifest_sha256']];
    $unlinked=$issue($fakeParentGen,23,$rootGen['manifest_sha256']);
    catalogReject(static fn()=>KiComEngramSignedCatalog::inspect(
        $mirrors,[$r1,$r2,$unlinked],$pk,$storeId,23,$head['manifest'],$now
    ),'signed parent reference must match actual immutable head metadata');
    $unknown='engram-'.bin2hex(random_bytes(16)).'.sqlite';
    file_put_contents($root.'/a/'.$unknown,'SYNTHETIC UNANCHORED ORPHAN');
    $status2=KiComEngramSignedCatalog::inspect(
        $mirrors,$all,$pk,$storeId,22,$head['manifest'],$now
    );
    catalogCheck($status2['signed_lineage_verified']
        && !$status2['complete_replica_inventory_proven'],
        'verified signed chain does not falsely attest absence of unknown replica files');
    $inventory=$mirrors->inventoryUnanchoredArtifacts([
        ['manifest'=>$rootGen['manifest'],'sha256'=>$rootGen['manifest_sha256']],
        ['manifest'=>$child['manifest'],'sha256'=>$child['manifest_sha256']],
        ['manifest'=>$head['manifest'],'sha256'=>$head['manifest_sha256']]
    ]);
    catalogCheck($inventory['unanchored_mirror_files']===1
        && $inventory['operator_review_required'],
        'separate operator replica inventory identifies unanchored file without promotion/deletion');
    $denied=$all;
    $tampered=json_decode($r3,true,8,JSON_THROW_ON_ERROR);
    $tampered['payload']['sequence']=99;
    $denied[2]=json_encode($tampered,JSON_THROW_ON_ERROR);
    catalogReject(static fn()=>KiComEngramSignedCatalog::inspect(
        $mirrors,$denied,$pk,$storeId,22,$head['manifest'],$now
    ),'modified head sequence without independent signature cannot be promoted');
    catalogCheck($store->auditHistory()['revision_count']===1,
        'signed catalog audit never modifies source memory or copies');
    echo "KICOM_ENGRAM_CATALOG_TESTS_PASSED=$catalogTests\n";
} finally {
    if (is_string($secret)) sodium_memzero($secret);
    unset($store,$mirrors,$pair);
    catalogClear($root);
}
