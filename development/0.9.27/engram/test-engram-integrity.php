<?php
declare(strict_types=1);
// Synthetic-only stand-alone data integrity regression. No live memory.
$integrityTests = 0;
function integrityAssert(bool $ok, string $label): void
{
    global $integrityTests;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $integrityTests++;
    echo 'PASS ' . $label . "\n";
}
$syntheticRoot = sys_get_temp_dir() . '/engram-integrity-synthetic-' . bin2hex(random_bytes(12));
mkdir($syntheticRoot, 0700);
mkdir($syntheticRoot . '/web', 0755);
mkdir($syntheticRoot . '/private', 0700);
try {
    $store = new KiComEngramStore($syntheticRoot . '/private', $syntheticRoot . '/web');
    $original = 'SYNTHETIC_APPROVED_FIXTURE';
    $entry = $store->create('subject-a', 'project', 'technical',
        $original, 'synthetic_test', 'fixture://integrity');
    $rows = $store->search('subject-a', 'project', 'SYNTHETIC');
    integrityAssert(count($rows) === 1 && $rows[0]['body'] === $original,
        'untampered synthetic revision can be retrieved');
    integrityAssert(!array_key_exists('subject', $rows[0]) && !array_key_exists('previous_hash', $rows[0]),
        'public search projection remains unchanged after hash verification');
    $rawDb = new PDO('sqlite:' . $syntheticRoot . '/private/engrams.sqlite');
    $rawDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $rawDb->prepare('UPDATE engram_revisions SET body = ? WHERE subject = ? AND namespace = ? AND id = ?');
    $stmt->execute(['SYNTHETIC_CHANGED_FIXTURE', 'subject-a', 'project', $entry['id']]);
    integrityAssert($store->health()['quick_check'] === 'ok',
        'SQLite structural quick_check alone does not detect a modified engram body');
    $denied = false;
    try {
        $store->search('subject-a', 'project', 'SYNTHETIC');
    } catch (RuntimeException $e) {
        $denied = $e->getMessage() === 'Engram revision content integrity check failed';
    }
    integrityAssert($denied, 'tampered synthetic latest revision is never returned by search');
    $deniedRevision = false;
    try {
        $store->revise('subject-a', 'project', $entry['id'], 1, $entry['revision_hash'],
            'synthetic new content', 'synthetic_test', 'fixture://integrity');
    } catch (RuntimeException $e) {
        $deniedRevision = true;
    }
    integrityAssert($deniedRevision, 'tampered latest revision cannot be revised');
    echo "KICOM_ENGRAM_INTEGRITY_TESTS_PASSED=$integrityTests\n";
} finally {
    unset($stmt, $rawDb, $store);
    foreach (glob($syntheticRoot . '/private/*') ?: [] as $f) {
        if (is_file($f) && !is_link($f)) { @unlink($f); }
    }
    @rmdir($syntheticRoot . '/private');
    @rmdir($syntheticRoot . '/web');
    @rmdir($syntheticRoot);
}
