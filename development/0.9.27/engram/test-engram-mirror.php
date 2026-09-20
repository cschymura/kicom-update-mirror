<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramMirrorSet.php';
$mirrorTests = 0;
function mirrorCheck(bool $value, string $label): void
{
    global $mirrorTests;
    if (!$value) throw new RuntimeException('FAIL ' . $label);
    $mirrorTests++;
    echo 'PASS ' . $label . "\n";
}
function mirrorReject(callable $fn, string $label): void
{
    $refused = false;
    try { $fn(); } catch (RuntimeException|InvalidArgumentException $e) { $refused = true; }
    mirrorCheck($refused, $label);
}
function mirrorClear(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $n) if ($n !== '.' && $n !== '..') mirrorClear($path.'/'.$n);
    @rmdir($path);
}
$base = sys_get_temp_dir() . '/engram-mirror-synthetic-' . bin2hex(random_bytes(12));
mkdir($base,0700);
foreach (['web','live','a','b','manifest','restore-a','restore-b','restore-c','restore-d','other'] as $item) {
    mkdir($base . '/' . $item,$item === 'web' ? 0755 : 0700);
}
try {
    $web = $base . '/web';
    $live = $base . '/live';
    $a = $base . '/a';
    $b = $base . '/b';
    $manifest = $base . '/manifest';
    $source = new KiComEngramStore($live,$web);
    $first = $source->create('subject-a','project','technical',
        'SYNTHETIC ORIGINAL MIRRORED RECORD','synthetic_test','fixture://mirror');
    $source->revise('subject-a','project',$first['id'],1,$first['revision_hash'],
        'SYNTHETIC CURRENT MIRRORED RECORD','synthetic_test','fixture://mirror');
    $raid = new KiComEngramMirrorSet($a,$b,$manifest,$web);
    $created = $raid->capture($source);
    mirrorCheck($created['mirrors_created'] === 2
        && !$created['independent_manifest_anchor_stored']
        && $created['revision_count'] === 2, 'consistent two-copy snapshot requires separate operator anchor');
    $manifestPath = $manifest . '/' . $created['manifest'];
    $bytes = (string)file_get_contents($manifestPath);
    $decoded = json_decode($bytes,true,8,JSON_THROW_ON_ERROR);
    mirrorCheck((fileperms($manifestPath) & 0077) === 0
        && hash('sha256',$bytes) === $created['manifest_sha256'],
        'manifest is a private independently hashable immutable file');
    mirrorCheck(!str_contains($bytes,'SYNTHETIC ORIGINAL')
        && !str_contains($bytes, $base)
        && array_keys($decoded) === [
            'format','generation','snapshot','snapshot_sha256','revision_count'
        ], 'manifest never contains memory bodies or absolute host paths');
    $aFile = $a . '/' . $decoded['snapshot'];
    $bFile = $b . '/' . $decoded['snapshot'];
    mirrorCheck(hash_file('sha256',$aFile) === hash_file('sha256',$bFile)
        && hash_file('sha256',$aFile) === $decoded['snapshot_sha256'],
        'both mirrors contain byte-identical consistent snapshot with committed revisions');
    $status = $raid->inspect($created['manifest'],$created['manifest_sha256']);
    mirrorCheck($status['state'] === 'mirrored' && $status['verified_mirrors'] === 2,
        'independently anchored manifest verifies two mirrors');
    mirrorReject(static fn()=> $raid->inspect($created['manifest'],str_repeat('0',64)),
        'incorrect independent manifest hash denied');
    mirrorReject(static fn()=> $raid->inspect('../'.$created['manifest'],$created['manifest_sha256']),
        'manifest path traversal denied');
    mirrorReject(static fn()=> $raid->inspect($created['manifest'], 'garbage'),
        'invalid independent manifest digest denied');
    $restored = $raid->recover($created['manifest'],$created['manifest_sha256'],$base.'/restore-a');
    $restoredStore = new KiComEngramStore($base.'/restore-a',$web);
    mirrorCheck($restored['restored'] && $restored['previous_state'] === 'mirrored'
        && $restoredStore->auditHistory()['revision_count'] === 2
        && count($restoredStore->search('subject-a','project','CURRENT MIRRORED')) === 1,
        'two-copy recovery preserves latest committed revision and historical chain');
    mirrorReject(static fn()=> $raid->recover(
        $created['manifest'],$created['manifest_sha256'],$base.'/restore-a'),
        'existing live/restored database cannot be overwritten');
    file_put_contents($aFile,'SYNTHETIC DAMAGE',FILE_APPEND);
    $degraded = $raid->inspect($created['manifest'],$created['manifest_sha256']);
    mirrorCheck($degraded['state'] === 'degraded' && $degraded['verified_mirrors'] === 1
        && $degraded['source_indexes'] === [1],
        'one damaged mirror is quarantined from selection without majority-vote guessing');
    $recovered = $raid->recover($created['manifest'],$created['manifest_sha256'],$base.'/restore-b');
    $reopened = new KiComEngramStore($base.'/restore-b',$web);
    mirrorCheck($recovered['source_mirror'] === 1
        && $recovered['previous_state'] === 'degraded'
        && count($reopened->search('subject-a','project','CURRENT MIRRORED')) === 1,
        'independently verified surviving mirror restores into a fresh directory');
    mirrorCheck(is_file($aFile) && !is_file($base.'/restore-c/engrams.sqlite'),
        'damaged mirror remains untouched for later operator review');
    file_put_contents($bFile,'SYNTHETIC SECOND DAMAGE',FILE_APPEND);
    mirrorCheck($raid->inspect($created['manifest'],$created['manifest_sha256'])['state'] === 'unrecoverable',
        'both damaged mirrors fail closed rather than being voted into validity');
    mirrorReject(static fn()=> $raid->recover(
        $created['manifest'],$created['manifest_sha256'],$base.'/restore-c'),
        'no trusted mirror means no restoration');
    $later = $raid->capture($source);
    mirrorCheck($later['manifest'] !== $created['manifest']
        && $later['manifest_sha256'] !== $created['manifest_sha256']
        && is_file($manifestPath), 'new generation preserves earlier damaged generation and manifest');
    $laterManifest = $manifest.'/'.$later['manifest'];
    file_put_contents($laterManifest,'SYNTHETIC MANIFEST TAMPER',FILE_APPEND);
    mirrorReject(static fn()=> $raid->inspect($later['manifest'],$later['manifest_sha256']),
        'tampered manifest is denied despite two intact mirror copies');
    mirrorCheck($source->auditHistory()['revision_count'] === 2,
        'mirror corruption does not modify the active source database');
    mirrorReject(static fn()=> new KiComEngramMirrorSet($a,$a,$manifest,$web),
        'two mirrors must never be the same directory');
    mirrorReject(static fn()=> new KiComEngramMirrorSet($a,$b,$a,$web),
        'manifest must never share a mirror directory');
    mirrorReject(static fn()=> new KiComEngramMirrorSet($base,$b,$manifest,$web),
        'mirror cannot target the webroot ancestor of private directories');
    mirrorReject(static fn()=> new KiComEngramMirrorSet($web,$b,$manifest,$web),
        'mirror cannot be stored under the public webroot');
    chmod($b,0755);
    mirrorReject(static fn()=> new KiComEngramMirrorSet($a,$b,$manifest,$web),
        'readable mirror directory denied');
    chmod($b,0700);
    $symlink = $base.'/symlink-a';
    symlink($a,$symlink);
    mirrorReject(static fn()=> new KiComEngramMirrorSet($symlink,$b,$manifest,$web),
        'symlink mirror directory denied');
    mirrorReject(static fn()=> $raid->recover(
        $created['manifest'],$created['manifest_sha256'],$a),
        'recovery cannot overwrite a mirror directory');
    echo "KICOM_ENGRAM_MIRROR_TESTS_PASSED=$mirrorTests\n";
} finally {
    unset($raid,$source,$restoredStore,$reopened);
    mirrorClear($base);
}
