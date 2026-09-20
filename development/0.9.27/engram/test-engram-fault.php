<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramMirrorSet.php';

/**
 * Deterministic DEV-only interrupted-rebuild simulations.
 * Exceptions model abrupt interruption at stable checkpoints, not actual
 * process-kill/power-loss/fsync/host hardware failure.
 */
$faultTests = 0;
function faultCheck(bool $yes, string $label): void
{
    global $faultTests;
    if (!$yes) throw new RuntimeException('FAIL ' . $label);
    $faultTests++;
    echo 'PASS ' . $label . "\n";
}
function faultClean(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') faultClean($path.'/'.$entry);
    }
    @rmdir($path);
}
$expected = [
    'before-first-copy' => [0,0],
    'after-first-copy' => [1,0],
    'after-second-copy' => [2,0],
    'before-manifest-publish' => [2,0],
    'after-manifest-publish' => [2,1],
];
foreach ($expected as $interrupt => $orphanCounts) {
    $root = sys_get_temp_dir().'/engram-fault-synthetic-'.bin2hex(random_bytes(12));
    mkdir($root,0700);
    foreach (['web','live','a','b','manifests','fresh-restore'] as $n) {
        mkdir($root.'/'.$n,$n === 'web' ? 0755 : 0700);
    }
    try {
        $web = $root.'/web';
        $store = new KiComEngramStore($root.'/live',$web);
        $store->create('subject-a','project','technical',
            'SYNTHETIC INTERRUPTED REPAIR','synthetic_test','fixture://fault');
        $mirrors = new KiComEngramMirrorSet(
            $root.'/a',$root.'/b',$root.'/manifests',$web
        );
        $parent = $mirrors->capture($store);
        $parentJson = json_decode(
            (string)file_get_contents($root.'/manifests/'.$parent['manifest']),
            true,8,JSON_THROW_ON_ERROR
        );
        $parentA = $root.'/a/'.$parentJson['snapshot'];
        $parentB = $root.'/b/'.$parentJson['snapshot'];
        file_put_contents($parentA,'SYNTHETIC DAMAGE',FILE_APPEND);
        $oldBadHash = hash_file('sha256',$parentA);
        $oldGoodHash = hash_file('sha256',$parentB);
        $anchors = [['manifest'=>$parent['manifest'],'sha256'=>$parent['manifest_sha256']]];
        $stages = [];
        $failed = false;
        try {
            $mirrors->rebuildNewGeneration(
                $parent['manifest'],$parent['manifest_sha256'],
                static function (string $phase) use ($interrupt,&$stages): void {
                    $stages[] = $phase;
                    if ($phase === $interrupt) {
                        throw new RuntimeException('SYNTHETIC_INTERRUPTED_REBUILD');
                    }
                }
            );
        } catch (RuntimeException $e) {
            $failed = $e->getMessage() === 'SYNTHETIC_INTERRUPTED_REBUILD';
        }
        faultCheck($failed && in_array($interrupt,$stages,true),
            'deterministic interruption triggered at '.$interrupt);
        $inventory = $mirrors->inventoryUnanchoredArtifacts($anchors);
        faultCheck(
            $inventory['known_generations'] === 1
            && $inventory['unanchored_mirror_files'] === $orphanCounts[0]
            && $inventory['unanchored_manifest_files'] === $orphanCounts[1]
            && $inventory['auto_recovery_permitted'] === false,
            'read-only inventory records untrusted orphan counts at '.$interrupt
        );
        faultCheck(
            $mirrors->inspect($parent['manifest'],$parent['manifest_sha256'])['state'] === 'degraded'
            && hash_file('sha256',$parentA) === $oldBadHash
            && hash_file('sha256',$parentB) === $oldGoodHash,
            'interrupted rebuild preserves damaged and intact parent generation at '.$interrupt
        );
        // A crash after publishing a local manifest is still NOT independent
        // authorization to recover: the operator has no separately pinned digest.
        if ($interrupt === 'after-manifest-publish') {
            $all = scandir($root.'/manifests');
            $unknownNames = array_values(array_diff($all,
                ['.','..',$parent['manifest']]));
            faultCheck(count($unknownNames) === 1
                && is_file($root.'/manifests/'.$unknownNames[0])
                && $inventory['operator_review_required'],
                'locally published but unanchored manifest is quarantined, not promoted');
        }
        $resume = $mirrors->rebuildNewGeneration(
            $parent['manifest'],$parent['manifest_sha256']
        );
        $resumeStatus = $mirrors->inspect($resume['manifest'],$resume['manifest_sha256']);
        faultCheck(
            $resumeStatus['state'] === 'mirrored'
            && $resume['manifest'] !== $parent['manifest']
            && !$resume['independent_manifest_anchor_stored'],
            'explicit retry creates distinct fully verified generation at '.$interrupt
        );
        $currentAnchors = array_merge($anchors, [[
            'manifest'=>$resume['manifest'],
            'sha256'=>$resume['manifest_sha256']
        ]]);
        $remaining = $mirrors->inventoryUnanchoredArtifacts($currentAnchors);
        faultCheck(
            $remaining['unanchored_mirror_files'] === $orphanCounts[0]
            && $remaining['unanchored_manifest_files'] === $orphanCounts[1],
            'retry never overwrites or silently adopts prior orphan files at '.$interrupt
        );
        $restored = $mirrors->recover(
            $resume['manifest'],$resume['manifest_sha256'],$root.'/fresh-restore'
        );
        $reopened = new KiComEngramStore($root.'/fresh-restore',$web);
        faultCheck(
            $restored['restored']
            && $reopened->auditHistory()['revision_count'] === 1
            && count($reopened->search('subject-a','project','INTERRUPTED REPAIR')) === 1,
            'explicit retry restores original consistent synthetic history at '.$interrupt
        );
        unset($reopened,$restored,$mirrors,$store);
    } finally {
        unset($reopened,$restored,$mirrors,$store);
        faultClean($root);
    }
}
echo "KICOM_ENGRAM_FAULT_TESTS_PASSED=$faultTests\n";
