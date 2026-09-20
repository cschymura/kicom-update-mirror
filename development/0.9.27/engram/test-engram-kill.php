<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramMirrorSet.php';
/**
 * Synthetic subprocess crash test; actual POSIX SIGKILL at deterministic
 * phase markers. Requires process-control support in isolated CI runner.
 * No real host / memory / credentials.
 */
$killTests = 0;
function killCheck(bool $yes, string $label): void
{
    global $killTests;
    if (!$yes) throw new RuntimeException('FAIL ' . $label);
    $killTests++;
    echo 'PASS ' . $label . "\n";
}
function killClear(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $n) {
        if ($n !== '.' && $n !== '..') killClear($path.'/'.$n);
    }
    @rmdir($path);
}
if (!function_exists('proc_open') || !function_exists('proc_terminate')
    || PHP_OS_FAMILY !== 'Linux') {
    throw new RuntimeException('Synthetic SIGKILL test requires Linux PHP subprocess support');
}
$killStages = [
    'before-first-copy'=>[0,0],
    'during-first-copy'=>[1,0],
    'after-first-copy'=>[1,0],
    'during-second-copy'=>[2,0],
    'after-second-copy'=>[2,0],
    'before-manifest-publish'=>[2,0],
    'after-manifest-publish'=>[2,1],
];
foreach ($killStages as $stage=>$expected) {
    $root = sys_get_temp_dir().'/engram-kill-synthetic-'.bin2hex(random_bytes(12));
    mkdir($root,0700);
    foreach (['web','live','a','b','manifests','recovered'] as $dir) {
        mkdir($root.'/'.$dir,$dir === 'web' ? 0755 : 0700);
    }
    try {
        $web = $root.'/web';
        $store = new KiComEngramStore($root.'/live',$web);
        $store->create('subject-a','project','technical',
            'SYNTHETIC SIGKILL MIRROR','synthetic_test','fixture://sigkill');
        $set = new KiComEngramMirrorSet(
            $root.'/a',$root.'/b',$root.'/manifests',$web
        );
        $parent = $set->capture($store);
        $p = json_decode(
            (string)file_get_contents($root.'/manifests/'.$parent['manifest']),
            true,8,JSON_THROW_ON_ERROR
        );
        $parentBadFile = $root.'/a/'.$p['snapshot'];
        $parentGoodFile = $root.'/b/'.$p['snapshot'];
        file_put_contents($parentBadFile, 'SYNTHETIC DAMAGE', FILE_APPEND);
        $badHash = hash_file('sha256',$parentBadFile);
        $goodHash = hash_file('sha256',$parentGoodFile);
        $command = [
            PHP_BINARY,__DIR__.'/test-engram-kill-child.php',
            $root,$parent['manifest'],$parent['manifest_sha256'],$stage,
        ];
        $descriptors = [
            0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
        ];
        $pipes = [];
        $process = proc_open($command,$descriptors,$pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Synthetic subprocess could not be started');
        }
        try {
            fclose($pipes[0]);
            $marker = fgets($pipes[1]);
            if ($marker !== 'READY_TO_KILL '.$stage."\n") {
                throw new RuntimeException('Synthetic subprocess did not reach chosen kill phase');
            }
            killCheck(proc_terminate($process,9),
                'real SIGKILL delivered to subprocess at '.$stage);
            $status = proc_get_status($process);
            for ($n=0;$n<80 && $status['running'];$n++) {
                usleep(25000);
                $status=proc_get_status($process);
            }
            killCheck(!$status['running'] && $status['signaled']
                && $status['termsig'] === 9,
                'subprocess actually terminated by signal 9 at '.$stage);
        } finally {
            if (is_resource($process)) {
                @proc_terminate($process,9);
            }
            if (isset($pipes[1]) && is_resource($pipes[1])) fclose($pipes[1]);
            if (isset($pipes[2]) && is_resource($pipes[2])) fclose($pipes[2]);
            if (is_resource($process)) proc_close($process);
        }
        $anchors=[['manifest'=>$parent['manifest'],'sha256'=>$parent['manifest_sha256']]];
        $inventory=$set->inventoryUnanchoredArtifacts($anchors);
        killCheck(
            $inventory['unanchored_mirror_files']===$expected[0]
            && $inventory['unanchored_manifest_files']===$expected[1]
            && !$inventory['auto_recovery_permitted'],
            'process-killed generation leaves only reviewable orphan counts at '.$stage
        );
        if (str_starts_with($stage, 'during-')) {
            $newA = array_values(array_filter(
                glob($root.'/a/engram-*.sqlite') ?: [],
                static fn(string $file): bool => $file !== $parentBadFile
            ));
            $newB = array_values(array_filter(
                glob($root.'/b/engram-*.sqlite') ?: [],
                static fn(string $file): bool => $file !== $parentGoodFile
            ));
            $incomplete = $stage === 'during-first-copy'
                ? ($newA[0] ?? '') : ($newB[0] ?? '');
            killCheck(count($newA) === 1
                && count($newB) === ($stage === 'during-first-copy' ? 0 : 1)
                && is_file($incomplete)
                && filesize($incomplete) === 512
                && filesize($incomplete) < filesize($parentGoodFile)
                && hash_file('sha256',$incomplete) !== $p['snapshot_sha256'],
                'actual SIGKILL leaves a non-recoverable 512-byte partial mirror at '.$stage);
        }
        killCheck(
            $set->inspect($parent['manifest'],$parent['manifest_sha256'])['state']==='degraded'
            && hash_file('sha256',$parentBadFile)===$badHash
            && hash_file('sha256',$parentGoodFile)===$goodHash,
            'real process kill preserves intact and damaged original files at '.$stage
        );
        $retry=$set->rebuildNewGeneration($parent['manifest'],$parent['manifest_sha256']);
        killCheck(
            $set->inspect($retry['manifest'],$retry['manifest_sha256'])['state']==='mirrored'
            && !$retry['independent_manifest_anchor_stored'],
            'explicit retry produces fresh complete generation after SIGKILL at '.$stage
        );
        $result=$set->recover($retry['manifest'],$retry['manifest_sha256'],$root.'/recovered');
        $restored=new KiComEngramStore($root.'/recovered',$web);
        killCheck($result['restored']
            && $restored->auditHistory()['revision_count']===1
            && count($restored->search('subject-a','project','SIGKILL MIRROR'))===1,
            'post-SIGKILL retry restores valid synthetic memory at '.$stage
        );
        unset($restored,$result,$retry,$set,$store);
    } finally {
        unset($restored,$result,$retry,$set,$store);
        killClear($root);
    }
}
echo "KICOM_ENGRAM_SIGKILL_TESTS_PASSED=$killTests\n";
