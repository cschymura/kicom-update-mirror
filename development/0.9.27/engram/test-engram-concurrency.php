<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramMirrorSet.php';

$concurrencyTests = 0;
function concurrencyCheck(bool $yes, string $label): void
{
    global $concurrencyTests;
    if (!$yes) throw new RuntimeException('FAIL '.$label);
    $concurrencyTests++;
    echo 'PASS '.$label."\n";
}
function concurrencyClear(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $n) {
        if ($n !== '.' && $n !== '..') concurrencyClear($path.'/'.$n);
    }
    @rmdir($path);
}
if (PHP_OS_FAMILY !== 'Linux' || !function_exists('proc_open')
    || !function_exists('flock')) {
    throw new RuntimeException('Concurrent synthetic repair requires Linux process and flock support');
}
$root = sys_get_temp_dir().'/engram-kill-synthetic-'.bin2hex(random_bytes(12));
mkdir($root,0700);
foreach (['web','live','a','b','manifests','restored'] as $dir) {
    mkdir($root.'/'.$dir,$dir === 'web' ? 0755 : 0700);
}
$process = null;
$pipes = [];
try {
    $web=$root.'/web';
    $source=new KiComEngramStore($root.'/live',$web);
    $source->create('subject-a','project','technical',
        'SYNTHETIC EXCLUSIVE PARENT LEASE','synthetic_test','fixture://lease');
    $set=new KiComEngramMirrorSet($root.'/a',$root.'/b',$root.'/manifests',$web);
    $parent=$set->capture($source);
    $manifest=json_decode(
        (string)file_get_contents($root.'/manifests/'.$parent['manifest']),
        true,8,JSON_THROW_ON_ERROR
    );
    $parentA=$root.'/a/'.$manifest['snapshot'];
    $parentB=$root.'/b/'.$manifest['snapshot'];
    file_put_contents($parentA,'SYNTHETIC DAMAGE',FILE_APPEND);
    $oldBadHash=hash_file('sha256',$parentA);
    $oldGoodHash=hash_file('sha256',$parentB);
    $command=[
        PHP_BINARY,__DIR__.'/test-engram-kill-child.php',
        $root,$parent['manifest'],$parent['manifest_sha256'],
        'before-first-copy'
    ];
    $process=proc_open($command,
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start synthetic first repair');
    }
    fclose($pipes[0]);
    $marker=fgets($pipes[1]);
    concurrencyCheck($marker === "READY_TO_KILL before-first-copy\n",
        'first repair holds same-parent lock before copy begins');
    $denied=false;
    try {
        $set->rebuildNewGeneration($parent['manifest'],$parent['manifest_sha256']);
    } catch (RuntimeException $e) {
        $denied=$e->getMessage()==='Concurrent repair of this parent is already active';
    }
    concurrencyCheck($denied,
        'second process is rejected immediately while same-parent repair holds exclusive lock');
    $inventory=$set->inventoryUnanchoredArtifacts([[
        'manifest'=>$parent['manifest'],'sha256'=>$parent['manifest_sha256']
    ]]);
    concurrencyCheck($inventory['unanchored_mirror_files']===0
        && $inventory['unanchored_manifest_files']===0,
        'blocked contender does not write any snapshot or manifest');
    concurrencyCheck(hash_file('sha256',$parentA)===$oldBadHash
        && hash_file('sha256',$parentB)===$oldGoodHash,
        'concurrent contender does not alter either parent copy');
    concurrencyCheck(proc_terminate($process,9),
        'real SIGKILL interrupts lock-holding repair');
    $status=proc_get_status($process);
    for ($n=0;$n<80 && $status['running'];$n++) {
        usleep(25000);
        $status=proc_get_status($process);
    }
    concurrencyCheck(!$status['running'] && $status['signaled']
        && $status['termsig']===9,
        'kernel terminates lock holder and releases advisory parent lock');
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $process=null;
    $retry=$set->rebuildNewGeneration($parent['manifest'],$parent['manifest_sha256']);
    concurrencyCheck($set->inspect($retry['manifest'],$retry['manifest_sha256'])['state']==='mirrored'
        && $retry['manifest']!==$parent['manifest'],
        'next explicitly authorized repair succeeds after SIGKILL releases parent lease');
    $restored=$set->recover($retry['manifest'],$retry['manifest_sha256'],$root.'/restored');
    $db=new KiComEngramStore($root.'/restored',$web);
    concurrencyCheck($restored['restored'] && $db->auditHistory()['revision_count']===1
        && count($db->search('subject-a','project','EXCLUSIVE PARENT LEASE'))===1,
        'post-concurrency recovery verifies original synthetic memory');
    echo "KICOM_ENGRAM_CONCURRENCY_TESTS_PASSED=$concurrencyTests\n";
} finally {
    if (is_resource($process)) {
        @proc_terminate($process,9);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($process);
    }
    unset($db,$source,$set,$restored);
    concurrencyClear($root);
}
