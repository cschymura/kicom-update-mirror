<?php
declare(strict_types=1);
// Included INSIDE test-engram-mirror.php's synthetic fixture and cleanup block.
// Never process real user memories or mount a live private host.
$fresh = $raid->capture($source);
$freshJson = json_decode(
    (string)file_get_contents($manifest.'/'.$fresh['manifest']),
    true, 8, JSON_THROW_ON_ERROR
);
$freshFileA = $a.'/'.$freshJson['snapshot'];
$freshFileB = $b.'/'.$freshJson['snapshot'];
mirrorReject(static fn()=> $raid->rebuildNewGeneration(
    $fresh['manifest'], $fresh['manifest_sha256']),
    'rebuild rejects intact parent: repair only an independently anchored degraded generation');
mirrorReject(static fn()=> $raid->rebuildNewGeneration(
    $fresh['manifest'], str_repeat('f', 64)),
    'rebuild refuses untrusted or changed parent manifest digest');
$parentAContents = (string)file_get_contents($freshFileA);
$parentBContents = (string)file_get_contents($freshFileB);
file_put_contents($freshFileA, 'SYNTHETIC DAMAGED PARENT COPY', FILE_APPEND);
$parentADigest = hash_file('sha256', $freshFileA);
$parentBDigest = hash_file('sha256', $freshFileB);
mirrorCheck($raid->inspect($fresh['manifest'], $fresh['manifest_sha256'])['source_indexes'] === [1],
    'repair fixture starts from one anchored intact parent copy');
$rebuild = $raid->rebuildNewGeneration($fresh['manifest'], $fresh['manifest_sha256']);
mirrorCheck($rebuild['mirrors_created'] === 2 && $rebuild['previous_state'] === 'degraded'
    && !$rebuild['independent_manifest_anchor_stored']
    && $rebuild['manifest'] !== $fresh['manifest']
    && $rebuild['manifest_sha256'] !== $fresh['manifest_sha256'],
    'degraded parent produces new private immutable generation requiring NEW external anchor');
$rebuildPath = $manifest.'/'.$rebuild['manifest'];
$rebuildBytes = (string)file_get_contents($rebuildPath);
$rebuildManifest = json_decode($rebuildBytes, true, 8, JSON_THROW_ON_ERROR);
mirrorCheck($rebuildManifest['format'] === 'engram-mirror-v2'
    && $rebuildManifest['parent_manifest'] === $fresh['manifest']
    && $rebuildManifest['parent_manifest_sha256'] === $fresh['manifest_sha256']
    && $rebuildManifest['parent_source_mirror'] === 1
    && $rebuildManifest['snapshot_sha256'] === $freshJson['snapshot_sha256']
    && $rebuildManifest['revision_count'] === 2,
    'new manifest records independently anchored parent, source index and revision provenance');
mirrorCheck(!str_contains($rebuildBytes, 'SYNTHETIC CURRENT')
    && !str_contains($rebuildBytes, $base)
    && (fileperms($rebuildPath) & 0077) === 0,
    'new manifest contains no memory content or private filesystem path and stays mode 0600');
$newFileA = $a.'/'.$rebuildManifest['snapshot'];
$newFileB = $b.'/'.$rebuildManifest['snapshot'];
mirrorCheck(is_file($newFileA) && is_file($newFileB)
    && hash_file('sha256', $newFileA) === $freshJson['snapshot_sha256']
    && hash_file('sha256', $newFileB) === $freshJson['snapshot_sha256']
    && $newFileA !== $freshFileA && $newFileB !== $freshFileB,
    'fresh two-copy repair uses NEW filenames but exactly the same anchored snapshot bytes');
mirrorCheck(hash_file('sha256', $freshFileA) === $parentADigest
    && hash_file('sha256', $freshFileB) === $parentBDigest
    && (string)file_get_contents($freshFileB) === $parentBContents
    && (string)file_get_contents($freshFileA) !== $parentAContents,
    'repair does not alter damaged or surviving parent copies');
mirrorCheck($raid->inspect($fresh['manifest'], $fresh['manifest_sha256'])['state'] === 'degraded'
    && $raid->inspect($rebuild['manifest'], $rebuild['manifest_sha256'])['state'] === 'mirrored',
    'both historical degraded parent and new intact generation remain independently inspectable');
mirrorReject(static fn()=> $raid->inspect($rebuild['manifest'], $fresh['manifest_sha256']),
    'repaired generation is not trusted by parent manifest anchor alone');
$recoveredNew = $raid->recover(
    $rebuild['manifest'], $rebuild['manifest_sha256'], $base.'/restore-c'
);
$recoveredNewStore = new KiComEngramStore($base.'/restore-c', $web);
mirrorCheck($recoveredNew['restored']
    && $recoveredNewStore->auditHistory()['revision_count'] === 2
    && count($recoveredNewStore->search('subject-a', 'project', 'CURRENT MIRRORED')) === 1,
    'repaired generation restores complete and revision-audited SQLite history');
file_put_contents($newFileA, 'SYNTHETIC CHILD DAMAGE', FILE_APPEND);
$secondRebuild = $raid->rebuildNewGeneration(
    $rebuild['manifest'], $rebuild['manifest_sha256']
);
$grandchild = json_decode(
    (string)file_get_contents($manifest.'/'.$secondRebuild['manifest']),
    true, 8, JSON_THROW_ON_ERROR
);
mirrorCheck($grandchild['format'] === 'engram-mirror-v2'
    && $grandchild['parent_manifest'] === $rebuild['manifest']
    && $grandchild['parent_manifest_sha256'] === $rebuild['manifest_sha256']
    && $grandchild['parent_source_mirror'] === 1
    && $raid->inspect($secondRebuild['manifest'], $secondRebuild['manifest_sha256'])['state'] === 'mirrored',
    'second repair carries verifiable immediate-parent provenance without overwriting old generations');
file_put_contents($newFileB, 'SYNTHETIC SECOND CHILD DAMAGE', FILE_APPEND);
mirrorReject(static fn()=> $raid->rebuildNewGeneration(
    $rebuild['manifest'], $rebuild['manifest_sha256']),
    'repair refuses parent with zero independently verified snapshot copies');
$orphanName = 'engram-'.bin2hex(random_bytes(16)).'.sqlite';
file_put_contents($a.'/'.$orphanName, 'SYNTHETIC INCOMPLETE ORPHAN');
$orphanManifest = 'mirror-'.bin2hex(random_bytes(16)).'.json';
mirrorReject(static fn()=> $raid->inspect($orphanManifest, str_repeat('a', 64)),
    'crash-like uncommitted orphan snapshot without anchored manifest is never selected');
mirrorCheck(is_file($a.'/'.$orphanName)
    && is_file($freshFileA) && is_file($freshFileB)
    && is_file($manifest.'/'.$fresh['manifest']),
    'orphan and historical parent are left intact for operator quarantine or retention review');
mirrorCheck($source->auditHistory()['revision_count'] === 2,
    'repair of mirror generations never changes the running synthetic source database');
